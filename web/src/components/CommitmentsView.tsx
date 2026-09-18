import { useState } from "react";
import { api, formatDate, relativeDays, type Commitment, type Goal, type Member } from "../lib/api";
import { GoalChip, GoalField } from "./GoalPicker";
import { COMMITMENT_TONE, DerivedStamp, StatusChip } from "./Trust";
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
 * Commitments: a time-bound responsibility with an owner (spec §5).
 *
 * Rendered inside RecordView rather than owning a tab of its own — the frame,
 * the permissions and the plan are all fetched once up there and handed down.
 */
export function CommitmentsBody({
  circleId,
  canCreate,
  canUpdate,
  goals,
  goalId = null,
}: {
  circleId: string;
  canCreate: boolean;
  canUpdate: boolean;
  goals: Goal[];
  /**
   * Narrow the list to one node of the plan, and file new records there.
   *
   * Set only by the job screen. The composer's goal picker goes with it:
   * somebody recording this from inside a package has already answered "what
   * is this about", and asking again invites an answer that contradicts the
   * screen they are standing on.
   */
  goalId?: string | null;
}) {
  const [composing, setComposing] = useState(false);
  const { data, error, loading, reload, mutate } = useAsync<Commitment[]>(
    () => api.get<{ data: Commitment[] }>(`/circles/${circleId}/commitments`).then((r) => r.data),
    [circleId],
  );

  /*
    A status change returns the whole updated commitment, so the list is patched
    in place instead of being re-read. The row moves between Outstanding and
    Settled immediately and nothing else on the page so much as flickers.
  */
  function replace(updated: Commitment) {
    mutate((current) => current.map((c) => (c.id === updated.id ? updated : c)));
  }

  if (loading) return <Panel><Loading what="commitments" /></Panel>;
  if (error) return <ErrorNote error={error} />;

  const all = data ?? [];
  const open = all.filter((c) => !["done", "cancelled"].includes(c.status));
  const closed = all.filter((c) => ["done", "cancelled"].includes(c.status));

  return (
    <div className="space-y-5">
      {canCreate && (
        <div className="flex justify-end">
          <Button variant={composing ? "quiet" : "primary"} onClick={() => setComposing((v) => !v)}>
            {composing ? "Cancel" : "Record a commitment"}
          </Button>
        </div>
      )}

      {composing && (
        <Composer
          circleId={circleId}
          goals={goals}
          fixedGoalId={goalId}
          onDone={() => {
            setComposing(false);
            reload();
          }}
        />
      )}

      <Panel
        title="Outstanding"
        meta={
          <span className="text-xs text-[var(--ink-faint)]">
            {open.filter((c) => c.is_overdue).length} overdue
          </span>
        }
      >
        {open.length === 0 ? (
          <Empty>Nothing outstanding right now.</Empty>
        ) : (
          open.map((c, i) => (
            <Row
              key={c.id}
              commitment={c}
              circleId={circleId}
              canUpdate={canUpdate}
              onUpdated={replace}
              index={i}
            />
          ))
        )}
      </Panel>

      {closed.length > 0 && (
        <Panel title="Settled">
          {closed.map((c, i) => (
            <Row
              key={c.id}
              commitment={c}
              circleId={circleId}
              canUpdate={false}
              onUpdated={replace}
              index={i}
            />
          ))}
        </Panel>
      )}
    </div>
  );
}

