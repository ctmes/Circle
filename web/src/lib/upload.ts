import { api } from "./api";

/**
 * The client half of the ingest pipeline (spec §7), on its own.
 *
 * It was inside the evidence drop zone, which was the only place a file could
 * enter the product. A job now takes a drop too, and the pipeline is not a
 * detail either screen should own: the binary goes straight from the browser to
 * private object storage on a short-lived signed URL, the API only ever learns
 * the key, and the server's hash is therefore computed on what actually landed
 * rather than on what the browser said it sent. Two copies of that would become
 * two answers about provenance within a release.
 *
 * What the callers keep is what they draw. This reports per-file state through
 * a callback and returns what the server made of the batch; the vault renders
 * that as a list of rows, the job as a line under its file list.
 */

/** A file the person dropped, with the folder shape they dropped it in. */
export interface Picked {
  file: File;
  path: string;
}

/** Where one file has got to. Mirrors the round trips, because they can each fail. */
export type UploadState = "signing" | "uploading" | "registering" | "done" | "failed";

export const SUPPORTED_EXTENSIONS = [
  "pdf", "docx", "txt", "md", "csv", "xlsx",
  "jpg", "jpeg", "png", "webp", "heic",
  "mp4", "mov", "webm", "mp3", "wav", "m4a", "ogg",
  "zip", "pptx", "eml",
];

/**
 * Walk what was dropped, following folders.
 *
 * A tender pack is a folder, and `dataTransfer.files` flattens it to nothing
 * when a directory is dropped. `webkitGetAsEntry` is the only way to read the
 * tree, and it has to be called synchronously during the drop event — the
 * entries are invalid by the time an await resolves, which is why the items are
 * collected first and walked afterwards.
 */
export async function walkTransfer(transfer: DataTransfer): Promise<Picked[]> {
  const entries: FileSystemEntry[] = [];

  for (const item of Array.from(transfer.items)) {
    const entry = item.webkitGetAsEntry?.();
    if (entry) entries.push(entry);
  }

  if (entries.length === 0) {
    return Array.from(transfer.files).map((file) => ({ file, path: file.name }));
  }

  const picked: Picked[] = [];

  const readEntry = (entry: FileSystemEntry, prefix: string): Promise<void> =>
    new Promise((resolve) => {
      if (entry.isFile) {
        (entry as FileSystemFileEntry).file(
          (file) => {
            picked.push({ file, path: prefix + file.name });
            resolve();
          },
          () => resolve(),
        );
        return;
      }

      const reader = (entry as FileSystemDirectoryEntry).createReader();
      const all: FileSystemEntry[] = [];

      // readEntries returns at most 100 at a time and signals the end with an
      // empty batch, so a folder of 140 needs the loop.
      const drain = () =>
        reader.readEntries(
          async (batch) => {
            if (batch.length === 0) {
              await Promise.all(all.map((e) => readEntry(e, prefix + entry.name + "/")));
              resolve();
              return;
            }
            all.push(...batch);
            drain();
          },
          () => resolve(),
        );

      drain();
    });

  await Promise.all(entries.map((e) => readEntry(e, "")));

  return picked;
}

/** What a file input gives, in the same shape. */
export function fromFileList(files: FileList | null): Picked[] {
  if (!files) return [];

  return Array.from(files).map((file) => ({
    file,
    // webkitRelativePath is set when a directory was chosen, empty otherwise.
    path: file.webkitRelativePath || file.name,
  }));
}

/** Run promises a few at a time, so a folder of 140 does not open 140 sockets. */
async function inBatches<T>(items: T[], size: number, run: (item: T) => Promise<void>) {
  for (let i = 0; i < items.length; i += size) {
    await Promise.all(items.slice(i, i + size).map(run));
  }
}

export interface UploadOptions {
  circleId: string;
  picked: Picked[];
  /** Opt-in per batch and never inherited (spec §10). */
  agentRead?: boolean;
  /** Null is the default and means the whole Circle. */
  restrictedToPartyId?: string | null;
  /**
   * The node of the plan this drop landed on.
   *
   * Sent with the registration rather than attached in a second call: a folder
   * that uploaded and then failed to attach would leave 140 files in the vault
   * and nothing on the job the person was looking at.
   */
  goalId?: string | null;
  /** Called as each file moves, keyed by the path it was picked under. */
  onState?: (path: string, state: UploadState, error?: string) => void;
}

