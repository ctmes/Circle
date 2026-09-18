import { useState } from "react";
import { api, type Branch, type Goal, type Member, type Party } from "../lib/api";
import { Button, ErrorNote, Field, inputClass } from "./ui";

/**
 * Editing a goal on a branch.
 *
 * The same fields as editing it for real, and none of the consequences: every
 * control here writes a change row that sits in the branch until the affected
 * parties agree.
 *
 * The wording is in the conditional throughout — "propose", "would move" —
 * because the single most expensive mistake this screen can make is letting
 * somebody believe they have changed the plan when they have only asked to. A
 * contractor who thinks a date moved, and finds out three weeks later that
 * nobody agreed, is worse off than one who was never offered the button.
 */
export function BranchEditor({
  goal,
  branch,
  parties,
  members,
  canBranch,
  canCreate,
  onChanged,
}: {
  goal: Goal;
  branch: Branch;
  parties: Party[];
  members: Member[];
  canBranch: boolean;
  canCreate: boolean;
  onChanged: () => void;
}) {
  const [mode, setMode] = useState<"none" | "edit" | "add">("none");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  const [title, setTitle] = useState(goal.title);
  const [owner, setOwner] = useState(goal.owner?.id ?? "");
  const [party, setParty] = useState(goal.responsible_party?.id ?? "");
  const [dueAt, setDueAt] = useState(goal.due_at?.slice(0, 10) ?? "");
  const [reason, setReason] = useState("");

  const [childTitle, setChildTitle] = useState("");
  const [childCondition, setChildCondition] = useState("");

  const staged = goal.branch ?? null;
  const dateMoved = dueAt !== (goal.due_at?.slice(0, 10) ?? "");

  async function stage(body: Record<string, unknown>) {
    setBusy(true);
    setError(null);
    try {
      await api.post(`/branches/${branch.id}/changes`, body);
      setMode("none");
      setReason("");
      setChildTitle("");
      setChildCondition("");
      onChanged();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  if (!canBranch) {
    return (
      <p className="text-xs text-[var(--ink-faint)]">
        You can read this branch but not add to it.
      </p>
    );
  }

  return (
    <div className="space-y-3">
      {staged && (
        <p className="text-xs text-[var(--ink-muted)]">
          This branch already{" "}
          {staged.state === "added"
            ? "adds"
            : staged.state === "removed"
              ? "proposes dropping"
              : "changes"}{" "}
          this node.
        </p>
      )}

      {!!error && <ErrorNote error={error} />}

      {mode === "none" && (
        <div className="flex flex-wrap items-center gap-1.5">
          {/*
            A node the branch is proposing to add has no id yet, so there is
            nothing on main to stage a further change against. Editing it means
            editing the change itself, which is a different thing and lives in
            the review panel.
          */}
          {goal.id !== null && (
            <>
              <Button variant="quiet" disabled={busy} onClick={() => setMode("edit")}>
                Propose a change
              </Button>
              <Button
                variant="danger"
                disabled={busy}
                title="On merge it is marked abandoned, never deleted — anything that cited it still works, and the record still shows the work existed and was dropped."
                onClick={() => stage({ change_type: "remove", goal_id: goal.id })}
              >
                Propose dropping it
              </Button>
            </>
          )}

          {canCreate && goal.id !== null && (
            <Button variant="quiet" disabled={busy} onClick={() => setMode("add")}>
              Propose a sub-goal
            </Button>
          )}
        </div>
      )}

      {mode === "edit" && goal.id !== null && (
        <div className="max-w-2xl space-y-3 rounded-[var(--r-control)] border border-[var(--rule)] bg-[var(--paper-raised)] p-3.5">
          <Field label="Title">
            <input
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              className={inputClass}
            />
          </Field>

          <div className="grid gap-3 sm:grid-cols-3">
            <Field label="Owner">
              <select
                value={owner}
                onChange={(e) => setOwner(e.target.value)}
                className={inputClass}
              >
                <option value="">unassigned</option>
                {members.map((m) => (
                  <option key={m.user.id} value={m.user.id}>
                    {m.user.name}
                  </option>
                ))}
              </select>
            </Field>

            <Field label="Responsible company">
              <select
                value={party}
                onChange={(e) => setParty(e.target.value)}
                className={inputClass}
              >
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

          {/*
            A date that can move without a reason carries no weight, and a
            branch must not become the one route around that. The API refuses it
            as well; asking here means the author is not told after the fact.
          */}
          {dateMoved && (
            <Field label="Why is the date moving?">
              <input
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                autoFocus
                placeholder="Freight confirmation still pending."
                className={inputClass}
              />
            </Field>
          )}

          <div className="flex gap-2">
            <Button
              variant="primary"
              disabled={busy || (dateMoved && !reason.trim())}
              onClick={() => {
                // Only what actually differs is staged. Sending every field
                // would make the diff claim changes nobody made, and each of
                // those is a field the other party has to read and clear.
                const attributes: Record<string, unknown> = {};

                if (title !== goal.title) attributes.title = title;
                if (owner !== (goal.owner?.id ?? "")) attributes.owner_user_id = owner || null;
                if (party !== (goal.responsible_party?.id ?? "")) {
                  attributes.responsible_party_id = party || null;
                }
                if (dateMoved) {
                  attributes.due_at = dueAt ? new Date(dueAt).toISOString() : null;
                }

                if (Object.keys(attributes).length === 0) {
                  setError(new Error("You haven't changed anything yet."));
                  return;
                }

                stage({
                  change_type: "update",
                  goal_id: goal.id,
                  attributes,
                  reason: reason || null,
                });
              }}
            >
              {busy ? "Staging…" : "Stage this change"}
            </Button>
            <Button variant="quiet" onClick={() => setMode("none")}>
              Cancel
            </Button>
          </div>
        </div>
      )}

      {mode === "add" && goal.id !== null && (
        <div className="max-w-2xl space-y-3 rounded-[var(--r-control)] border border-[var(--rule)] bg-[var(--paper-raised)] p-3.5">
          <Field label="What is the sub-goal?">
            <input
              value={childTitle}
              onChange={(e) => setChildTitle(e.target.value)}
              autoFocus
              placeholder="NDT witness point"
              className={inputClass}
            />
          </Field>

          <Field
            label="Done when"
            hint="Agree this up front. Without it, “done” is whatever the owner says it is."
          >
            <input
              value={childCondition}
              onChange={(e) => setChildCondition(e.target.value)}
              placeholder="Witness report countersigned."
              className={inputClass}
            />
          </Field>

          <div className="flex gap-2">
            <Button
              variant="primary"
              disabled={busy || !childTitle.trim()}
              onClick={() =>
                stage({
                  change_type: "add",
                  parent_goal_id: goal.id,
                  attributes: {
                    title: childTitle,
                    acceptance_condition: childCondition || null,
                  },
                })
              }
            >
              {busy ? "Staging…" : "Stage this addition"}
            </Button>
            <Button variant="quiet" onClick={() => setMode("none")}>
              Cancel
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