function Row({
  commitment,
  circleId,
  canUpdate,
  onUpdated,
  index,
}: {
  commitment: Commitment;
  circleId: string;
  canUpdate: boolean;
  onUpdated: (commitment: Commitment) => void;
  index: number;
}) {
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  async function move(status: string) {
    setBusy(true);
    setError(null);
    try {
      const { data } = await api.patch<{ data: Commitment }>(
        `/commitments/${commitment.id}`,
        { status },
      );
      onUpdated(data);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <article
      className={`lay-in border-t border-[var(--rule)] px-5 py-4 ${
        commitment.derived ? "derived-panel" : ""
      }`}
      style={{ animationDelay: `${index * 25}ms` }}
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <p className="text-[1rem] leading-relaxed">{commitment.title}</p>
          {commitment.acceptance_condition && (
            <p className="mt-1.5 text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
              <span className="font-[590] text-[var(--ink-faint)]">Done when</span>{" "}
              {commitment.acceptance_condition}
            </p>
          )}
        </div>
        <StatusChip status={commitment.status} tone={COMMITMENT_TONE[commitment.status]} />
      </div>

      <div className="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1.5">
        {commitment.derived && <DerivedStamp compact />}
        <GoalChip goal={commitment.goal} circleId={circleId} />
        <span className="text-xs text-[var(--ink-faint)]">
          {commitment.owner.name ?? "unassigned"}
        </span>
        <span aria-hidden="true" className="text-[var(--rule-strong)]">·</span>
        <span
          className={`text-xs ${
            commitment.is_overdue
              ? "font-[560] text-[var(--signal)]"
              : "text-[var(--ink-faint)]"
          }`}
        >
          {commitment.due_at
            ? `${formatDate(commitment.due_at)} · ${relativeDays(commitment.due_at)}`
            : "No deadline"}
        </span>
      </div>

      {commitment.updates.length > 0 && (
        <ul className="mt-2 space-y-0.5 rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3.5 py-2.5">
          {commitment.updates.map((u, i) => (
            <li key={i} className="text-xs leading-relaxed text-[var(--ink-faint)]">
              {u.from ?? "—"} → {u.to}
              {u.note && <span> — {u.note}</span>}
              {" · "}
              {formatDate(u.at)}
            </li>
          ))}
        </ul>
      )}

      {canUpdate && (
        <div className="mt-3 flex flex-wrap gap-2">
          {commitment.status === "draft" && (
            <Button variant="primary" disabled={busy} onClick={() => move("open")}>
              Confirm
            </Button>
          )}
          <Button disabled={busy} onClick={() => move("blocked")}>
            Blocked
          </Button>
          <Button disabled={busy} onClick={() => move("done")}>
            Done
          </Button>
          <Button variant="danger" disabled={busy} onClick={() => move("cancelled")}>
            Cancel
          </Button>
        </div>
      )}

      {!!error && <div className="mt-3"><ErrorNote error={error} /></div>}
    </article>
  );
}

function Composer({
  circleId,
  goals,
  fixedGoalId = null,
  onDone,
}: {
  circleId: string;
  goals: Goal[];
  /** Set on the job screen: the node is decided, so it is stated, not asked. */
  fixedGoalId?: string | null;
  onDone: () => void;
}) {
  const { data: members } = useAsync<{ members: Member[] }>(
    () => api.get<{ data: { members: Member[] } }>(`/circles/${circleId}/members`).then((r) => r.data),
    [circleId],
  );

  const [title, setTitle] = useState("");
  const [condition, setCondition] = useState("");
  const [owner, setOwner] = useState("");
  const [due, setDue] = useState("");
  const [goalId, setGoalId] = useState(fixedGoalId ?? "");
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await api.post(`/circles/${circleId}/commitments`, {
        title,
        goal_id: goalId || undefined,
        acceptance_condition: condition || undefined,
        owner_user_id: owner || undefined,
        due_at: due ? new Date(due).toISOString() : undefined,
      });
      onDone();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Panel title="New commitment" className="lay-in">
      <div className="space-y-4 px-5 pb-5">
        <Field label="What is being committed to">
          <input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            className={inputClass}
            placeholder="Confirm stock availability for the 14 Sept possession"
          />
        </Field>

        {fixedGoalId === null && (
          <GoalField goals={goals} value={goalId} onChange={setGoalId} />
        )}

        <Field
          label="Acceptance condition"
          hint="How will everyone know this is actually done?"
        >
          <input
            value={condition}
            onChange={(e) => setCondition(e.target.value)}
            className={inputClass}
            placeholder="Written freight confirmation uploaded to this Circle."
          />
        </Field>

        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Owner">
            <select value={owner} onChange={(e) => setOwner(e.target.value)} className={inputClass}>
              <option value="">unassigned</option>
              {(members?.members ?? [])
                .filter((m) => m.is_active)
                .map((m) => (
                  <option key={m.user.id} value={m.user.id}>
                    {m.user.name} · {m.circle_role}
                  </option>
                ))}
            </select>
          </Field>

          <Field label="Due">
            <input type="datetime-local" value={due} onChange={(e) => setDue(e.target.value)} className={inputClass} />
          </Field>
        </div>

        {!!error && <ErrorNote error={error} />}

        <Button variant="primary" onClick={submit} disabled={busy || !title.trim()}>
          {busy ? "Recording…" : "Record commitment"}
        </Button>
      </div>
    </Panel>
  );
}
