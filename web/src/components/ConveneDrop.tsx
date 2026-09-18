import { useRef, useState } from "react";
import { conveneFromDocument, type ConveneStage } from "../lib/convening";
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
 * working Circle, not a form and not a review queue.
 *
 * What it does not do is hide where any of that came from. The reading is kept
 * as a derived artifact and sits behind "Convening" in the Circle's own menu,
 * marking every line stated or inferred, so checking the plan against the
 * contract is one click rather than an archaeology exercise.
 */
const STAGE_TEXT: Record<ConveneStage, string> = {
  opening: "Opening the Circle…",
  signing: "Preparing the upload…",
  uploading: "Uploading the document…",
  registering: "Filing it as evidence…",
  extracting: "Reading the text out of it…",
  reading: "Working out the plan…",
  building: "Building the Circle…",
  done: "Done.",
  failed: "Failed.",
};

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
  const input = useRef<HTMLInputElement>(null);

  const busy = stage !== null && stage !== "failed";

  async function run(file: File | null | undefined) {
    if (!file || busy) return;

    if (!organisationId) {
      setError(new Error("Choose the company convening this first."));
      return;
    }

    setError(null);
    setDetail(null);

    try {
      const { circle } = await conveneFromDocument({
        organisationId,
        file,
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
          Drop the contract, scope of works or letter of appointment. It is filed as evidence
          in a new Circle and read: the mission statement, the parties, the plan, the dated
          deliverables and the questions it leaves open all come out of the document, and the
          Circle is live when you land in it. Everything is editable from there.
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
            void run(e.dataTransfer.files?.[0]);
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
              <p className="text-sm font-[560]">{STAGE_TEXT[stage]}</p>
              <p className="text-xs text-[var(--ink-faint)]">
                {stage === "extracting"
                  ? "A long contract takes a moment. This page will move on by itself."
                  : "Leave this page open."}
              </p>
            </>
          ) : (
            <>
              <p className="text-sm font-[560]">Drop the document, or choose a file</p>
              <p className="text-xs text-[var(--ink-faint)]">PDF, DOCX, TXT or MD</p>
            </>
          )}
        </div>

        <input
          ref={input}
          type="file"
          accept=".pdf,.docx,.txt,.md"
          className="hidden"
          onChange={(e) => {
            void run(e.target.files?.[0]);
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
          The document is filed as evidence and marked agent-readable, because reading it is
          the point. It stays in the Circle, filed against every piece of work it produced.
        </p>
      </div>
    </Panel>
  );
}
