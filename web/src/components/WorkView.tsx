import { useState } from "react";
import {
  api,
  formatDate,
  relativeDays,
  type Circle,
  type Goal,
  type Party,
  type Member,
} from "../lib/api";
import { CircleFrame } from "./CircleFrame";
import { Discussion } from "./Thread";
import {
  Button,
  Empty,
  ErrorNote,
  Field,
  Loading,
  Panel,
  inputClass,
  useAsync,
} from "./ui";

/**
 * Work — the goal tree, and the view the product is now organised around.
 *
 * The old "Now" screen reported state: what was blocked, what was pending. It
 * could not say what the mission *is*, because nothing in the model held that.
 * This does, and everything else — commitments, decisions, claims, discussion —
 * hangs off a node in it.
 *
 * Two levels only. Deeper nesting turns into a work-breakdown structure that
 * nobody maintains, and the second level is where responsibility actually lands.
 */
export function WorkView({ circleId }: { circleId: string }) {
  return (
    <CircleFrame circleId={circleId} tab="work">
      {(circle) => <Body circleId={circleId} circle={circle} />}
    </CircleFrame>
  );
}

function Body({ circleId, circle }: { circleId: string; circle: Circle | null }) {
  const perms = circle?.my_access?.permissions ?? [];
  const canCreate = perms.includes("goal.create") && !circle?.is_closed;
  const canUpdate = perms.includes("goal.update") && !circle?.is_closed;
  const canAccept = perms.includes("goal.accept") && !circle?.is_closed;
  const canComment = perms.includes("comment.create") && !circle?.is_closed;

  const [composingUnder, setComposingUnder] = useState<string | null | false>(false);

  const { data, error, loading, reload } = useAsync<Goal[]>(
    () => api.get<{ data: Goal[] }>(`/circles/${circleId}/goals`).then((r) => r.data),
    [circleId],
  );
  const { data: parties } = useAsync<Party[]>(
    () => api.get<{ data: Party[] }>(`/circles/${circleId}/parties`).then((r) => r.data),
    [circleId],
  );
  // `/members` answers with { members, pending_invitations, agents } rather
  // than a bare list — the same unwrapping the Commitments and Decisions
  // composers do. Reading it as an array put a non-array into `members.map`.
  const { data: members } = useAsync<Member[]>(
    () =>
      api
        .get<{ data: { members: Member[] } }>(`/circles/${circleId}/members`)
        .then((r) => r.data.members),
    [circleId],
  );

  if (loading) {
    return (
      <Panel>
        <Loading what="the plan" />
      </Panel>
    );
  }
  if (error) return <ErrorNote error={error} />;

  const goals = data ?? [];
  const overdue = countWhere(goals, (g) => g.is_overdue);
  const unowned = countWhere(goals, (g) => g.owner === null && g.responsible_party === null);

  return (
    <div className="space-y-5">
      {/*
        The one-line read on the plan. Deliberately three numbers and no chart:
        the tree below is the detail, and a second visualisation of the same
        thing would only compete with it.
      */}
      <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
        <h2 className="display text-[1.25rem] font-[640] text-[var(--ink)]">The plan</h2>
        <span className="text-[0.8125rem] text-[var(--ink-muted)]">
          {goals.length} goal{goals.length === 1 ? "" : "s"}
        </span>
        {overdue > 0 && (
          <span className="text-[0.8125rem] font-[560] text-[var(--signal)]">
            {overdue} overdue
          </span>
        )}
        {unowned > 0 && (
          <span
            className="text-[0.8125rem] text-[var(--ink-muted)]"
            title="Work with neither a person nor a company answerable for it."
          >
            {unowned} unassigned
          </span>
        )}

        {canCreate && (
          <span className="ml-auto">
            <Button
              variant={composingUnder === null ? "quiet" : "primary"}
              onClick={() => setComposingUnder((v) => (v === null ? false : null))}
            >
              {composingUnder === null ? "Cancel" : "Add a goal"}
            </Button>
          </span>
        )}
      </div>

      {composingUnder === null && (
        <GoalComposer
          circleId={circleId}
          parentId={null}
          parties={parties ?? []}
          members={members ?? []}
          onDone={() => {
            setComposingUnder(false);
            reload();
          }}
          onCancel={() => setComposingUnder(false)}
        />
      )}

      {goals.length === 0 ? (
        <Panel>
          <Empty>
            Nothing planned yet. A goal is an outcome someone is answerable for by
            a date — start with the two or three that decide whether this mission
            succeeds, then break each one down.
          </Empty>
        </Panel>
      ) : (
        <div className="space-y-4">
          {goals.map((goal) => (
            <GoalCard
              key={goal.id}
              goal={goal}
              circleId={circleId}
              parties={parties ?? []}
              members={members ?? []}
              canCreate={canCreate}
              canUpdate={canUpdate}
              canAccept={canAccept}
              canComment={canComment}
              onChanged={reload}
            />
          ))}
        </div>
      )}
    </div>
  );
}

