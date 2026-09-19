import { useEffect, useMemo, useState } from "react";
import {
  api,
  formatDate,
  relativeDays,
  type AgentActionRow,
  type Circle,
  type Goal,
  type Member,
  type MentionRow,
  type Overview,
  type Party,
  type Thread,
  type ThreadSubject,
} from "../lib/api";
import { BranchDiagram, hueFor, stroke } from "./BranchDiagram";
import { GoalTree } from "./GoalTree";
import { useJobDrop } from "./JobDrop";
import { Discussion } from "./Thread";
import { CircleFrame } from "./CircleFrame";
import {
  Button,
  Empty,
  ErrorNote,
  Loading,
  Meta,
  Panel,
  inputClass,
  useAsync,
  useRevalidate,
} from "./ui";

/**
 * The main screen: the branch diagram, and everything that hangs off it.
 *
 * The diagram is the page. Above it, one line for whatever is waiting on the
 * reader — the only thing here that is about them rather than about the
 * mission. Below it, the panels that used to be tabs: the conversation, the
 * room, and what is late.
 *
 * Selecting a branch or a dot narrows the panels to that node rather than
 * opening anything. That is the whole reason the tabs could collapse: once the
 * plan is an index, "tell me about X" is a selection, not a destination.
 */
export function MainView({ circleId }: { circleId: string }) {
  return (
    <CircleFrame circleId={circleId} tab="">
      {(circle) => <Body circleId={circleId} circle={circle} />}
    </CircleFrame>
  );
}

