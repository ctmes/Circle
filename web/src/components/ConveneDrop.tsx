import { useRef, useState } from "react";
import {
  CONVENE_EXTENSIONS,
  CONVENE_MAX_FILES,
  conveneFromDocuments,
  conveneRefusal,
  type ConveneStage,
} from "../lib/convening";
import { Button, ErrorNote, Field, Panel, inputClass } from "./ui";

/**
 * Opening a Circle by dropping the contract in (spec §23).
 *
 * The other way to open a Circle is a form: name it, say what it is for, pick a
 * closing date, then build the plan a goal at a time. That form is still there
 * and is still the right thing when there is no document — but most consequential
 * work starts from a signed piece of paper that already contains the answers,
 * and retyping it is both tedious and where the copy first diverges from the
 * contract.
 *
 * One drop opens the Circle, files the document as evidence, reads it, and
 * writes the whole thing in — mission statement, parties, plan, dated
 * deliverables, and the questions the document leaves open. You land in a
 * working Circle, not a form and not a review queue. A contract whose schedules
 * came as separate files is dropped as one set and read as one engagement.
 *
 * What it does not do is hide where any of that came from. The reading is kept
 * as a derived artifact and sits behind "Convening" in the Circle's own menu,
 * marking every line stated or inferred, so checking the plan against the
 * contract is one click rather than an archaeology exercise.
 */
function stageText(stage: ConveneStage, count: number): string {
  const one = count === 1;

  return {
    opening: "Opening the Circle…",
    signing: "Preparing the upload…",
    uploading: one ? "Uploading the document…" : `Uploading ${count} documents…`,
    registering: one ? "Filing it as evidence…" : "Filing them as evidence…",
    extracting: one ? "Reading the text out of it…" : "Reading the text out of them…",
    reading: "Working out the plan…",
    building: "Building the Circle…",
    done: "Done.",
    failed: "Failed.",
  }[stage];
}

export function ConveneDrop({
  organisationId,
  disabled,
}: {
  organisationId: string;
  disabled?: boolean;
}) {
  const [stage, setStage] = useState<ConveneStage | null>(null);
  const [detail, setDetail] = useState<string | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [dragging, setDragging] = useState(false);
  const [lookFor, setLookFor] = useState("");
  const [names, setNames] = useState<string[]>([]);
  const input = useRef<HTMLInputElement>(null);

  const busy = stage !== null && stage !== "failed";

  async function run(list: FileList | null | undefined) {
    const files = Array.from(list ?? []);
    if (files.length === 0 || busy) return;

    if (!organisationId) {
      setError(new Error("Choose the company convening this first."));
      return;
    }

    setError(null);
    setDetail(null);

    // Refused here, before a Circle exists, rather than by the pipeline after
    // one has been opened around a folder of photographs.
    const refusal = conveneRefusal(files);
    if (refusal) {
      setError(new Error(refusal));
      return;
    }

    setNames(files.map((f) => f.name));

    try {
      const { circle } = await conveneFromDocuments({
        organisationId,
        files,
        lookFor: lookFor.split("\n"),
        onStage: (s, d) => {
          setStage(s);
          setDetail(d ?? null);
        },
      });

      // Straight into the Circle, which is already built. The reading it was
      // built from is kept and sits behind "Convening" in the Circle's own
      // menu — a receipt to check against the contract, not a gate to pass.
      location.href = `/circles/${circle.id}`;
    } catch (e) {
      setError(e);
      setStage("failed");
    }
  }

  return (
    <Panel title="Convene from an engagement of terms" className="lay-in">
      <div className="space-y-4 px-5 pb-5">
        <p className="text-sm leading-relaxed text-[var(--ink-muted)]">
          Drop the contract, scope of works or letter of appointment — with its schedules, if
          they are separate files. They are filed as evidence in a new Circle and read together:
          the mission statement, the parties, the plan, the dated deliverables and the questions
          they leave open all come out of the documents, and the Circle is live when you land in
          it. Everything is editable from there.
        </p>

        <div
          onDragOver={(e) => {
            e.preventDefault();
            setDragging(true);
          }}
          onDragLeave={() => setDragging(false)}
          onDrop={(e) => {
            e.preventDefault();
            setDragging(false);
            void run(e.dataTransfer.files);
          }}
          onClick={() => !busy && input.current?.click()}
          className={`flex min-h-28 cursor-pointer flex-col items-center justify-center gap-1.5 rounded-[var(--r-control)] border border-dashed px-4 py-6 text-center transition-colors ${
            dragging
              ? "border-[var(--accent)] bg-[var(--paper-inset)]"
              : "border-[var(--rule-strong)]"
          } ${busy || disabled ? "pointer-events-none opacity-60" : ""}`}
        >
          {stage && stage !== "failed" ? (
            <>
              <p className="text-sm font-[560]">{stageText(stage, names.length)}</p>
              {names.length > 1 && (
                <p className="max-w-full truncate text-xs text-[var(--ink-muted)]">
                  {names.join(" · ")}
                </p>
              )}
              <p className="text-xs text-[var(--ink-faint)]">
                {stage === "extracting"
                  ? "A long contract takes a moment. This page will move on by itself."
                  : "Leave this page open."}
              </p>
            </>
          ) : (
            <>
              <p className="text-sm font-[560]">Drop the documents, or choose files</p>
              <p className="text-xs text-[var(--ink-faint)]">
                PDF, DOCX, TXT or MD · up to {CONVENE_MAX_FILES}, read together
              </p>
            </>
          )}
        </div>

        <input
          ref={input}
          type="file"
          multiple
          accept={CONVENE_EXTENSIONS.map((ext) => `.${ext}`).join(",")}
          className="hidden"
          onChange={(e) => {
            void run(e.target.files);
            e.target.value = "";
          }}
        />

        <Field
          label="Anything it should look for"
          hint="Optional, one per line. Questions the document may or may not answer — “what is the liquidated damages rate”, “who supplies traffic control”."
        >
          <textarea
            value={lookFor}
            onChange={(e) => setLookFor(e.target.value)}
            rows={2}
            className={`${inputClass} resize-y leading-relaxed`}
            disabled={busy}
          />
        </Field>

        {!!detail && <p className="text-xs text-[var(--signal)]">{detail}</p>}
        {!!error && <ErrorNote error={error} />}

        {stage === "failed" && (
          <Button variant="quiet" onClick={() => setStage(null)}>
            Try again
          </Button>
        )}

        {/*
          Said here rather than discovered on the review screen. The document
          becomes readable by an agent because that is the only way this works,
          and somebody dropping a contract with commercial terms in it is
          entitled to know that before they let go of it, not after.
        */}
        <p className="border-t border-[var(--rule)] pt-3 text-xs leading-snug text-[var(--ink-faint)]">
          Each document is filed as evidence and marked agent-readable, because reading it is
          the point. They stay in the Circle, filed against every piece of work they produced.
        </p>
      </div>
    </Panel>
  );
}
