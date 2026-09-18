import { useEffect, useRef, useState } from "react";
import { api, type Circle } from "../lib/api";
import { Button, Field, inputClass } from "./ui";

/**
 * The mission statement in the Circle header: what this is, and what it is for.
 *
 * Both were fixed at creation until now, which meant the one sentence every
 * other screen frames itself with was the only thing in the product you could
 * not correct. Missions get renamed — a bid becomes a stage, a package changes
 * number — and a Circle that cannot say so ends up with a title nobody trusts.
 *
 * Editing is deliberately not free-form-in-place. A restatement is an audited
 * assertion like any other: it opens a form, it asks why, and it says on the
 * form that the change is recorded. Somebody who half-clicked the heading and
 * typed a character should not be able to write history by accident.
 */
export function MissionStatement({
  circle,
  editable,
  onSaved,
}: {
  circle: Circle;
  /** Whether the gate would actually let this caller through. */
  editable: boolean;
  /** Handed the saved Circle, so the header updates without a re-read. */
  onSaved: (circle: Circle) => void;
}) {
  const [editing, setEditing] = useState(false);

  if (editing) {
    return (
      <MissionForm
        circle={circle}
        onDone={(saved) => {
          if (saved) onSaved(saved);
          setEditing(false);
        }}
      />
    );
  }

  return (
    <div className="group/mission">
      <div className="flex items-start gap-2">
        <h1 className="display text-[2rem] font-[680] leading-[1.12] text-[var(--ink)]">
          {circle.name}
        </h1>
        {editable && (
          <button
            onClick={() => setEditing(true)}
            title="Edit the name and purpose of this Circle"
            aria-label="Edit the mission statement"
            /*
              Present on hover and whenever it has focus, so the heading stays
              a heading for the great majority of visits that only read it —
              without the control being reachable by mouse alone.
            */
            className="mt-2 shrink-0 rounded-[var(--r-control)] px-2 py-1 text-[0.75rem] font-[560] text-[var(--ink-faint)] opacity-0 transition-opacity duration-150 hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)] focus-visible:opacity-100 group-hover/mission:opacity-100"
          >
            Edit
          </button>
        )}
      </div>
      <p className="mt-2.5 text-[0.9375rem] leading-relaxed text-[var(--ink-muted)]">
        {circle.purpose}
      </p>
    </div>
  );
}

function MissionForm({
  circle,
  onDone,
}: {
  circle: Circle;
  /** Called with the saved Circle, or null if the edit was abandoned. */
  onDone: (circle: Circle | null) => void;
}) {
  const [name, setName] = useState(circle.name);
  const [purpose, setPurpose] = useState(circle.purpose);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const first = useRef<HTMLInputElement>(null);

  useEffect(() => first.current?.select(), []);

  const trimmedName = name.trim();
  const trimmedPurpose = purpose.trim();
  const changed = trimmedName !== circle.name || trimmedPurpose !== circle.purpose;

  async function save() {
    if (!changed || trimmedName === "" || trimmedPurpose === "") return;

    setBusy(true);
    setError(null);
    try {
      const { data } = await api.patch<{ data: Circle }>(`/circles/${circle.id}`, {
        name: trimmedName,
        purpose: trimmedPurpose,
        ...(reason.trim() === "" ? {} : { reason: reason.trim() }),
      });
      onDone(data);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not save.");
      setBusy(false);
    }
  }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        void save();
      }}
      onKeyDown={(e) => {
        if (e.key === "Escape") onDone(null);
      }}
      className="card p-4"
    >
      <Field label="Name">
        <input
          ref={first}
          value={name}
          onChange={(e) => setName(e.target.value)}
          maxLength={200}
          required
          className={`${inputClass} display !text-[1.25rem] !font-[640]`}
        />
      </Field>

      <div className="mt-3">
        <Field
          label="Purpose"
          hint="What this Circle exists to do. Everything the parties decide here is read against it."
        >
          <textarea
            value={purpose}
            onChange={(e) => setPurpose(e.target.value)}
            maxLength={5000}
            required
            rows={3}
            className={`${inputClass} resize-y leading-relaxed`}
          />
        </Field>
      </div>

      <div className="mt-3">
        <Field label="Why (optional)">
          <input
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            maxLength={1000}
            placeholder="Scope moved to stage 2 after the client meeting."
            className={inputClass}
          />
        </Field>
      </div>

      <div className="mt-4 flex items-center gap-2">
        <Button type="submit" variant="primary" disabled={busy || !changed}>
          {busy ? "Saving…" : "Save"}
        </Button>
        <Button variant="quiet" onClick={() => onDone(null)} disabled={busy}>
          Cancel
        </Button>
        <p
          className={`ml-1 text-xs leading-snug ${
            error ? "text-[var(--signal)]" : "text-[var(--ink-faint)]"
          }`}
        >
          {error ??
            "Recorded in this Circle's history, with the previous wording and who changed it."}
        </p>
      </div>
    </form>
  );
}
