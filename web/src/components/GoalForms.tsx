import { useState } from "react";
import { api, type Goal, type GoalStatus, type Member, type Party } from "../lib/api";
import { Button, ErrorNote, Field, inputClass } from "./ui";

/**
 * The forms that change a goal for real, shared by the Tree and a job's own page.
 *
 * They lived inside the Tree screen, which made that the only place a plan could
 * be changed — and the Tree sits behind "More", while a Circle convened from a
 * document lands on Main and every job links to its own page. A plan somebody
 * could read everywhere and edit in one out-of-the-way place read as a plan that
 * could not be edited at all.
 */

/**
 * The statuses a person can set by editing. `met` is absent on purpose: it is
 * reached by accepting the work, and the API refuses it here so that the record
 * always shows who signed it off.
 */
const EDITABLE_STATUSES: GoalStatus[] = ["draft", "active", "blocked", "in_review", "abandoned"];

/**
 * Editing what a goal is: its wording, who owes it, and what "done" means.
 *
 * The due date is not here. It moves through the reschedule form, which asks
 * why — the API will not take it as a plain field edit, and a date that could
 * move silently would carry no weight with anybody.
 *
 * Only the fields that changed are sent, so the audit entry records what this
 * person actually altered rather than a copy of every field as it already was.
 */
