import { useEffect, useRef, useState } from "react";
import { api } from "../lib/api";
import { Panel } from "./ui";

/** A file the person dropped, with the folder shape they dropped it in. */
interface Picked {
  file: File;
  path: string;
}

/**
 * Walk what was dropped, following folders.
 *
 * A tender pack is a folder, and `dataTransfer.files` flattens it to nothing
 * when a directory is dropped. `webkitGetAsEntry` is the only way to read the
 * tree, and it has to be called synchronously during the drop event — the
 * entries are invalid by the time an await resolves, which is why the items are
 * collected first and walked afterwards.
 */
async function walk(transfer: DataTransfer): Promise<Picked[]> {
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

/** Run promises a few at a time, so a folder of 140 does not open 140 sockets. */
async function inBatches<T>(items: T[], size: number, run: (item: T) => Promise<void>) {
  for (let i = 0; i < items.length; i += size) {
    await Promise.all(items.slice(i, i + size).map(run));
  }
}

const SUPPORTED = [
  "pdf", "docx", "txt", "md", "csv", "xlsx",
  "jpg", "jpeg", "png", "webp", "heic",
  "mp4", "mov", "webm", "mp3", "wav", "m4a", "ogg",
  "zip", "pptx", "eml",
];

interface Job {
  id: number;
  name: string;
  state: "signing" | "uploading" | "registering" | "done" | "failed";
  error?: string;
}

/**
 * The ingest pipeline's client half (spec §7).
 *
 * The binary goes straight from the browser to private object storage using a
 * short-lived signed URL; the API only ever learns the key. That keeps large
 * site videos off the application server entirely, and means the server's later
 * hash is computed on what actually landed rather than on what we were told.
 */
export function UploadDrop({
  circleId,
  onUploaded,
  party,
}: {
  circleId: string;
  onUploaded: () => void;
  /** The company the uploader sits in, where the Circle spans several. */
  party?: { id: string; label: string } | null;
}) {
  const [jobs, setJobs] = useState<Job[]>([]);
  const [dragging, setDragging] = useState(false);
  const [agentRead, setAgentRead] = useState(false);
  const [restricted, setRestricted] = useState(false);

  // An item scoped to one party is refused to the Steward outright: it is bound
  // to the Circle, not to a company, so letting it read one party's material
  // would put that material into answers everyone reads. Saying so here beats
  // letting someone tick both and discover the contradiction from a run that
  // quietly skipped their file.
  const scoped = restricted && !!party;
  const nextId = useRef(0);
  const fileInput = useRef<HTMLInputElement>(null);
  const folderInput = useRef<HTMLInputElement>(null);

  useEffect(() => {
    folderInput.current?.setAttribute("webkitdirectory", "");
  }, []);

  function update(id: number, patch: Partial<Job>) {
    setJobs((all) => all.map((j) => (j.id === id ? { ...j, ...patch } : j)));
  }

  /**
   * Sign, upload and register a whole drop in two API calls.
   *
   * One file or a hundred and forty takes the same shape, because the common
   * case in this industry is a folder and asking somebody to add a tender pack
   * one file at a time is asking them to do the filing twice. Each file still
   * gets its own row, so a drop that half works says which half.
   */
  async function send(picked: Picked[]) {
    if (picked.length === 0) return;

    const rows = picked.map((p) => ({ id: nextId.current++, picked: p }));

    setJobs((all) => [
      ...all,
      ...rows.map(({ id, picked: p }) => ({ id, name: p.path, state: "signing" as const })),
    ]);

    const byKey = new Map<string, number>();

    try {
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
      // spinning. A folder of 140 contains a .DS_Store, and that is not a
      // reason to fail the other 139.
      const rejected = new Map(signed.data.rejected.map((r) => [r.filename, r.reason]));
      const remaining = rows.filter(({ picked: p }) => {
        const reason = rejected.get(p.file.name);
        if (reason) {
          update(rows.find((r) => r.picked === p)!.id, {
            state: "failed",
            error: reason === "unsupported_type" ? "Unsupported file type." : reason,
          });
          return false;
        }
        return true;
      });

      const slots = signed.data.signed;

      await inBatches(remaining, 6, async ({ id, picked: p }) => {
        const slot = slots.find(
          (s) => s.relative_path === p.path || (!s.relative_path && s.filename === p.file.name),
        );

        if (!slot) {
          update(id, { state: "failed", error: "No upload slot was returned." });
          return;
        }

        byKey.set(slot.storage_key, id);
        update(id, { state: "uploading" });

        const put = await fetch(slot.upload.url, {
          method: "PUT",
          headers: slot.upload.headers,
          body: p.file,
        });

        if (!put.ok) {
          byKey.delete(slot.storage_key);
          update(id, { state: "failed", error: `Storage refused the upload (${put.status}).` });
          return;
        }

        update(id, { state: "registering" });
      });

      const uploaded = remaining
        .map(({ picked: p }) => {
          const slot = slots.find(
            (s) => s.relative_path === p.path || (!s.relative_path && s.filename === p.file.name),
          );
          return slot && byKey.has(slot.storage_key)
            ? {
                storage_key: slot.storage_key,
                filename: p.file.name,
                relative_path: p.path,
                content_type: p.file.type || null,
              }
            : null;
        })
        .filter((x): x is NonNullable<typeof x> => x !== null);

      if (uploaded.length === 0) return;

      const result = await api.post<{
        data: { created_count: number; rejected: { filename: string; reason: string }[] };
      }>(`/circles/${circleId}/evidence/batch`, {
        items: uploaded,
        // Agent access is opt-in per item and never inherited (spec §10).
        agent_read: agentRead && !scoped,
        // Null is the default and means the whole Circle.
        restricted_to_party_id: scoped ? party!.id : null,
      });

      const refused = new Map(result.data.rejected.map((r) => [r.filename, r.reason]));

      for (const [key, id] of byKey) {
        const entry = uploaded.find((u) => u.storage_key === key);
        const reason = entry ? refused.get(entry.filename) : undefined;

        if (reason) {
          update(id, { state: "failed", error: reason });
        } else {
          update(id, { state: "done" });
          // Clear the finished row after a moment so the panel does not grow
          // indefinitely during a bulk drop.
          setTimeout(() => setJobs((all) => all.filter((j) => j.id !== id)), 2500);
        }
      }

      onUploaded();
    } catch (e) {
      const message = e instanceof Error ? e.message : "Upload failed.";
      for (const { id } of rows) {
        setJobs((all) =>
          all.map((j) =>
            j.id === id && j.state !== "done" && j.state !== "failed"
              ? { ...j, state: "failed", error: message }
              : j,
          ),
        );
      }
    }
  }

  function accept(files: FileList | null) {
    if (!files) return;
    void send(
      Array.from(files).map((file) => ({
        // webkitRelativePath is set when a directory was chosen, empty otherwise.
        file,
        path: file.webkitRelativePath || file.name,
      })),
    );
  }

  return (
    <Panel
      title="Add evidence"
      meta={
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
          {party && (
            <label className="flex cursor-pointer items-center gap-1.5 select-none">
              <input
                type="checkbox"
                checked={restricted}
                onChange={(e) => setRestricted(e.target.checked)}
                className="!accent-[var(--signal)]"
              />
              <span className="text-[0.8125rem] font-[560] text-[var(--signal)]">
                {party.label} only
              </span>
            </label>
          )}
          <label
            className={`flex items-center gap-1.5 select-none ${
              scoped ? "cursor-not-allowed opacity-45" : "cursor-pointer"
            }`}
            title={
              scoped
                ? "The Steward reads for the whole Circle, so it is not given one party's material."
                : undefined
            }
          >
            <input
              type="checkbox"
              checked={agentRead && !scoped}
              disabled={scoped}
              onChange={(e) => setAgentRead(e.target.checked)}
              className="!accent-[var(--derived)]"
            />
            <span className="text-[0.8125rem] font-[560] text-[var(--derived)]">
              Readable by the Steward
            </span>
          </label>
        </div>
      }
    >
      <div
        onDragOver={(e) => {
          e.preventDefault();
          setDragging(true);
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={(e) => {
          e.preventDefault();
          setDragging(false);
          void walk(e.dataTransfer).then(send);
        }}
        onClick={() => fileInput.current?.click()}
        role="button"
        tabIndex={0}
        onKeyDown={(e) => {
          if (e.key === "Enter" || e.key === " ") fileInput.current?.click();
        }}
        className={`mx-5 mb-5 cursor-pointer rounded-[var(--r-card)] px-5 py-9 text-center outline-2 outline-dashed -outline-offset-2 transition-all duration-200 ${
          dragging
            ? "scale-[1.01] bg-[var(--accent-soft)] outline-[var(--accent)]"
            : "bg-[var(--paper-inset)] outline-[var(--rule-strong)] hover:outline-[var(--ink-faint)]"
        }`}
      >
        <svg
          width="26"
          height="26"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.6"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
          className={`mx-auto mb-2.5 transition-colors ${
            dragging ? "text-[var(--accent)]" : "text-[var(--ink-faint)]"
          }`}
        >
          <path d="M12 16V4m0 0 4 4m-4-4L8 8" />
          <path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" />
        </svg>

        <p className="display text-[0.9375rem] font-[600]">
          {dragging ? "Release to add" : "Drop files or a folder, or click to choose"}
        </p>
        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation();
            folderInput.current?.click();
          }}
          className="mt-2 text-[0.8125rem] font-[560] text-[var(--accent)] underline underline-offset-2"
        >
          Choose a folder instead
        </button>
        <p className="mx-auto mt-2 max-w-md text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
          The original is preserved unchanged and hashed on arrival. Replacing a
          file later creates a new version rather than overwriting this one.
        </p>
        {scoped && (
          <p className="mx-auto mt-2 max-w-md text-[0.8125rem] leading-relaxed text-[var(--signal)]">
            Visible to {party!.label} and to the convener, who receives the
            packet. No other party in this Circle will see it listed.
          </p>
        )}
        {/*
          The supported-type list is reference material, not something anyone
          reads before their first drop — so it sits below the instruction at
          the smallest useful size rather than competing with it.
        */}
        <p className="mx-auto mt-3 max-w-lg text-xs leading-relaxed text-[var(--ink-faint)]">
          {SUPPORTED.join(" · ")}
        </p>
      </div>

      <input
        ref={fileInput}
        type="file"
        multiple
        hidden
        onChange={(e) => {
          accept(e.target.files);
          e.target.value = "";
        }}
      />

      {/*
        Directory picking is set on the element rather than written as a JSX
        prop: `webkitdirectory` is not in the HTML attribute types, and casting
        the whole input to `any` to say one word would hide every other typo on
        it.
      */}
      <input
        ref={folderInput}
        type="file"
        multiple
        hidden
        onChange={(e) => {
          accept(e.target.files);
          e.target.value = "";
        }}
      />

      {jobs.length > 0 && (
        <ul>
          {jobs.map((j) => (
            <li
              key={j.id}
              className="flex items-baseline justify-between gap-3 border-t border-[var(--rule)] px-5 py-2.5"
            >
              <span className="truncate text-[0.8125rem]">{j.name}</span>
              <span
                className={`shrink-0 text-xs ${
                  j.state === "failed"
                    ? "text-[var(--signal)]"
                    : j.state === "done"
                      ? "text-[var(--settled)]"
                      : "text-[var(--ink-faint)]"
                }`}
                title={j.error}
              >
                {j.state === "signing" && "Requesting upload URL…"}
                {j.state === "uploading" && "Uploading to vault…"}
                {j.state === "registering" && "Recording provenance…"}
                {j.state === "done" && "Stored · verifying"}
                {j.state === "failed" && (j.error ?? "Failed")}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}