function Body({ circleId, circle }: { circleId: string; circle: Circle | null }) {
  const [selected, setSelected] = useState<string | null>(null);
  const [shape, setShape] = useShape(circleId);

  const goals = useAsync<Goal[]>(
    () => api.get<{ data: Goal[] }>(`/circles/${circleId}/goals`).then((r) => r.data),
    [circleId],
  );
  const overview = useAsync<Overview>(
    () => api.get<{ data: Overview }>(`/circles/${circleId}/overview`).then((r) => r.data),
    [circleId],
  );
  // One call carries both the thread list and the reader's unread mentions.
  const inbox = useAsync<{ data: Thread[]; mentions: MentionRow[] }>(
    () => api.get<{ data: Thread[]; mentions: MentionRow[] }>(`/circles/${circleId}/inbox`),
    [circleId],
  );
  const members = useAsync<Member[]>(
    () =>
      api
        .get<{ data: { members: Member[] } }>(`/circles/${circleId}/members`)
        .then((r) => r.data.members),
    [circleId],
  );
  const queue = useAsync<{ data: AgentActionRow[] }>(
    () => api.get<{ data: AgentActionRow[] }>(`/circles/${circleId}/agent-actions`),
    [circleId],
  );
  // Answerability in this product is a company as often as a person, so fixing
  // an unassigned goal has to be able to name either.
  const parties = useAsync<Party[]>(
    () => api.get<{ data: Party[] }>(`/circles/${circleId}/parties`).then((r) => r.data),
    [circleId],
  );

  /*
    The plan changes from places this page never hears about — the job's own
    screen in another tab, the Tree, another company, an agent — so it is
    re-read rather than trusted from the first load. The strip and the worklist
    come with it: a diagram that has moved on while "waiting on you" has not is
    a page contradicting itself.
  */
  useRevalidate(() => {
    goals.reload();
    overview.reload();
    inbox.reload();
    queue.reload();
  });

  const tree = goals.data ?? [];
  const node = useMemo(() => findGoal(tree, selected), [tree, selected]);

  const canApprove =
    circle?.my_access?.permissions.includes("agent.approve") === true && !circle.is_closed;

  // Filing a document against a node changes that node's record and puts a new
  // item in the vault, so it takes both rights rather than either.
  const canFile =
    circle?.my_access?.permissions.includes("goal.update") === true &&
    circle.my_access.permissions.includes("resource.upload") &&
    !circle.is_closed;

  if (goals.loading) {
    return (
      <Panel>
        <Loading what="the plan" />
      </Panel>
    );
  }
  // Only a failure with nothing to show stops the page. A background re-read
  // that fails keeps the plan it already has rather than trading it for an error.
  if (goals.error && !goals.data) return <ErrorNote error={goals.error} />;

  return (
    <div className="space-y-4">
      <WaitingStrip
        circleId={circleId}
        mentions={inbox.data?.mentions ?? []}
        actions={canApprove ? queue.data?.data ?? [] : []}
        next={overview.data?.next_decision ?? null}
        onRead={inbox.reload}
      />

      {tree.length === 0 ? (
        <Panel>
          <Empty>
            Nothing planned yet. A goal is something specific that someone owns,
            with a date on it. Add two or three and the diagram draws itself.
          </Empty>
        </Panel>
      ) : (
        <>
          <ShapeSwitch shape={shape} onChange={setShape} />

          {shape === "diagram" ? (
            <>
              <Panel>
                <div className="px-3 pb-2 pt-1">
                  <BranchDiagram
                    goals={tree}
                    circle={circle}
                    selectedId={selected}
                    onSelect={(id) => setSelected((cur) => (cur === id ? null : id))}
                  />
                </div>
              </Panel>

              <Legend />

              <Jobs
                circleId={circleId}
                goals={tree}
                selectedId={selected}
                canFile={canFile}
                onFiled={goals.reload}
                onPick={(id) => setSelected((cur) => (cur === id ? null : id))}
              />
            </>
          ) : (
            /*
              The same component the Tree tab draws, with the same remembered
              collapse state, so the two are one view rather than two that
              resemble each other. What it does not carry is that screen's
              editing surface — the composers and the branch bar stay there,
              because Main is for reading the plan and answering what is on it.
            */
            <GoalTree
              circleId={circleId}
              goals={tree}
              selectedId={selected}
              onSelect={setSelected}
              fileDrop={{ canFile, onFiled: goals.reload }}
              renderDetail={(goal) => <JobSummary goal={goal} circleId={circleId} />}
            />
          )}
        </>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <Talk
            circleId={circleId}
            threads={inbox.data?.data ?? []}
            loading={inbox.loading}
            node={node}
            goals={tree}
            canComment={
              circle?.my_access?.permissions.includes("comment.create") === true &&
              !circle.is_closed
            }
            onPick={setSelected}
          />
        </div>
        <div className="space-y-4">
          <Room members={members.data ?? []} loading={members.loading} />
          <NeedsAttention
            circleId={circleId}
            goals={tree}
            overview={overview.data ?? null}
            members={members.data ?? []}
            parties={parties.data ?? []}
            canUpdate={
              circle?.my_access?.permissions.includes("goal.update") === true &&
              !circle.is_closed
            }
            selectedId={selected}
            onPick={setSelected}
            onFixed={goals.reload}
          />
        </div>
      </div>
    </div>
  );
}

// -------------------------------------------------------------------- shape

/** Which drawing of the plan is on screen. */
type Shape = "diagram" | "tree";

/**
 * Which one, remembered per Circle.
 *
 * Per Circle rather than globally because the right answer genuinely differs:
 * a plan of eight jobs over a quarter is a picture, and a plan of sixty across
 * four subcontractors is a list you scan. Somebody who has decided that for one
 * mission should not have to decide it again every morning.
 *
 * Guarded on both sides — a private window or a browser blocking site data
 * throws on access rather than returning empty, and losing the plan because a
 * preference could not be read would be a poor trade for remembering it.
 */
function useShape(circleId: string): [Shape, (next: Shape) => void] {
  const key = `circle.main.shape.${circleId}`;

  /*
    Read after mount, not during render. The server has no localStorage, so
    seeding state from it directly makes the first client render disagree with
    the markup React is hydrating — which React resolves by throwing the page
    away and drawing it again.
  */
  const [shape, setShape] = useState<Shape>("diagram");

  useEffect(() => {
    try {
      const saved = localStorage.getItem(key);
      if (saved === "tree" || saved === "diagram") setShape(saved);
    } catch {
      /* A preference is a convenience, never a requirement. */
    }
  }, [key]);

  return [
    shape,
    (next: Shape) => {
      setShape(next);
      try {
        localStorage.setItem(key, next);
      } catch {
        /* As above. */
      }
    },
  ];
}

/**
 * Two drawings of one plan.
 *
 * The diagram answers "what is the shape of this, and who is behind" at a
 * glance and carries no text inside the plot. The tree answers "what, exactly,
 * are all the things" and can be collapsed branch by branch. Neither is a
 * better version of the other, which is why this is a switch rather than a
 * decision somebody made once on everybody's behalf.
 */
function ShapeSwitch({ shape, onChange }: { shape: Shape; onChange: (next: Shape) => void }) {
  const options: Array<{ key: Shape; label: string; hint: string }> = [
    { key: "diagram", label: "Diagram", hint: "The shape of the plan over time." },
    { key: "tree", label: "Tree", hint: "Every job, collapsible branch by branch." },
  ];

  return (
    <div className="flex items-center gap-2 px-1">
      <div
        role="group"
        aria-label="How to draw the plan"
        className="inline-flex rounded-[var(--r-control)] bg-[var(--paper-sunk)] p-0.5"
      >
        {options.map((option) => (
          <button
            key={option.key}
            type="button"
            onClick={() => onChange(option.key)}
            aria-pressed={shape === option.key}
            title={option.hint}
            className={`rounded-[calc(var(--r-control)-2px)] px-3 py-1 text-[0.8125rem] transition-colors ${
              shape === option.key
                ? "bg-[var(--paper-raised)] font-[590] text-[var(--ink)] shadow-[0_1px_2px_rgb(0_0_0/0.08)]"
                : "font-[450] text-[var(--ink-muted)] hover:text-[var(--ink)]"
            }`}
          >
            {option.label}
          </button>
        ))}
      </div>

      <span className="text-xs text-[var(--ink-faint)]">
        {options.find((o) => o.key === shape)!.hint}
      </span>
    </div>
  );
}

// -------------------------------------------------------------------- strip

/**
 * The one thing on the page about the reader.
 *
 * A strip rather than a stack of cards: six panels above the diagram would push
 * the shape of the work below the fold, which is exactly what the old "Now"
 * screen did wrong.
 */
function WaitingStrip({
  circleId,
  mentions,
  actions,
  next,
  onRead,
}: {
  circleId: string;
  mentions: MentionRow[];
  actions: AgentActionRow[];
  next: Overview["next_decision"];
  onRead: () => void;
}) {
  const total = mentions.length + actions.length + (next ? 1 : 0);

  if (total === 0) {
    return (
      <p className="px-1 text-[0.8125rem] text-[var(--ink-faint)]">
        Nothing is waiting on you.
      </p>
    );
  }

  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[var(--r-panel)] border border-[var(--signal)]/30 bg-[var(--signal-soft)] px-4 py-2.5 text-[0.8125rem]">
      <span className="font-[600] text-[var(--ink)]">{total} waiting on you</span>

      {mentions.length > 0 && (
        <>
          <span className="text-[var(--ink-muted)]">
            {mentions.length} mention{mentions.length === 1 ? "" : "s"}
          </span>
          <button
            onClick={async () => {
              await api.post(`/circles/${circleId}/mentions/read`);
              onRead();
            }}
            className="text-[var(--accent)] hover:underline"
          >
            mark read
          </button>
        </>
      )}

      {actions.length > 0 && (
        <a
          href={`/circles/${circleId}/agents`}
          className="text-[var(--accent)] no-underline hover:underline"
        >
          {actions.length} agent action{actions.length === 1 ? "" : "s"} to decide
        </a>
      )}

      {next && (
        <a
          href={`/circles/${circleId}/record`}
          className="text-[var(--accent)] no-underline hover:underline"
        >
          {next.title}
        </a>
      )}
    </div>
  );
}

/** Four marks and a line. Without it the dot states are unguessable. */
function Legend() {
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-1 text-[0.72rem] text-[var(--ink-faint)]">
      <Key><Ring filled /> accepted</Key>
      <Key><Ring core /> under way</Key>
      <Key><Ring /> not started</Key>
      <Key><Ring overdue /> overdue</Key>
      <Key><Ring dashed /> no date</Key>
      <Key>
        <span className="inline-block h-[3px] w-5 rounded-full bg-[var(--ink-muted)]" /> progress
      </Key>
    </div>
  );
}

