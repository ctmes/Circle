import { useRef, useState } from "react";
import { api } from "../lib/api";
import { Panel } from "./ui";

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
}: {
  circleId: string;
  onUploaded: () => void;
}) {
  const [jobs, setJobs] = useState<Job[]>([]);
  const [dragging, setDragging] = useState(false);
  const [agentRead, setAgentRead] = useState(false);
  const nextId = useRef(0);
  const fileInput = useRef<HTMLInputElement>(null);

  function update(id: number, patch: Partial<Job>) {
    setJobs((all) => all.map((j) => (j.id === id ? { ...j, ...patch } : j)));
  }

  async function send(file: File) {
    const id = nextId.current++;
    setJobs((all) => [...all, { id, name: file.name, state: "signing" }]);

    try {
      const signed = await api.post<{
        data: { url: string; headers: Record<string, string>; key: string };
      }>(`/circles/${circleId}/uploads/sign`, { filename: file.name });

      update(id, { state: "uploading" });

      const put = await fetch(signed.data.url, {
        method: "PUT",
        headers: signed.data.headers,
        body: file,
      });

      if (!put.ok) throw new Error(`Storage refused the upload (${put.status}).`);

      update(id, { state: "registering" });

      await api.post(`/circles/${circleId}/evidence`, {
        storage_key: signed.data.key,
        filename: file.name,
        // Agent access is opt-in per item and never inherited (spec §10).
        agent_read: agentRead,
      });

      update(id, { state: "done" });
      onUploaded();

      // Clear the finished row after a moment so the panel does not grow
      // indefinitely during a bulk drop.
      setTimeout(() => setJobs((all) => all.filter((j) => j.id !== id)), 2500);
    } catch (e) {
      update(id, {
        state: "failed",
        error: e instanceof Error ? e.message : "Upload failed.",
      });
    }
  }

  function accept(files: FileList | null) {
    if (!files) return;
    for (const file of Array.from(files)) void send(file);
  }

  return (
    <Panel
      title="Add evidence"
      meta={
        <label className="flex cursor-pointer items-center gap-1.5 select-none">
          <input
            type="checkbox"
            checked={agentRead}
            onChange={(e) => setAgentRead(e.target.checked)}
            className="accent-[var(--derived)]"
          />
          <span className="label !text-[var(--derived)]">readable by the Steward</span>
        </label>
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
          accept(e.dataTransfer.files);
        }}
        onClick={() => fileInput.current?.click()}
        role="button"
        tabIndex={0}
        onKeyDown={(e) => {
          if (e.key === "Enter" || e.key === " ") fileInput.current?.click();
        }}
        className={`m-3 cursor-pointer border-2 border-dashed px-4 py-7 text-center transition-colors ${
          dragging
            ? "border-[var(--ink)] bg-[var(--paper-sunk)]"
            : "border-[var(--rule-strong)] hover:border-[var(--ink-muted)]"
        }`}
      >
        <p className="display text-sm font-600">
          Drop files, or click to choose
        </p>
        <p className="mt-1.5 mono text-[0.6875rem] leading-relaxed text-[var(--ink-faint)]">
          {SUPPORTED.join(" · ")}
        </p>
        <p className="mt-2 text-xs italic text-[var(--ink-muted)]">
          The original is preserved unchanged and hashed on arrival. Replacing a
          file later creates a new version rather than overwriting this one.
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

      {jobs.length > 0 && (
        <ul className="border-t border-[var(--rule)]">
          {jobs.map((j) => (
            <li
              key={j.id}
              className="flex items-baseline justify-between gap-3 border-b border-[var(--rule)] px-4 py-2 last:border-0"
            >
              <span className="mono truncate text-xs">{j.name}</span>
              <span
                className={`mono shrink-0 text-[0.6875rem] ${
                  j.state === "failed"
                    ? "text-[var(--signal)]"
                    : j.state === "done"
                      ? "text-[var(--settled)]"
                      : "text-[var(--ink-faint)]"
                }`}
                title={j.error}
              >
                {j.state === "signing" && "requesting upload URL…"}
                {j.state === "uploading" && "uploading to vault…"}
                {j.state === "registering" && "recording provenance…"}
                {j.state === "done" && "stored · verifying"}
                {j.state === "failed" && (j.error ?? "failed")}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}
