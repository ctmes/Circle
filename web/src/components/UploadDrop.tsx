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
            className="!accent-[var(--derived)]"
          />
          <span className="text-[0.8125rem] font-[560] text-[var(--derived)]">
            Readable by the Steward
          </span>
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
          {dragging ? "Release to add" : "Drop files, or click to choose"}
        </p>
        <p className="mx-auto mt-2 max-w-md text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
          The original is preserved unchanged and hashed on arrival. Replacing a
          file later creates a new version rather than overwriting this one.
        </p>
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