function Key({ children }: { children: React.ReactNode }) {
  return <span className="flex items-center gap-1.5">{children}</span>;
}

function Ring({
  filled,
  core,
  overdue,
  dashed,
}: {
  filled?: boolean;
  core?: boolean;
  overdue?: boolean;
  dashed?: boolean;
}) {
  return (
    <span
      className={`inline-flex h-2.5 w-2.5 items-center justify-center rounded-full border-2 ${
        overdue ? "border-[var(--signal)]" : "border-[var(--ink-muted)]"
      } ${filled ? "bg-[var(--ink-muted)]" : ""} ${dashed ? "border-dashed" : ""}`}
    >
      {core && <span className="h-[3px] w-[3px] rounded-full bg-[var(--ink-muted)]" />}
    </span>
  );
}

// ------------------------------------------------------------------- panels

/**
 * Every job in the plan, under the diagram that draws it.
 *
 * The diagram answers "what is the shape of this and who is behind" at a
 * glance, and deliberately carries no text inside the plot — which leaves it
 * unable to answer "what, exactly, are all the things". This is that list: one
 * ruled row per goal, indented to its depth, in the same order the diagram
 * stacks its branches.
 *
 * It is the same selection as the diagram, both ways. Clicking a row highlights
 * the node above and scopes the discussion below; clicking a dot opens the row.
 * The row a person opens expands in place with what the diagram cannot show —
 * the description, the acceptance condition, what is filed against it — so the
 * detail never needs a panel of its own.
 */
