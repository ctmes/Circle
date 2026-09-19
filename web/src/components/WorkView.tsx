import { useState } from "react";
import {
  api,
  formatDate,
  relativeDays,
  type Branch,
  type Circle,
  type Goal,
  type Party,
  type Member,
} from "../lib/api";
import { BranchBar } from "./BranchBar";
import { BranchEditor } from "./BranchEditor";
import { CircleFrame } from "./CircleFrame";
import { GoalComposer, GoalEditForm, RescheduleForm } from "./GoalForms";
import { GoalTree, statsFor } from "./GoalTree";
import { Discussion } from "./Thread";
import { Button, Empty, ErrorNote, Loading, Panel, useAsync } from "./ui";

/**
 * Work — the goal tree, and the view the product is now organised around.
 *
 * The old "Now" screen reported state: what was blocked, what was pending. It
 * could not say what the mission *is*, because nothing in the model held that.
 * This does, and everything else — commitments, decisions, claims, discussion —
 * hangs off a node in it.
 *
 * The tree is drawn as a tree — rows, rules, twisties — rather than as nested
 * cards, because a plan is a shape and a shape has to be seen rather than
 * reassembled from indentation. GoalTree owns that drawing; this file owns what
 * happens when you open a node.
 *
 * Depth is capped in configuration rather than here. Four levels is the default
 * because principal → package → contractor → subcontractor is four before
 * anyone has padded anything.
 */