export function GoalEditForm({
  goal,
  parties,
  members,
  onDone,
  onCancel,
}: {
  goal: Goal;
  parties: Party[];
  members: Member[];
  onDone: () => void;
  onCancel: () => void;
}) {
  const [title, setTitle] = useState(goal.title);
  const [description, setDescription] = useState(goal.description ?? "");
  const [condition, setCondition] = useState(goal.acceptance_condition ?? "");
  const [owner, setOwner] = useState(goal.owner?.id ?? "");
  const [party, setParty] = useState(goal.responsible_party?.id ?? "");
  const [status, setStatus] = useState<GoalStatus>(goal.status);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  // Somebody who owns this but has since left the Circle is still its owner
  // until someone says otherwise, so they stay selectable rather than the
  // select silently showing "unassigned" over a goal that is not.
  const ownerMissing = goal.owner !== null && !members.some((m) => m.user.id === goal.owner?.id);

  function changes(): Record<string, unknown> {
    const body: Record<string, unknown> = {};
    const text = (v: string) => (v.trim() === "" ? null : v.trim());

    if (title.trim() !== goal.title) body.title = title.trim();
    if (text(description) !== (goal.description ?? null)) body.description = text(description);
    if (text(condition) !== (goal.acceptance_condition ?? null)) {
      body.acceptance_condition = text(condition);
    }
    if ((owner || null) !== (goal.owner?.id ?? null)) body.owner_user_id = owner || null;
    if ((party || null) !== (goal.responsible_party?.id ?? null)) {
      body.responsible_party_id = party || null;
    }
    if (status !== goal.status) body.status = status;

    return body;
  }

  async function save() {
    const body = changes();

    if (Object.keys(body).length === 0) {
      onCancel();
      return;
    }

    setBusy(true);
    setError(null);
    try {
      await api.patch(`/goals/${goal.id}`, body);
      onDone();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-3 rounded-[var(--r-control)] border border-[var(--rule)] bg-[var(--paper-inset)] p-4">
      <Field label="What is it?">
        <input
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          autoFocus
          className={inputClass}
        />
      </Field>

      <Field label="Description">
        <textarea
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          rows={3}
          className={`${inputClass} resize-y leading-relaxed`}
        />
      </Field>

      <div className="grid gap-3 sm:grid-cols-3">
        <Field label="Owner">
          <select value={owner} onChange={(e) => setOwner(e.target.value)} className={inputClass}>
            <option value="">unassigned</option>
            {ownerMissing && goal.owner && (
              <option value={goal.owner.id}>{goal.owner.name ?? "former member"}</option>
            )}
            {members.map((m) => (
              <option key={m.user.id} value={m.user.id}>
                {m.user.name}
              </option>
            ))}
          </select>
        </Field>

        <Field label="Responsible company">
          <select value={party} onChange={(e) => setParty(e.target.value)} className={inputClass}>
            <option value="">not set</option>
            {parties.map((p) => (
              <option key={p.id} value={p.id}>
                {p.label}
              </option>
            ))}
          </select>
        </Field>

        <Field label="Status">
          <select
            value={status}
            onChange={(e) => setStatus(e.target.value as GoalStatus)}
            className={`${inputClass} capitalize`}
          >
            {EDITABLE_STATUSES.map((s) => (
              <option key={s} value={s}>
                {s.replace(/_/g, " ")}
              </option>
            ))}
          </select>
        </Field>
      </div>

      <Field
        label="Done when"
        hint="Change this before the work is done, not after — it is what acceptance is measured against."
      >
        <input
          value={condition}
          onChange={(e) => setCondition(e.target.value)}
          className={inputClass}
        />
      </Field>

      <p className="text-xs leading-snug text-[var(--ink-faint)]">
        The date moves separately, with a reason, so that anyone relying on it can see why.
      </p>

      {!!error && <ErrorNote error={error} />}

      <div className="flex gap-2">
        <Button variant="primary" disabled={busy || !title.trim()} onClick={save}>
          {busy ? "Saving…" : "Save changes"}
        </Button>
        <Button variant="quiet" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

/**
 * Moving a date, with a reason.
 *
 * The reason is not optional in spirit — a date that can move silently carries
 * no weight, and in inter-company work a slipped date is the thing people end
 * up arguing about. Naming the party that has to agree makes the unagreed move
 * visible instead of settled.
 */
export function RescheduleForm({
  goal,
  parties,
  onDone,
  onCancel,
}: {
  goal: Goal;
  parties: Party[];
  onDone: () => void;
  onCancel: () => void;
}) {
  const [dueAt, setDueAt] = useState(goal.due_at?.slice(0, 10) ?? "");
  const [reason, setReason] = useState("");
  const [requiresParty, setRequiresParty] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  return (
    <div className="mt-3 space-y-3 rounded-[var(--r-control)] border border-[var(--rule)] bg-[var(--paper-inset)] p-3.5">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="New date">
          <input
            type="date"
            value={dueAt}
            onChange={(e) => setDueAt(e.target.value)}
            className={inputClass}
          />
        </Field>
        <Field
          label="Needs agreement from"
          hint="Leave empty if this move affects nobody else."
        >
          <select
            value={requiresParty}
            onChange={(e) => setRequiresParty(e.target.value)}
            className={inputClass}
          >
            <option value="">nobody</option>
            {parties
              .filter((p) => !p.is_convener)
              .map((p) => (
                <option key={p.id} value={p.id}>
                  {p.label}
                </option>
              ))}
          </select>
        </Field>
      </div>

      <Field label="Why is it moving?">
        <input
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder="Freight confirmation still pending."
          className={inputClass}
        />
      </Field>

      {!!error && <ErrorNote error={error} />}

      <div className="flex gap-2">
        <Button
          variant="primary"
          disabled={busy || !reason.trim()}
          onClick={async () => {
            setBusy(true);
            setError(null);
            try {
              await api.post(`/goals/${goal.id}/reschedule`, {
                due_at: dueAt ? new Date(dueAt).toISOString() : null,
                reason,
                requires_party_id: requiresParty || null,
              });
              onDone();
            } catch (e) {
              setError(e);
            } finally {
              setBusy(false);
            }
          }}
        >
          {busy ? "Moving…" : "Move the date"}
        </Button>
        <Button variant="quiet" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

export function GoalComposer({
  circleId,
  parentId,
  parties,
  members,
  onDone,
  onCancel,
}: {
  circleId: string;
  parentId: string | null;
  parties: Party[];
  members: Member[];
  onDone: () => void;
  onCancel: () => void;
}) {
  const [title, setTitle] = useState("");
  const [owner, setOwner] = useState("");
  const [party, setParty] = useState("");
  const [condition, setCondition] = useState("");
  const [dueAt, setDueAt] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await api.post(`/circles/${circleId}/goals`, {
        title,
        parent_goal_id: parentId,
        owner_user_id: owner || null,
        responsible_party_id: party || null,
        acceptance_condition: condition || null,
        due_at: dueAt ? new Date(dueAt).toISOString() : null,
      });
      onDone();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-3 rounded-[var(--r-control)] border border-[var(--rule)] bg-[var(--paper-inset)] p-4">
      <Field label={parentId ? "What is the sub-goal?" : "What is the goal?"}>
        <input
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          autoFocus
          placeholder={
            parentId
              ? "Geotechnical review complete"
              : "Bid package ready to submit"
          }
          className={inputClass}
        />
      </Field>

      <div className="grid gap-3 sm:grid-cols-3">
        <Field label="Owner">
          <select value={owner} onChange={(e) => setOwner(e.target.value)} className={inputClass}>
            <option value="">unassigned</option>
            {members.map((m) => (
              <option key={m.user.id} value={m.user.id}>
                {m.user.name}
              </option>
            ))}
          </select>
        </Field>

        {/* The company on the hook for it, which outlives whoever owns it today. */}
        <Field label="Responsible company">
          <select value={party} onChange={(e) => setParty(e.target.value)} className={inputClass}>
            <option value="">not set</option>
            {parties.map((p) => (
              <option key={p.id} value={p.id}>
                {p.label}
              </option>
            ))}
          </select>
        </Field>

        <Field label="Due">
          <input
            type="date"
            value={dueAt}
            onChange={(e) => setDueAt(e.target.value)}
            className={inputClass}
          />
        </Field>
      </div>

      <Field
        label="Done when"
        hint="Agree this up front. Without it, “done” is whatever the owner says it is."
      >
        <input
          value={condition}
          onChange={(e) => setCondition(e.target.value)}
          placeholder="Signed geotechnical report uploaded to this Circle."
          className={inputClass}
        />
      </Field>

      {!!error && <ErrorNote error={error} />}

      <div className="flex gap-2">
        <Button variant="primary" disabled={busy || !title.trim()} onClick={submit}>
          {busy ? "Adding…" : parentId ? "Add sub-goal" : "Add goal"}
        </Button>
        <Button variant="quiet" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </div>
  );
}