function Jobs({
  circleId,
  goals,
  selectedId,
  canFile,
  onFiled,
  onPick,
}: {
  circleId: string;
  goals: Goal[];
  selectedId: string | null;
  canFile: boolean;
  onFiled: () => void;
  onPick: (goalId: string | null) => void;
}) {
  const rows = useMemo(() => flatten(goals).filter((g) => g.id !== null), [goals]);

  if (rows.length === 0) return null;

  const done = rows.filter((g) => g.accepted_at !== null).length;

  return (
    <Panel
      title="The work"
      meta={
        <Meta>
          {rows.length} job{rows.length === 1 ? "" : "s"} · {done} accepted
        </Meta>
      }
    >
      <ul>
        {rows.map((g) => (
          <JobRow
            key={g.id}
            goal={g}
            circleId={circleId}
            open={g.id === selectedId}
            canFile={canFile}
            onFiled={onFiled}
            onPick={onPick}
          />
        ))}
      </ul>
    </Panel>
  );
}

function JobRow({
  goal,
  circleId,
  open,
  canFile,
  onFiled,
  onPick,
}: {
  goal: Goal;
  circleId: string;
  open: boolean;
  canFile: boolean;
  onFiled: () => void;
  onPick: (goalId: string | null) => void;
}) {
  const hue = hueFor(goal.responsible_party?.id ?? goal.owner?.id ?? goal.id);

  const drop = useJobDrop({
    circleId,
    goalId: goal.id,
    enabled: canFile,
    onFiled,
  });

  return (
    <li
      {...drop.dropProps}
      className={`group border-t border-[var(--rule)] first:border-t-0 ${
        drop.dragging ? "bg-[var(--accent-soft)] shadow-[inset_0_0_0_2px_var(--accent)]" : ""
      }`}
    >
      <button
        type="button"
        onClick={() => onPick(goal.id)}
        aria-expanded={open}
        className={`flex w-full items-center gap-3 px-5 py-2 text-left transition-colors hover:bg-[var(--paper-sunk)] ${
          open && !drop.dragging ? "bg-[var(--paper-sunk)]" : ""
        }`}
      >
        {/* Indent carries the hierarchy, exactly as the diagram's gutter does. */}
        <span style={{ width: goal.depth * 14 }} className="shrink-0" aria-hidden="true" />

        <StateDot goal={goal} hue={hue} />

        <span
          className={`min-w-0 flex-1 truncate text-[0.875rem] ${
            goal.accepted_at
              ? "text-[var(--ink-muted)]"
              : goal.depth === 0
                ? "font-[570] text-[var(--ink)]"
                : "text-[var(--ink)]"
          }`}
        >
          {goal.title}
        </span>

        {(drop.dragging || drop.busy) && (
          <span className="shrink-0 rounded-[var(--r-chip)] bg-[var(--accent)] px-1.5 py-0.5 text-[0.6875rem] font-[600] text-white">
            {drop.busy
              ? drop.progress
                ? `filing ${drop.progress.done}/${drop.progress.total}`
                : "filing…"
              : "file it here"}
          </span>
        )}

        <span className="hidden w-36 shrink-0 truncate text-right text-xs text-[var(--ink-faint)] sm:block">
          {goal.owner?.name ?? goal.responsible_party?.label ?? "unassigned"}
        </span>

        <span
          className={`w-24 shrink-0 text-right text-xs tabular ${
            goal.is_overdue ? "font-[600] text-[var(--signal)]" : "text-[var(--ink-faint)]"
          }`}
        >
          {goal.due_at ? relativeDays(goal.due_at) : "no date"}
        </span>

        <span className="flex w-16 shrink-0 items-center justify-end gap-1.5">
          <span className="block h-1 w-8 overflow-hidden rounded-full bg-[var(--paper-sunk)]">
            <span
              className="block h-full rounded-full"
              style={{ width: `${Math.max(goal.progress, 2)}%`, background: stroke(hue) }}
            />
          </span>
          <span className="tabular w-7 text-right text-xs text-[var(--ink-faint)]">
            {goal.progress}%
          </span>
        </span>
      </button>

      {!!drop.error && (
        <div className="px-5 pb-3">
          <ErrorNote error={drop.error} />
        </div>
      )}

      {open && (
        <div className="bg-[var(--paper-sunk)] px-5 pb-3.5 pt-0.5">
          <JobSummary goal={goal} circleId={circleId} />
        </div>
      )}
    </li>
  );
}