export function WorkView({ circleId }: { circleId: string }) {
  return (
    <CircleFrame circleId={circleId} tab="tree">
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
  const canBranch = perms.includes("goal.branch") && !circle?.is_closed;
  const canMerge = perms.includes("goal.merge") && !circle?.is_closed;
  // Filing a document against a node changes that node's record and puts a new
  // item in the vault, so it takes both rights rather than either.
  const canFile = canUpdate && perms.includes("resource.upload");

  const [composingUnder, setComposingUnder] = useState<string | null | false>(false);
  const [selected, setSelected] = useState<string | null>(null);
  const [branchId, setBranchId] = useState<string | null>(null);

  // Mirrors config('circle.goals.max_depth'). Only used to hide an "add" button
  // the API would refuse anyway — the cap itself lives on the server.
  const maxDepth = 4;

  // The tree is re-fetched whenever the branch changes, never overlaid on the
  // client. An overlay computed here would drift from the server's view of the
  // same branch the moment main moved, and the difference between those two is
  // exactly what somebody would be approving.
  const { data, error, loading, reload } = useAsync<Goal[]>(
    () =>
      api
        .get<{ data: Goal[] }>(
          `/circles/${circleId}/goals${branchId ? `?branch=${branchId}` : ""}`,
        )
        .then((r) => r.data),
    [circleId, branchId],
  );

  const branches = useAsync<Branch[]>(
    () => api.get<{ data: Branch[] }>(`/circles/${circleId}/branches`).then((r) => r.data),
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
  const stats = statsFor(goals);

  const activeBranch = (branches.data ?? []).find((b) => b.id === branchId) ?? null;

  // Comes from the Circle rather than being guessed out of the member list:
  // only the server knows which of those rows is the caller.
  const myParties = circle?.my_access?.party ? [circle.my_access.party.label] : [];

  function reloadAll() {
    reload();
    branches.reload();
  }

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
          {stats.total} goal{stats.total === 1 ? "" : "s"}
          {stats.maxDepth > 0 && `, ${stats.maxDepth + 1} levels deep`}
        </span>
        {stats.overdue > 0 && (
          <span className="text-[0.8125rem] font-[560] text-[var(--signal)]">
            {stats.overdue} overdue
          </span>
        )}
        {stats.unowned > 0 && (
          <span
            className="text-[0.8125rem] text-[var(--ink-muted)]"
            title="Work that hasn't been assigned to a person or a company."
          >
            {stats.unowned} unassigned
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

      {/*
        The branch bar sits above the tree rather than on a screen of its own.
        The only useful way to review a proposed change to a plan is against the
        plan; a diff elsewhere means holding the tree in your head while reading
        it, which is exactly what people get wrong.
      */}
      {(canBranch || (branches.data ?? []).some((b) => b.status !== "merged")) && (
        <BranchBar
          circleId={circleId}
          branches={branches.data ?? []}
          active={activeBranch}
          onSelect={(id) => {
            setBranchId(id);
            setSelected(null);
          }}
          onChanged={reloadAll}
          canBranch={canBranch}
          canMerge={canMerge}
          myParties={myParties}
        />
      )}

      {goals.length === 0 ? (
        <Panel>
          <Empty>
            Nothing planned yet. A goal is something specific that someone owns,
            with a date on it. Start with the two or three that decide whether
            this succeeds, then break each one down.
          </Empty>
        </Panel>
      ) : (
        <GoalTree
          circleId={circleId}
          goals={goals}
          selectedId={selected}
          onSelect={setSelected}
          // Every row takes a drop. The tree is where people already look for a
          // piece of work, so it is where the drawing for it should be able to
          // land — the alternative is the vault, three screens away, which is
          // how a Circle ends up with 400 files and a plan pointing at none.
          fileDrop={{ canFile, onFiled: reloadAll }}
          renderDetail={(goal) => (
            <GoalDetail
              goal={goal}
              circleId={circleId}
              branch={activeBranch}
              parties={parties ?? []}
              members={members ?? []}
              canCreate={canCreate && goal.depth < maxDepth - 1}
              canUpdate={canUpdate}
              canAccept={canAccept}
              canComment={canComment}
              canBranch={canBranch}
              atDepthLimit={goal.depth >= maxDepth - 1}
              onChanged={reloadAll}
            />
          )}
        />
      )}
    </div>
  );
}

/**
 * What opens when you click a row.
 *
 * Everything that was spread across a card and a sub-row is here instead: the
 * acceptance condition, who owes it, the actions, the discussion. The tree row
 * answers "what state is this in"; this answers "what is it, and what do I do
 * about it" — and only for the one node you asked about, which is why the tree
 * above it stays legible at four levels.
 */
function GoalDetail({
  goal,
  circleId,
  branch,
  parties,
  members,
  canCreate,
  canUpdate,
  canAccept,
  canComment,
  canBranch,
  atDepthLimit,
  onChanged,
}: {
  goal: Goal;
  circleId: string;
  /** Set when the tree is being viewed on a branch. Edits stage, not apply. */
  branch: Branch | null;
  parties: Party[];
  members: Member[];
  canCreate: boolean;
  canUpdate: boolean;
  canAccept: boolean;
  canComment: boolean;
  canBranch: boolean;
  atDepthLimit: boolean;
  onChanged: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [editing, setEditing] = useState(false);
  const [rescheduling, setRescheduling] = useState(false);
  const [addingChild, setAddingChild] = useState(false);
  const [showDiscussion, setShowDiscussion] = useState(false);

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
    <div className="space-y-3 py-3.5 pr-4">
      {goal.description && (
        <p className="max-w-[70ch] text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
          {goal.description}
        </p>
      )}

      {/*
        The responsible party sits next to the owner rather than instead of it.
        In cross-company work "Sam" is not an answer to who owes this — Sam's
        company is, and Sam might leave.
      */}
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[0.8125rem]">
        <GoalStatusChip goal={goal} />

        {/*
          The way through to everything this panel has no room for: the files
          filed against it, the whole conversation, what has been put on the
          record, what a branch is proposing for it.
        */}
        {goal.id !== null && (
          <a
            href={`/circles/${circleId}/jobs/${goal.id}`}
            className="font-[560] text-[var(--accent)] no-underline hover:underline"
          >
            Open this job
          </a>
        )}

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

        <ProgressPip goal={goal} />
      </div>

      {goal.acceptance_condition && !goal.accepted_at && (
        <p className="max-w-[70ch] rounded-[var(--r-control)] bg-[var(--paper-raised)] px-3 py-2 text-xs leading-relaxed text-[var(--ink-muted)]">
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
        <p className="text-xs text-[var(--settled)]">
          Accepted by {goal.accepted_by ?? "—"} · {formatDate(goal.accepted_at, true)}
        </p>
      )}

      {!!error && <ErrorNote error={error} />}

      {/*
        On a branch the same controls stage rather than apply, and acceptance
        disappears entirely: accepting work is a statement about what has been
        done, not a proposal about what should be. Putting it on a branch would
        mean signing off on a completion that has not happened yet.
      */}
      {branch !== null && branch.status !== "merged" ? (
        <BranchEditor
          goal={goal}
          branch={branch}
          parties={parties}
          members={members}
          canBranch={canBranch}
          canCreate={canCreate}
          onChanged={onChanged}
        />
      ) : (
        <div className="flex flex-wrap items-center gap-1.5">
          {canUpdate && !goal.accepted_at && goal.id !== null && (
            <Button variant="quiet" disabled={busy} onClick={() => setEditing((v) => !v)}>
              {editing ? "Cancel" : "Edit"}
            </Button>
          )}

          {canUpdate && !goal.accepted_at && (
            <Button variant="quiet" disabled={busy} onClick={() => setRescheduling((v) => !v)}>
              {rescheduling ? "Cancel" : "Move date"}
            </Button>
          )}

          {canCreate && (
            <Button variant="quiet" disabled={busy} onClick={() => setAddingChild((v) => !v)}>
              {addingChild ? "Cancel" : "Add sub-goal"}
            </Button>
          )}

          {goal.id !== null && (
            <Button variant="quiet" onClick={() => setShowDiscussion((v) => !v)}>
              {showDiscussion ? "Hide discussion" : "Discussion"}
            </Button>
          )}

          {/*
            Acceptance is a button rather than a status dropdown, because "met"
            is reached by someone signing it off — the API refuses the shortcut,
            and offering one here would only produce a 422 nobody expected.
          */}
          {canAccept && !goal.accepted_at && goal.status !== "abandoned" && (
            <Button
              variant="primary"
              disabled={busy}
              title={goal.acceptance_condition ?? "Sign this work off as done."}
              onClick={() => act(() => api.post(`/goals/${goal.id}/accept`))}
            >
              Accept
            </Button>
          )}
        </div>
      )}

      {/*
        On its own line rather than wedged between the buttons, where it read as
        a disabled control. It is an explanation of an absent button, not one.
      */}
      {atDepthLimit && (
        <p className="text-xs text-[var(--ink-faint)]">
          This is as deep as the tree goes. Anything smaller belongs in a
          commitment, which is where a single piece of work lives.
        </p>
      )}

      {editing && (
        <GoalEditForm
          goal={goal}
          parties={parties}
          members={members}
          onDone={() => {
            setEditing(false);
            onChanged();
          }}
          onCancel={() => setEditing(false)}
        />
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

      {addingChild && (
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
      )}

      {/*
        A node a branch proposes adding has nothing to discuss yet — it does not
        exist, so a thread against it would have no subject to hang on. The
        conversation about whether it should exist belongs on the branch.
      */}
      {showDiscussion && goal.id !== null && (
        <div className="max-w-[80ch]">
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
          : "Reported by whoever owns it."
      }
    >
      {goal.progress}%
      {goal.progress_is_derived && (
        <span className="ml-1 text-xs font-[450] text-[var(--ink-faint)]">avg</span>
      )}
    </span>
  );
}
