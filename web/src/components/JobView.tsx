import { useState } from "react";
import {
  api,
  formatDate,
  relativeDays,
  type AuditEventRow,
  type Branch,
  type Circle,
  type Goal,
  type Member,
  type Party,
} from "../lib/api";
import { CircleFrame } from "./CircleFrame";
import { ClaimsBody } from "./ClaimsView";
import { CommitmentsBody } from "./CommitmentsView";
import { DecisionsBody } from "./DecisionsView";
import { GoalComposer, GoalEditForm, RescheduleForm } from "./GoalForms";
import { useGoals } from "./GoalPicker";
import { JobFiles } from "./JobFiles";
import { Discussion } from "./Thread";
import { Button, Empty, ErrorNote, Loading, Meta, Panel, useAsync } from "./ui";

/**
 * One job, with its own address.
 *
 * Everything about a piece of work used to be reachable only by selecting it
 * somewhere else: open the plan, find the row, expand it, and read a panel that
 * had room for the acceptance condition and not much more. Everything filed
 * against it lived on other screens, filtered by nothing, and the only way to
 * send somebody to it was "it's the third one down under Piling".
 *
 * So: a URL. What is on it is what a person actually does with a job — read
 * what it is, talk about it, put files against it, see what is on the record,
 * and check what is being proposed for it — and the sections are a sub-nav
 * rather than a stack, because five panels down a page is a page nobody reads
 * past the second.
 *
 * The plan stays the index. This is where you land from it, not a replacement
 * for it.
 */

const SECTIONS = [
  { key: "files", label: "Files" },
  { key: "discussion", label: "Discussion" },
  { key: "record", label: "Record" },
  { key: "changes", label: "Changes" },
] as const;

type Section = (typeof SECTIONS)[number]["key"];

// Mirrors config('circle.goals.max_depth'), as the Tree does. Only hides a
// button the API would refuse anyway — the cap itself lives on the server.
const MAX_DEPTH = 4;

export function JobView({ circleId, goalId }: { circleId: string; goalId: string }) {
  return (
    // No tab is named: a job is not one of the Circle's destinations, it is a
    // place inside the plan. Claiming "Main" or "Tree" in the bar would tell
    // the reader they are somewhere they are not.
    <CircleFrame circleId={circleId} tab="job">
      {(circle) => <Body circleId={circleId} goalId={goalId} circle={circle} />}
    </CircleFrame>
  );
}

function Body({
  circleId,
  goalId,
  circle,
}: {
  circleId: string;
  goalId: string;
  circle: Circle | null;
}) {
  const [section, setSection] = useState<Section>("files");

  const { data: goal, error, loading, reload } = useAsync<Goal>(
    () => api.get<{ data: Goal }>(`/goals/${goalId}`).then((r) => r.data),
    [goalId],
  );

  // Who the work can be given to, for the edit and sub-job forms. `/members`
  // answers with { members, pending_invitations, agents }, not a bare list.
  const { data: parties } = useAsync<Party[]>(
    () => api.get<{ data: Party[] }>(`/circles/${circleId}/parties`).then((r) => r.data),
    [circleId],
  );
  const { data: members } = useAsync<Member[]>(
    () =>
      api
        .get<{ data: { members: Member[] } }>(`/circles/${circleId}/members`)
        .then((r) => r.data.members),
    [circleId],
  );

  const perms = circle?.my_access?.permissions ?? [];
  const closed = circle?.is_closed === true;
  const may = (p: string) => perms.includes(p) && !closed;

  if (loading) {
    return (
      <Panel>
        <Loading what="this job" />
      </Panel>
    );
  }
  if (error) return <ErrorNote error={error} />;
  if (!goal) return null;

  return (
    <div className="space-y-5">
      <Header
        circleId={circleId}
        goal={goal}
        parties={parties ?? []}
        members={members ?? []}
        canAccept={may("goal.accept")}
        canUpdate={may("goal.update")}
        canCreate={may("goal.create")}
        onChanged={reload}
      />

      <nav className="flex flex-wrap gap-1 border-b border-[var(--rule)]" aria-label="Job sections">
        {SECTIONS.map((s) => (
          <button
            key={s.key}
            type="button"
            onClick={() => setSection(s.key)}
            aria-current={section === s.key ? "page" : undefined}
            className={`-mb-px border-b-2 px-3 py-2 text-[0.8125rem] transition-colors ${
              section === s.key
                ? "border-[var(--ink)] font-medium text-[var(--ink)]"
                : "border-transparent text-[var(--ink-faint)] hover:text-[var(--ink-soft)]"
            }`}
          >
            {s.label}
            {s.key === "record" && (
              <span className="ml-1.5 text-xs text-[var(--ink-faint)] tabular">
                {goal.counts.commitments + goal.counts.decisions + goal.counts.claims}
              </span>
            )}
          </button>
        ))}
      </nav>

      {section === "files" && (
        <JobFiles
          circleId={circleId}
          goalId={goalId}
          canFile={may("goal.update")}
          canUpload={may("goal.update") && may("resource.upload")}
          closed={closed}
        />
      )}

      {section === "discussion" && (
        <Panel title="Discussion" meta={<Meta>about this job only</Meta>}>
          <div className="px-5 pb-5 pt-1">
            <Discussion
              circleId={circleId}
              subject={{ type: "goal", id: goalId }}
              canComment={may("comment.create")}
            />
          </div>
        </Panel>
      )}

      {section === "record" && (
        <JobRecord circleId={circleId} goalId={goalId} circle={circle} />
      )}

      {section === "changes" && (
        <JobChanges circleId={circleId} goal={goal} canUpdate={may("goal.update")} onChanged={reload} />
      )}

      {(goal.children ?? []).length > 0 && <SubJobs circleId={circleId} goal={goal} />}
    </div>
  );
}