/**
 * What a job is, in the space an opened row has.
 *
 * Shared by the work list and the tree, because a job opened in one should not
 * describe itself differently from the same job opened in the other — and
 * because the last line of it is the way through to the job's own screen,
 * which is the only place the rest of it lives.
 */
function JobSummary({ goal, circleId }: { goal: Goal; circleId: string }) {
  return (
    <div className="space-y-2 py-2">
      {goal.description && (
        <p className="max-w-[70ch] text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
          {goal.description}
        </p>
      )}

      <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs">
        {goal.responsible_party && (
          <PartyChip id={goal.responsible_party.id} label={goal.responsible_party.label} />
        )}
        <span className="text-[var(--ink-faint)]">
          {goal.due_at ? formatDate(goal.due_at) : "No date set"}
        </span>
        {goal.progress_is_derived && (
          <span className="text-[var(--ink-faint)]">progress derived from the work below</span>
        )}
        <span className="text-[var(--ink-faint)]">
          {goal.counts.commitments} commitments · {goal.counts.decisions} decisions ·{" "}
          {goal.counts.claims} claims
        </span>
      </div>

      {goal.acceptance_condition && !goal.accepted_at && (
        <p className="max-w-[70ch] rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3 py-2 text-xs leading-relaxed text-[var(--ink-muted)]">
          <span className="label">Done when</span>
          <span className="ml-1.5">{goal.acceptance_condition}</span>
        </p>
      )}

      {goal.accepted_at && (
        <p className="text-xs text-[var(--settled)]">
          Accepted by {goal.accepted_by ?? "—"} · {formatDate(goal.accepted_at, true)}
        </p>
      )}

      {goal.id !== null && (
        <a
          href={`/circles/${circleId}/jobs/${goal.id}`}
          className="inline-block text-xs font-[560] text-[var(--accent)] no-underline hover:underline"
        >
          Open this job — files, discussion, record and changes
        </a>
      )}
    </div>
  );
}

/** The same four states the diagram draws, at list scale. */
function StateDot({ goal, hue }: { goal: Goal; hue: number }) {
  const done = goal.accepted_at !== null || goal.status === "met";
  const colour = stroke(hue);

  return (
    <span
      aria-hidden="true"
      className="flex h-3 w-3 shrink-0 items-center justify-center rounded-full border-2"
      style={{
        borderColor: goal.is_overdue ? "var(--signal)" : colour,
        background: done ? colour : "transparent",
        borderStyle: goal.due_at ? "solid" : "dashed",
      }}
    >
      {!done && goal.progress > 0 && (
        <span
          className="h-1 w-1 rounded-full"
          style={{ background: colour }}
        />
      )}
    </span>
  );
}

/**
 * The conversation, in full, on the main screen.
 *
 * It was a read-only list of thread titles, which made the panel a signpost
 * pointing at the tab it was supposed to replace. This is the real component —
 * the same one the Discussion tab renders — so opening a thread, replying,
 * mentioning somebody, marking a comment for the record, sharing a party thread
 * with the Circle and resolving it all work here.
 *
 * What it is scoped to follows the diagram. Nothing selected means the general
 * room; select a branch or a dot and the panel becomes that goal's thread, which
 * is the behaviour the plan-as-index was for. Conversations on anything else are
 * listed underneath so they are still one click away.
 */