export interface UploadResult {
  /** Items the server registered, in its own presentation. */
  created: Array<{ id: string; name: string }>;
  createdCount: number;
  rejected: Array<{ filename: string; reason: string }>;
}

/**
 * Sign, upload and register a whole drop in two API calls.
 *
 * One file or a hundred and forty takes the same shape, because the common
 * case in this industry is a folder and asking somebody to add a tender pack
 * one file at a time is asking them to do the filing twice. Each file still
 * reports its own state, so a drop that half works says which half.
 */
export async function uploadEvidence({
  circleId,
  picked,
  agentRead = false,
  restrictedToPartyId = null,
  goalId = null,
  onState = () => {},
}: UploadOptions): Promise<UploadResult> {
  const empty: UploadResult = { created: [], createdCount: 0, rejected: [] };

  if (picked.length === 0) return empty;

  for (const p of picked) onState(p.path, "signing");

  const signed = await api.post<{
    data: {
      signed: {
        filename: string;
        relative_path: string | null;
        storage_key: string;
        upload: { url: string; headers: Record<string, string> };
      }[];
      rejected: { filename: string; reason: string }[];
    };
  }>(`/circles/${circleId}/uploads/sign-batch`, {
    files: picked.map((p) => ({
      filename: p.file.name,
      content_type: p.file.type || null,
      byte_size: p.file.size || null,
      relative_path: p.path,
    })),
  });

  // Anything the server would not sign is settled here rather than left
  // spinning. A folder of 140 contains a .DS_Store, and that is not a reason to
  // fail the other 139.
  const refusedUpFront = new Map(signed.data.rejected.map((r) => [r.filename, r.reason]));
  const slots = signed.data.signed;

  const remaining = picked.filter((p) => {
    const reason = refusedUpFront.get(p.file.name);
    if (reason === undefined) return true;
    onState(p.path, "failed", reason === "unsupported_type" ? "Unsupported file type." : reason);
    return false;
  });

  const slotFor = (p: Picked) =>
    slots.find(
      (s) => s.relative_path === p.path || (!s.relative_path && s.filename === p.file.name),
    );

  /** Paths that actually landed in storage, by the key they landed under. */
  const stored = new Map<string, Picked>();

  await inBatches(remaining, 6, async (p) => {
    const slot = slotFor(p);

    if (!slot) {
      onState(p.path, "failed", "No upload slot was returned.");
      return;
    }

    onState(p.path, "uploading");

    const put = await fetch(slot.upload.url, {
      method: "PUT",
      headers: slot.upload.headers,
      body: p.file,
    });

    if (!put.ok) {
      onState(p.path, "failed", `Storage refused the upload (${put.status}).`);
      return;
    }

    stored.set(slot.storage_key, p);
    onState(p.path, "registering");
  });

  if (stored.size === 0) {
    return { ...empty, rejected: signed.data.rejected };
  }

  const result = await api.post<{
    data: {
      created: Array<{ id: string; name: string }>;
      created_count: number;
      rejected: { filename: string; reason: string }[];
    };
  }>(`/circles/${circleId}/evidence/batch`, {
    items: [...stored].map(([storage_key, p]) => ({
      storage_key,
      filename: p.file.name,
      relative_path: p.path,
      content_type: p.file.type || null,
    })),
    agent_read: agentRead,
    restricted_to_party_id: restrictedToPartyId,
    goal_id: goalId,
  });

  const refusedOnRegister = new Map(result.data.rejected.map((r) => [r.filename, r.reason]));

  for (const p of stored.values()) {
    const reason = refusedOnRegister.get(p.file.name);
    onState(p.path, reason ? "failed" : "done", reason);
  }

  return {
    created: result.data.created ?? [],
    createdCount: result.data.created_count,
    rejected: [...signed.data.rejected, ...result.data.rejected],
  };
}