/**
 * What this job is, above everything that hangs off it.
 *
 * The facts in the order somebody arriving asks for them: what it is, who owes
 * it, when it is due, how far along, and what "done" was agreed to mean. The
 * acceptance condition is not tucked into a detail pane — on a job screen it is
 * the sentence the whole page is about.
 */
function Header({
  circleId,
  goal,
  parties,
  members,
  canAccept,
  canUpdate,
  canCreate,
  onChanged,
}: {
  circleId: string;
  goal: Goal;
  parties: Party[];
  members: Member[];
  canAccept: boolean;
  canUpdate: boolean;
  canCreate: boolean;
  onChanged: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  // One form open at a time: each of them rewrites part of this header, and two
  // open at once would each be editing a goal the other is about to change.
  const [form, setForm] = useState<"none" | "edit" | "date" | "child">("none");

  const toggle = (f: typeof form) => setForm((cur) => (cur === f ? "none" : f));
  const closeAndReload = () => {
    setForm("none");
    onChanged();
  };

  // Accepted work is a statement about what was done, and changing what it
  // was, or when it was due, after the sign-off would rewrite that statement.
  const editable = canUpdate && !goal.accepted_at;
  const atDepthLimit = goal.depth >= MAX_DEPTH - 1;

  return (
    <div className="space-y-3">
      <a
        href={`/circles/${circleId}`}
        className="inline-flex items-center gap-1.5 text-[0.8125rem] text-[var(--ink-muted)] no-underline transition-colors hover:text-[var(--ink)]"
      >
        <span aria-hidden="true">←</span> The plan
      </a>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <h1 className="display max-w-[40ch] text-[1.375rem] font-[650] leading-snug text-[var(--ink)]">
          {goal.title}
        </h1>

        {canAccept && !goal.accepted_at && goal.status !== "abandoned" && (
          <Button
            variant="primary"
            disabled={busy}
            title={goal.acceptance_condition ?? "Sign this work off as done."}
            onClick={async () => {
              setBusy(true);
              setError(null);
              try {
                await api.post(`/goals/${goal.id}/accept`);
                onChanged();
              } catch (e) {
                setError(e);
              } finally {
                setBusy(false);
              }
            }}
          >
            {busy ? "Accepting…" : "Accept"}
          </Button>
        )}
      </div>

      {goal.description && (
        <p className="max-w-[70ch] text-[0.875rem] leading-relaxed text-[var(--ink-muted)]">
          {goal.description}
        </p>
      )}

      <div className="flex flex-wrap items-center gap-x-3 gap-y-2 text-[0.8125rem]">
        <StatusChip goal={goal} />

        <span className="text-[var(--ink-muted)]">
          {goal.owner?.name ?? <span className="text-[var(--ink-faint)]">Unassigned</span>}
        </span>

        {goal.responsible_party && (
          <span
            className="rounded-[var(--r-chip)] bg-[var(--paper-sunk)] px-2 py-0.5 text-xs font-[560] text-[var(--ink-muted)]"
            title={`${goal.responsible_party.label} is responsible for this, as the ${goal.responsible_party.role}.`}
          >
            {goal.responsible_party.label}
          </span>
        )}

        <span
          className={
            goal.is_overdue ? "font-[560] text-[var(--signal)]" : "text-[var(--ink-faint)]"
          }
        >
          {goal.due_at ? `${formatDate(goal.due_at)} · ${relativeDays(goal.due_at)}` : "No date"}
        </span>

        <span
          className="tabular text-[var(--ink-muted)]"
          title={
            goal.progress_is_derived
              ? "Averaged from this job's sub-jobs."
              : "Reported by whoever owns it."
          }
        >
          {goal.progress}%{goal.progress_is_derived && " avg"}
        </span>
      </div>

      {goal.acceptance_condition && !goal.accepted_at && (
        <p className="max-w-[70ch] rounded-[var(--r-control)] bg-[var(--paper-raised)] px-3.5 py-2.5 text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
          <span className="label">Done when</span>
          <span className="ml-1.5">{goal.acceptance_condition}</span>
        </p>
      )}

      {!goal.acceptance_condition && !goal.accepted_at && (
        <p className="text-xs text-[var(--ink-faint)]">
          No acceptance condition set, so “done” is whatever the owner says it is.
        </p>
      )}

      {goal.accepted_at && (
        <p className="text-[0.8125rem] text-[var(--settled)]">
          Accepted by {goal.accepted_by ?? "—"} · {formatDate(goal.accepted_at, true)}
        </p>
      )}

      {(editable || canCreate) && (
        <div className="flex flex-wrap items-center gap-1.5">
          {editable && (
            <Button variant="quiet" onClick={() => toggle("edit")}>
              {form === "edit" ? "Cancel" : "Edit"}
            </Button>
          )}
          {editable && (
            <Button variant="quiet" onClick={() => toggle("date")}>
              {form === "date" ? "Cancel" : "Move date"}
            </Button>
          )}
          {canCreate && !atDepthLimit && (
            <Button variant="quiet" onClick={() => toggle("child")}>
              {form === "child" ? "Cancel" : "Add sub-job"}
            </Button>
          )}
        </div>
      )}

      {canCreate && atDepthLimit && (
        <p className="text-xs text-[var(--ink-faint)]">
          This is as deep as the plan goes. Anything smaller belongs in a commitment, under
          Record.
        </p>
      )}

      {form === "edit" && (
        <GoalEditForm
          goal={goal}
          parties={parties}
          members={members}
          onDone={closeAndReload}
          onCancel={() => setForm("none")}
        />
      )}

      {form === "date" && (
        <RescheduleForm
          goal={goal}
          parties={parties}
          onDone={closeAndReload}
          onCancel={() => setForm("none")}
        />
      )}

      {form === "child" && (
        <GoalComposer
          circleId={circleId}
          parentId={goal.id}
          parties={parties}
          members={members}
          onDone={closeAndReload}
          onCancel={() => setForm("none")}
        />
      )}

      {!!error && <ErrorNote error={error} />}
    </div>
  );
}

function StatusChip({ goal }: { goal: Goal }) {
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

/** The work underneath this, each its own destination. */
function SubJobs({ circleId, goal }: { circleId: string; goal: Goal }) {
  const children = goal.children ?? [];

  return (
    <Panel
      title="Underneath this"
      meta={<Meta>{children.length} sub-job{children.length === 1 ? "" : "s"}</Meta>}
    >
      <ul>
        {children.map((child) => (
          <li key={child.id} className="border-t border-[var(--rule)] first:border-t-0">
            <a
              href={`/circles/${circleId}/jobs/${child.id}`}
              className="flex items-center gap-3 px-5 py-2.5 no-underline transition-colors hover:bg-[var(--paper-sunk)]"
            >
              <span className="min-w-0 flex-1 truncate text-[0.875rem] text-[var(--ink)]">
                {child.title}
              </span>
              <span className="shrink-0 text-xs text-[var(--ink-faint)]">
                {child.owner?.name ?? child.responsible_party?.label ?? "unassigned"}
              </span>
              <span
                className={`tabular w-24 shrink-0 text-right text-xs ${
                  child.is_overdue ? "font-[600] text-[var(--signal)]" : "text-[var(--ink-faint)]"
                }`}
              >
                {child.due_at ? relativeDays(child.due_at) : "no date"}
              </span>
            </a>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

/**
 * What this job has put on the record.
 *
 * The same three renderers the Record tab uses, narrowed to this node. Filing
 * from here does not ask which part of the plan it belongs to, because the
 * answer is the page.
 */
function JobRecord({
  circleId,
  goalId,
  circle,
}: {
  circleId: string;
  goalId: string;
  circle: Circle | null;
}) {
  const [kind, setKind] = useState<"commitments" | "decisions" | "claims">("commitments");
  const perms = circle?.my_access?.permissions ?? [];
  const closed = circle?.is_closed === true;
  const may = (p: string) => perms.includes(p) && !closed;

  const goals = useGoals(circleId);
  const tree = goals.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-1">
        {(["commitments", "decisions", "claims"] as const).map((k) => (
          <button
            key={k}
            type="button"
            onClick={() => setKind(k)}
            aria-current={kind === k ? "page" : undefined}
            className={`rounded-[var(--r-control)] px-3 py-1.5 text-[0.8125rem] capitalize transition-colors ${
              kind === k
                ? "bg-[var(--paper-sunk)] font-[590] text-[var(--ink)]"
                : "font-[450] text-[var(--ink-muted)] hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)]"
            }`}
          >
            {k}
          </button>
        ))}
      </div>

      {kind === "commitments" && (
        <CommitmentsBody
          circleId={circleId}
          canCreate={may("commitment.create")}
          canUpdate={may("commitment.update")}
          goals={tree}
          goalId={goalId}
        />
      )}

      {kind === "decisions" && (
        <DecisionsBody
          circleId={circleId}
          canCreate={may("decision.create")}
          canApprove={may("decision.approve")}
          goals={tree}
          goalId={goalId}
        />
      )}

      {kind === "claims" && (
        <ClaimsBody
          circleId={circleId}
          canCreate={may("claim.create")}
          canReview={may("claim.review")}
          goals={tree}
          goalId={goalId}
        />
      )}
    </div>
  );
}

/**
 * What has moved, and what is being proposed.
 *
 * Three things a person reviewing a job needs and previously had to assemble
 * from three screens: the dates that have already moved and whether the party
 * they affect ever agreed, the branches with a revision of this node in flight,
 * and the node's own trail from the audit chain.
 *
 * The branch section deliberately shows only the changes that touch this job.
 * A forty-change branch rendered in full here is how somebody approves a diff
 * without reading the row that concerned them.
 */
function JobChanges({
  circleId,
  goal,
  canUpdate,
  onChanged,
}: {
  circleId: string;
  goal: Goal;
  canUpdate: boolean;
  onChanged: () => void;
}) {
  const branches = useAsync<Branch[]>(
    () =>
      api
        .get<{ data: Branch[] }>(`/circles/${circleId}/branches?goal=${goal.id}`)
        .then((r) => r.data),
    [circleId, goal.id],
  );

  const history = useAsync<AuditEventRow[]>(
    () =>
      api
        .get<{ data: AuditEventRow[] }>(
          `/circles/${circleId}/history?resource_id=${goal.id}&limit=50`,
        )
        .then((r) => r.data),
    [circleId, goal.id],
  );

  const moves = goal.schedule_changes ?? [];
  const inFlight = (branches.data ?? []).filter(
    (b) => b.status !== "merged" && b.status !== "withdrawn",
  );

  return (
    <div className="space-y-4">
      <Panel
        title="Proposed"
        meta={
          <Meta>
            {inFlight.length} branch{inFlight.length === 1 ? "" : "es"} touching this job
          </Meta>
        }
      >
        {branches.loading ? (
          <Loading what="branches" />
        ) : inFlight.length === 0 ? (
          <Empty>
            No revision of this job is in flight. Changes to the plan are
            proposed on a branch and reviewed against the tree.
          </Empty>
        ) : (
          <ul>
            {inFlight.map((branch) => (
              <li
                key={branch.id}
                className="border-t border-[var(--rule)] px-5 py-3 first:border-t-0"
              >
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                  <span className="text-[0.875rem] font-[590] text-[var(--ink)]">
                    {branch.name}
                  </span>
                  <span className="text-xs capitalize text-[var(--ink-faint)]">
                    {branch.status}
                  </span>
                  {branch.party && (
                    <span className="text-xs text-[var(--ink-muted)]">{branch.party}</span>
                  )}
                  {branch.awaiting.length > 0 && (
                    <span className="text-xs font-[560] text-[var(--signal)]">
                      awaiting {branch.awaiting.map((p) => p.label).join(", ")}
                    </span>
                  )}
                  <a
                    href={`/circles/${circleId}/tree`}
                    className="ml-auto text-xs text-[var(--accent)] no-underline hover:underline"
                  >
                    review against the tree
                  </a>
                </div>

                <ul className="mt-1.5 space-y-1">
                  {(branch.changes ?? []).map((change) => (
                    <li key={change.id} className="text-[0.8125rem] text-[var(--ink-muted)]">
                      <span className="mono mr-1.5 text-xs text-[var(--derived)]">
                        {change.change_type === "add"
                          ? "+"
                          : change.change_type === "remove"
                            ? "−"
                            : "~"}
                      </span>
                      {change.summary}
                      {change.reason && (
                        <span className="text-[var(--ink-faint)]"> — {change.reason}</span>
                      )}
                    </li>
                  ))}
                </ul>
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <Panel
        title="Dates that moved"
        meta={<Meta>{moves.length}</Meta>}
        tone={moves.some((m) => m.awaiting_agreement) ? "signal" : "default"}
      >
        {moves.length === 0 ? (
          <Empty>This job&rsquo;s date has never moved.</Empty>
        ) : (
          <ul>
            {moves.map((move) => (
              <li
                key={move.id}
                className="border-t border-[var(--rule)] px-5 py-3 first:border-t-0"
              >
                <p className="text-[0.8125rem] text-[var(--ink)]">
                  {formatDate(move.from_due_at)} &rarr;{" "}
                  <span className="font-[590]">{formatDate(move.to_due_at)}</span>
                  {move.days_moved !== null && (
                    <span className="ml-1.5 text-[var(--ink-faint)]">
                      ({move.days_moved > 0 ? "+" : ""}
                      {move.days_moved} days)
                    </span>
                  )}
                </p>
                {move.reason && (
                  <p className="mt-0.5 text-[0.8125rem] text-[var(--ink-muted)]">{move.reason}</p>
                )}
                <p className="mt-0.5 text-xs text-[var(--ink-faint)]">
                  {move.changed_by ?? "—"} · {formatDate(move.created_at, true)}
                </p>

                {move.awaiting_agreement ? (
                  <div className="mt-1.5 flex flex-wrap items-center gap-2">
                    <span className="text-xs font-[560] text-[var(--signal)]">
                      Awaiting {move.requires_party ?? "agreement"}
                    </span>
                    {canUpdate && (
                      <Button
                        variant="quiet"
                        onClick={async () => {
                          await api.post(`/schedule-changes/${move.id}/agree`);
                          onChanged();
                        }}
                      >
                        Agree to it
                      </Button>
                    )}
                  </div>
                ) : (
                  move.requires_party && (
                    <p className="mt-1 text-xs text-[var(--settled)]">
                      Agreed by {move.requires_party} · {formatDate(move.agreed_at)}
                    </p>
                  )
                )}
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <Panel title="This job&rsquo;s trail" meta={<Meta>{(history.data ?? []).length} events</Meta>}>
        {history.loading ? (
          <Loading what="history" />
        ) : (history.data ?? []).length === 0 ? (
          <Empty>Nothing recorded against this job yet.</Empty>
        ) : (
          <ul>
            {(history.data ?? []).map((event) => (
              <li
                key={event.id}
                className="flex flex-wrap items-baseline gap-x-3 border-t border-[var(--rule)] px-5 py-2 first:border-t-0"
              >
                <span className="min-w-0 flex-1 text-[0.8125rem] text-[var(--ink)]">
                  {event.summary}
                </span>
                <span className="shrink-0 text-xs text-[var(--ink-faint)]">
                  {formatDate(event.occurred_at, true)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </div>
  );
}