function Talk({
  circleId,
  threads,
  loading,
  node,
  goals,
  canComment,
  onPick,
}: {
  circleId: string;
  threads: Thread[];
  loading: boolean;
  node: Goal | null;
  goals: Goal[];
  canComment: boolean;
  onPick: (goalId: string | null) => void;
}) {
  const subject: { type: ThreadSubject; id: string } =
    node?.id ? { type: "goal", id: node.id } : { type: "circle", id: circleId };

  /*
    A thread carries its subject's id but not its name. The plan is already
    loaded on this page, so the name is a lookup rather than a request.
  */
  const titles = useMemo(() => {
    const m = new Map<string, string>();
    for (const g of flatten(goals)) if (g.id) m.set(g.id, g.title);
    return m;
  }, [goals]);

  // Everything that is not what the panel is currently showing.
  const elsewhere = threads.filter(
    (t) => !(t.subject.type === subject.type && t.subject.id === subject.id),
  );

  return (
    <Panel
      title={node ? node.title : "Discussion"}
      meta={
        node ? (
          <button
            onClick={() => onPick(null)}
            className="text-xs text-[var(--accent)] hover:underline"
          >
            back to the general room
          </button>
        ) : (
          <span className="text-xs text-[var(--ink-faint)]">
            the general room — not attached to anything yet
          </span>
        )
      }
    >
      <div className="space-y-5 px-5 pb-5 pt-1">
        {loading ? (
          <Loading what="discussion" />
        ) : (
          <Discussion circleId={circleId} subject={subject} canComment={canComment} />
        )}

        {elsewhere.length > 0 && (
          <div className="border-t border-[var(--rule)] pt-3">
            <p className="label">Elsewhere in this Circle</p>
            <ul className="mt-2 space-y-1">
              {elsewhere.slice(0, 6).map((t) => (
                <li key={t.id}>
                  <ThreadJump
                    thread={t}
                    circleId={circleId}
                    title={
                      t.title ??
                      (t.subject.type === "goal"
                        ? titles.get(t.subject.id) ?? "a goal"
                        : `on a ${t.subject.type.replace(/_/g, " ")}`)
                    }
                    onPick={onPick}
                  />
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </Panel>
  );
}

/**
 * One row in "elsewhere".
 *
 * A thread on a goal is a selection — it moves the diagram and re-scopes this
 * panel without a page load. A thread on a claim, decision or commitment is a
 * different screen, so it is an honest link rather than a button that quietly
 * does nothing.
 */
function ThreadJump({
  thread,
  circleId,
  title,
  onPick,
}: {
  thread: Thread;
  circleId: string;
  title: string;
  onPick: (goalId: string | null) => void;
}) {
  const meta = (
    <span className="ml-2 shrink-0 text-xs text-[var(--ink-faint)]">
      {thread.comment_count}
      {thread.visibility === "party" && ` · ${thread.party ?? "private"}`}
    </span>
  );

  const label = (
    <>
      <span className="min-w-0 flex-1 truncate">{title}</span>
      {meta}
    </>
  );

  const shell =
    "flex w-full items-baseline rounded-[var(--r-control)] px-1.5 py-1 text-left text-[0.8125rem] text-[var(--ink-muted)] no-underline transition-colors hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)]";

  if (thread.subject.type === "goal") {
    return (
      <button type="button" onClick={() => onPick(thread.subject.id)} className={shell}>
        {label}
      </button>
    );
  }

  return (
    <a
      href={`/circles/${circleId}/${
        thread.subject.type === "evidence_item" ? "context" : "record"
      }`}
      className={shell}
    >
      {label}
    </a>
  );
}

/** Who is in the room, coloured by the company they sit in. */
function Room({ members, loading }: { members: Member[]; loading: boolean }) {
  const active = members.filter((m) => m.is_active);

  return (
    <Panel title="In the room" meta={<span className="text-xs text-[var(--ink-faint)]">{active.length}</span>}>
      {loading ? (
        <Loading what="the room" />
      ) : (
        <ul className="space-y-2 px-5 pb-5 pt-1">
          {active.map((m) => (
            <li key={m.membership_id} className="flex items-center gap-2.5">
              <Avatar name={m.user.name} partyId={m.party?.id ?? null} />
              <span className="min-w-0 flex-1 truncate text-[0.8125rem] text-[var(--ink)]">
                {m.user.name ?? m.user.email}
              </span>
              <span className="shrink-0 text-xs capitalize text-[var(--ink-faint)]">
                {m.circle_role}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

/**
 * Initials on a disc in the party's colour.
 *
 * A photograph would answer "who is this". In cross-company work the question a
 * face has to answer is "whose side are they on", and the company colour does
 * that at sixteen pixels where a photograph does not.
 */
function Avatar({ name, partyId }: { name: string | null; partyId: string | null }) {
  const hue = hueFor(partyId);
  const initials = (name ?? "—")
    .replace(/\(.*?\)/g, "")
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? "")
    .join("");

  return (
    <span
      className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[0.625rem] font-[640] text-white"
      style={{ background: stroke(hue) }}
      aria-hidden="true"
    >
      {initials}
    </span>
  );
}

function PartyChip({ id, label }: { id: string; label: string }) {
  const hue = hueFor(id);
  return (
    <span
      className="rounded-[var(--r-chip)] px-2 py-0.5 text-xs font-[560]"
      style={{ background: stroke(hue, 0.14), color: stroke(hue) }}
    >
      {label}
    </span>
  );
}

/**
 * Needs attention — a worklist, not a tally.
 *
 * It used to end on the line "2 goals aren't assigned to anyone", which is a
 * fact you can do nothing with: it does not say which two, and knowing the
 * number changes nothing about your afternoon. Every group now opens, every row
 * selects that goal on the diagram, and the one problem with a one-field answer
 * — nobody is answerable for this — is fixed in place rather than by going to
 * another screen to do it.
 *
 * Order is by how much the problem blocks: overdue first, then work nobody owns,
 * then work with no date at all, then the commitments underneath.
 */
function NeedsAttention({
  circleId,
  goals,
  overview,
  members,
  parties,
  canUpdate,
  selectedId,
  onPick,
  onFixed,
}: {
  circleId: string;
  goals: Goal[];
  overview: Overview | null;
  members: Member[];
  parties: Party[];
  canUpdate: boolean;
  selectedId: string | null;
  onPick: (goalId: string | null) => void;
  onFixed: () => void;
}) {
  const all = flatten(goals).filter((g) => g.id !== null);
  const overdue = all.filter((g) => g.is_overdue);
  const unowned = all.filter((g) => !g.owner && !g.responsible_party);
  // A goal with no date cannot be placed on the axis at all — the diagram parks
  // it past the end rather than inventing a position for it.
  const undated = all.filter((g) => !g.due_at && !g.accepted_at);
  const stuck = (overview?.commitments ?? []).filter(
    (c) => c.overdue || c.status === "blocked",
  );

  const groups = [
    { key: "overdue", label: "overdue", items: overdue },
    { key: "unowned", label: "not assigned to anyone", items: unowned },
    { key: "undated", label: "with no date", items: undated },
  ].filter((g) => g.items.length > 0);

  // The first real problem is open on arrival. A column of shut rows is the
  // same tally in a different shape.
  const [open, setOpen] = useState<string | null>(groups[0]?.key ?? null);

  if (groups.length === 0 && stuck.length === 0) {
    return (
      <Panel title="Needs attention">
        <Empty>Nothing is late, unassigned or undated.</Empty>
      </Panel>
    );
  }

  return (
    <Panel title="Needs attention" tone="signal">
      <div className="pb-2">
        {groups.map((g) => (
          <div key={g.key} className="border-t border-[var(--rule)] first:border-t-0">
            <button
              type="button"
              onClick={() => setOpen((cur) => (cur === g.key ? null : g.key))}
              aria-expanded={open === g.key}
              className="flex w-full items-center gap-2 px-5 py-2.5 text-left text-[0.8125rem] transition-colors hover:bg-[var(--paper-sunk)]"
            >
              <Chevron open={open === g.key} />
              <span className="font-[600] text-[var(--signal)]">{g.items.length}</span>
              <span className="text-[var(--ink-muted)]">
                {g.items.length === 1 ? "goal" : "goals"} {g.label}
              </span>
            </button>

            {open === g.key && (
              <ul className="space-y-0.5 pb-2 pl-5 pr-3">
                {g.items.map((goal) => (
                  <li key={goal.id}>
                    <GoalRow
                      goal={goal}
                      kind={g.key}
                      selected={goal.id === selectedId}
                      onPick={onPick}
                    />
                    {g.key === "unowned" && canUpdate && goal.id === selectedId && (
                      <AssignBox
                        goalId={goal.id!}
                        members={members}
                        parties={parties}
                        onDone={onFixed}
                      />
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>
        ))}

        {stuck.length > 0 && (
          <div className="border-t border-[var(--rule)] px-5 pt-2.5">
            <a
              href={`/circles/${circleId}/record`}
              className="text-[0.8125rem] text-[var(--accent)] no-underline hover:underline"
            >
              {stuck.length} commitment{stuck.length === 1 ? "" : "s"} blocked or overdue
            </a>
          </div>
        )}
      </div>
    </Panel>
  );
}

/** One problem. Clicking it selects that node everywhere else on the page. */
function GoalRow({
  goal,
  kind,
  selected,
  onPick,
}: {
  goal: Goal;
  kind: string;
  selected: boolean;
  onPick: (goalId: string | null) => void;
}) {
  return (
    <button
      type="button"
      onClick={() => onPick(selected ? null : goal.id)}
      className={`flex w-full items-baseline gap-2 rounded-[var(--r-control)] px-2 py-1 text-left text-[0.8125rem] transition-colors hover:bg-[var(--paper-sunk)] ${
        selected ? "bg-[var(--paper-sunk)]" : ""
      }`}
    >
      <span className="min-w-0 flex-1 truncate text-[var(--ink)]">{goal.title}</span>
      {kind === "overdue" && goal.due_at && (
        <span className="shrink-0 text-xs font-[560] text-[var(--signal)]">
          {relativeDays(goal.due_at)}
        </span>
      )}
      {kind === "unowned" && (
        <span className="shrink-0 text-xs text-[var(--ink-faint)]">
          {selected ? "assign below" : "assign"}
        </span>
      )}
    </button>
  );
}

/**
 * Naming someone answerable, in place.
 *
 * One select rather than two, because "who owes this" has one answer even
 * though it may be a person or a company — which of the two it is decides which
 * field the PATCH sets, and that is this component's business rather than the
 * reader's.
 */
function AssignBox({
  goalId,
  members,
  parties,
  onDone,
}: {
  goalId: string;
  members: Member[];
  parties: Party[];
  onDone: () => void;
}) {
  const [choice, setChoice] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  async function save() {
    if (!choice) return;
    const [kind, id] = choice.split(":");
    setBusy(true);
    setError(null);
    try {
      await api.patch(`/goals/${goalId}`,
        kind === "user" ? { owner_user_id: id } : { responsible_party_id: id },
      );
      onDone();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="mb-1.5 ml-2 mt-1 space-y-2 rounded-[var(--r-control)] bg-[var(--paper-inset)] p-2.5">
      <div className="flex gap-2">
        <select
          value={choice}
          onChange={(e) => setChoice(e.target.value)}
          className={`${inputClass} !py-1 !text-[0.8125rem]`}
          aria-label="Who is answerable for this"
        >
          <option value="">choose someone…</option>
          {members.filter((m) => m.is_active).length > 0 && (
            <optgroup label="People">
              {members
                .filter((m) => m.is_active)
                .map((m) => (
                  <option key={m.user.id} value={`user:${m.user.id}`}>
                    {m.user.name ?? m.user.email}
                  </option>
                ))}
            </optgroup>
          )}
          {parties.length > 0 && (
            <optgroup label="Companies">
              {parties.map((party) => (
                <option key={party.id} value={`party:${party.id}`}>
                  {party.label}
                </option>
              ))}
            </optgroup>
          )}
        </select>

        <Button variant="primary" onClick={save} disabled={busy || !choice}>
          {busy ? "…" : "Assign"}
        </Button>
      </div>

      {!!error && <ErrorNote error={error} />}
    </div>
  );
}

function Chevron({ open }: { open: boolean }) {
  return (
    <svg
      viewBox="0 0 12 12"
      aria-hidden="true"
      className={`h-3 w-3 shrink-0 text-[var(--ink-faint)] transition-transform duration-150 ${
        open ? "rotate-90" : ""
      }`}
      fill="none"
      stroke="currentColor"
      strokeWidth="1.75"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M4.5 2.5 L8 6 L4.5 9.5" />
    </svg>
  );
}

// -------------------------------------------------------------------- utils

function flatten(goals: Goal[]): Goal[] {
  return goals.flatMap((g) => [g, ...flatten(g.children ?? [])]);
}

function findGoal(goals: Goal[], id: string | null): Goal | null {
  if (id === null) return null;
  for (const g of goals) {
    if (g.id === id) return g;
    const hit = findGoal(g.children ?? [], id);
    if (hit) return hit;
  }
  return null;
}