/** A top-level goal and its sub-goals. */
function GoalCard({
  goal,
  circleId,
  parties,
  members,
  canCreate,
  canUpdate,
  canAccept,
  canComment,
  onChanged,
}: {
  goal: Goal;
  circleId: string;
  parties: Party[];
  members: Member[];
  canCreate: boolean;
  canUpdate: boolean;
  canAccept: boolean;
  canComment: boolean;
  onChanged: () => void;
}) {
  const [addingChild, setAddingChild] = useState(false);

  return (
    <Panel
      tone={goal.is_overdue ? "signal" : "default"}
      className="lay-in"
      title={undefined}
    >
      <div className="px-5 pb-4 pt-4">
        <GoalHeader
          goal={goal}
          parties={parties}
          canUpdate={canUpdate}
          canAccept={canAccept}
          onChanged={onChanged}
          large
        />
      </div>

      {/* Sub-goals: where the work and the responsibility actually sit. */}
      <div className="border-t border-[var(--rule)]">
        {(goal.children ?? []).length === 0 ? (
          <p className="px-5 py-3.5 text-[0.8125rem] text-[var(--ink-faint)]">
            No sub-goals. Break this down so each piece has one owner and one date.
          </p>
        ) : (
          <ul>
            {(goal.children ?? []).map((child) => (
              <li key={child.id} className="border-t border-[var(--rule)] first:border-t-0">
                <div className="px-5 py-3.5">
                  <GoalHeader
                    goal={child}
                    parties={parties}
                    canUpdate={canUpdate}
                    canAccept={canAccept}
                    onChanged={onChanged}
                  />
                  <SubGoalDetail
                    goal={child}
                    circleId={circleId}
                    canComment={canComment}
                  />
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>

      {canCreate && (
        <div className="border-t border-[var(--rule)] px-5 py-3">
          {addingChild ? (
            <GoalComposer
              circleId={circleId}
              parentId={goal.id}
              parties={parties}
              members={members}
              onDone={() => {
                setAddingChild(false);
                onChanged();
              }}
              onCancel={() => setAddingChild(false)}
            />
          ) : (
            <Button variant="quiet" onClick={() => setAddingChild(true)}>
              Add a sub-goal
            </Button>
          )}
        </div>
      )}
    </Panel>
  );
}

/**
 * One goal's identity line: what it is, who owes it, when, and how far along.
 *
 * The responsible *party* is shown next to the owner rather than instead of it.
 * In cross-company work "Sam" is not an answer to who owes this — Sam's company
 * is, and Sam might leave.
 */
function GoalHeader({
  goal,
  parties,
  canUpdate,
  canAccept,
  onChanged,
  large = false,
}: {
  goal: Goal;
  parties: Party[];
  canUpdate: boolean;
  canAccept: boolean;
  onChanged: () => void;
  large?: boolean;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [rescheduling, setRescheduling] = useState(false);

  async function act(fn: () => Promise<unknown>) {
    setBusy(true);
    setError(null);
    try {
      await fn();
      onChanged();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div className="min-w-0 flex-1">
          <p
            className={
              large
                ? "display text-[1.125rem] font-[640] leading-snug text-[var(--ink)]"
                : "text-[0.9375rem] font-[560] leading-snug text-[var(--ink)]"
            }
          >
            {goal.title}
          </p>
          {goal.description && (
            <p className="mt-1 text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
              {goal.description}
            </p>
          )}
        </div>

        <div className="flex shrink-0 items-center gap-2.5">
          <GoalStatusChip goal={goal} />
          <ProgressPip goal={goal} />
        </div>
      </div>

      <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[0.8125rem]">
        <span className="text-[var(--ink-muted)]">
          {goal.owner?.name ?? <span className="text-[var(--ink-faint)]">Unassigned</span>}
        </span>

        {goal.responsible_party && (
          <span
            className="rounded-[var(--r-chip)] bg-[var(--paper-sunk)] px-2 py-0.5 text-xs font-[560] text-[var(--ink-muted)]"
            title={`${goal.responsible_party.label} is answerable for this as ${goal.responsible_party.role}.`}
          >
            {goal.responsible_party.label}
          </span>
        )}

        <span
          className={
            goal.is_overdue
              ? "font-[560] text-[var(--signal)]"
              : "text-[var(--ink-faint)]"
          }
        >
          {goal.due_at ? relativeDays(goal.due_at) : "No date"}
        </span>

        {(goal.counts.commitments > 0 ||
          goal.counts.decisions > 0 ||
          goal.counts.claims > 0) && (
          <span className="text-xs text-[var(--ink-faint)]">
            {[
              goal.counts.commitments && `${goal.counts.commitments} commitment${goal.counts.commitments === 1 ? "" : "s"}`,
              goal.counts.decisions && `${goal.counts.decisions} decision${goal.counts.decisions === 1 ? "" : "s"}`,
              goal.counts.claims && `${goal.counts.claims} claim${goal.counts.claims === 1 ? "" : "s"}`,
            ]
              .filter(Boolean)
              .join(" · ")}
          </span>
        )}

        <span className="ml-auto flex items-center gap-1">
          {canUpdate && !goal.accepted_at && (
            <Button variant="quiet" disabled={busy} onClick={() => setRescheduling((v) => !v)}>
              Move date
            </Button>
          )}
          {/*
            Acceptance is offered rather than a status dropdown, because "met"
            is reached by someone signing it off — the API refuses the shortcut.
          */}
          {canAccept && !goal.accepted_at && goal.status !== "abandoned" && (
            <Button
              variant="primary"
              disabled={busy}
              title={goal.acceptance_condition ?? "Accept this work as done."}
              onClick={() => act(() => api.post(`/goals/${goal.id}/accept`))}
            >
              Accept
            </Button>
          )}
        </span>
      </div>

      {goal.acceptance_condition && !goal.accepted_at && (
        <p className="mt-2 rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3 py-2 text-xs leading-relaxed text-[var(--ink-muted)]">
          <span className="label">Done when</span>{" "}
          <span className="ml-1">{goal.acceptance_condition}</span>
        </p>
      )}

      {goal.accepted_at && (
        <p className="mt-2 text-xs text-[var(--settled)]">
          Accepted by {goal.accepted_by ?? "—"} · {formatDate(goal.accepted_at, true)}
        </p>
      )}

      {!!error && (
        <div className="mt-2">
          <ErrorNote error={error} />
        </div>
      )}

      {rescheduling && (
        <RescheduleForm
          goal={goal}
          parties={parties}
          onDone={() => {
            setRescheduling(false);
            onChanged();
          }}
          onCancel={() => setRescheduling(false)}
        />
      )}
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
function RescheduleForm({
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

/** Progress reporting and discussion for one sub-goal. */
function SubGoalDetail({
  goal,
  circleId,
  canComment,
}: {
  goal: Goal;
  circleId: string;
  canComment: boolean;
}) {
  const [open, setOpen] = useState(false);

  return (
    <div className="mt-2">
      <button
        onClick={() => setOpen((v) => !v)}
        className="text-xs text-[var(--accent)] hover:underline"
      >
        {open ? "Hide discussion" : "Discussion"}
      </button>

      {open && (
        <div className="mt-3">
          <Discussion
            circleId={circleId}
            subject={{ type: "goal", id: goal.id }}
            canComment={canComment}
            compact
          />
        </div>
      )}
    </div>
  );
}

function GoalComposer({
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

        {/* The company answerable for it, which outlives whoever owns it today. */}
        <Field label="Answerable company">
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
        hint="Agree this now. Without it, completion is whatever the owner says it is."
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

// ------------------------------------------------------------------- bits

function GoalStatusChip({ goal }: { goal: Goal }) {
  const tone =
    goal.status === "met"
      ? { bg: "var(--settled-soft)", fg: "var(--settled)" }
      : goal.status === "blocked" || goal.is_overdue
        ? { bg: "var(--signal-soft)", fg: "var(--signal)" }
        : goal.status === "in_review"
          ? { bg: "var(--derived-soft)", fg: "var(--derived)" }
          : { bg: "var(--paper-sunk)", fg: "var(--ink-muted)" };

  return (
    <span
      className="rounded-[var(--r-chip)] px-2 py-0.5 text-xs font-[560] capitalize"
      style={{ background: tone.bg, color: tone.fg }}
    >
      {goal.status.replace(/_/g, " ")}
    </span>
  );
}

/**
 * Progress as a number with its provenance.
 *
 * A derived figure is marked as such: a parent's number is the average of its
 * children, and presenting that identically to a figure someone typed would
 * make the distinction invisible exactly where it matters.
 */
function ProgressPip({ goal }: { goal: Goal }) {
  return (
    <span
      className="tabular text-[0.8125rem] font-[560] text-[var(--ink-muted)]"
      title={
        goal.progress_is_derived
          ? "Averaged from this goal's sub-goals."
          : "Reported by whoever owns this."
      }
    >
      {goal.progress}%
      {goal.progress_is_derived && (
        <span className="ml-1 text-xs font-[450] text-[var(--ink-faint)]">avg</span>
      )}
    </span>
  );
}

function countWhere(goals: Goal[], predicate: (g: Goal) => boolean): number {
  return goals.reduce(
    (total, g) =>
      total + (predicate(g) ? 1 : 0) + (g.children ?? []).filter(predicate).length,
    0,
  );
}
