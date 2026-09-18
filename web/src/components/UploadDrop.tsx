import { useEffect, useRef, useState } from "react";
import {
  SUPPORTED_EXTENSIONS,
  fromFileList,
  uploadEvidence,
  walkTransfer,
  type Picked,
  type UploadState,
} from "../lib/upload";
import { Panel } from "./ui";

/** One row of the drop's progress. Keyed by the path it was picked under. */
interface Row {
  id: number;
  path: string;
  state: UploadState;
  error?: string;
}

/**
 * The evidence vault's front door (spec §7).
 *
 * The pipeline itself moved to `lib/upload` when jobs started taking drops of
 * their own — the binary still goes straight from the browser to private
 * storage on a signed URL, and the API still only learns the key. What is left
 * here is the vault's version of the gesture: the two access decisions that
 * apply to a whole drop, and a row per file so a folder that half-worked says
 * which half.
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
  const [rows, setRows] = useState<Row[]>([]);
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

  async function send(picked: Picked[]) {
    if (picked.length === 0) return;

    const ids = new Map(picked.map((p) => [p.path, nextId.current++]));

    setRows((all) => [
      ...all,
      ...picked.map((p) => ({ id: ids.get(p.path)!, path: p.path, state: "signing" as const })),
    ]);

    function mark(path: string, state: UploadState, error?: string) {
      const id = ids.get(path);
      setRows((all) => all.map((r) => (r.id === id ? { ...r, state, error } : r)));

      // Clear the finished row after a moment so the panel does not grow
      // indefinitely during a bulk drop.
      if (state === "done") {
        setTimeout(() => setRows((all) => all.filter((r) => r.id !== id)), 2500);
      }
    }

    try {
      await uploadEvidence({
        circleId,
        picked,
        // Agent access is opt-in per item and never inherited (spec §10).
        agentRead: agentRead && !scoped,
        restrictedToPartyId: scoped ? party!.id : null,
        onState: mark,
      });
      onUploaded();
    } catch (e) {
      const message = e instanceof Error ? e.message : "Upload failed.";
      setRows((all) =>
        all.map((r) =>
          ids.has(r.path) && r.state !== "done" && r.state !== "failed"
            ? { ...r, state: "failed", error: message }
            : r,
        ),
      );
    }
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
                ? "The Steward reads for the whole Circle, so it isn't given one party's material."
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
          void walkTransfer(e.dataTransfer).then(send);
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
          Originals are kept exactly as they arrive and hashed on the way in.
          Replacing a file later adds a new version rather than overwriting the
          old one.
        </p>
        {scoped && (
          <p className="mx-auto mt-2 max-w-md text-[0.8125rem] leading-relaxed text-[var(--signal)]">
            Visible to {party!.label} and to the convener, who gets the packet.
            No other party in this Circle will even see it listed.
          </p>
        )}
        {/*
          The supported-type list is reference material, not something anyone
          reads before their first drop — so it sits below the instruction at
          the smallest useful size rather than competing with it.
        */}
        <p className="mx-auto mt-3 max-w-lg text-xs leading-relaxed text-[var(--ink-faint)]">
          {SUPPORTED_EXTENSIONS.join(" · ")}
        </p>
      </div>

      <input
        ref={fileInput}
        type="file"
        multiple
        hidden
        onChange={(e) => {
          void send(fromFileList(e.target.files));
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
          void send(fromFileList(e.target.files));
          e.target.value = "";
        }}
      />

      {rows.length > 0 && (
        <ul>
          {rows.map((r) => (
            <li
              key={r.id}
              className="flex items-baseline justify-between gap-3 border-t border-[var(--rule)] px-5 py-2.5"
            >
              <span className="truncate text-[0.8125rem]">{r.path}</span>
              <span
                className={`shrink-0 text-xs ${
                  r.state === "failed"
                    ? "text-[var(--signal)]"
                    : r.state === "done"
                      ? "text-[var(--settled)]"
                      : "text-[var(--ink-faint)]"
                }`}
                title={r.error}
              >
                {r.state === "signing" && "Getting an upload link…"}
                {r.state === "uploading" && "Uploading…"}
                {r.state === "registering" && "Recording where it came from…"}
                {r.state === "done" && "Stored · verifying"}
                {r.state === "failed" && (r.error ?? "Failed")}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}
