import { useCallback, useEffect, useMemo, useState } from "react";
import { formatDate, relativeDays, type Goal } from "../lib/api";

/**
 * The goal tree, drawn as a tree.
 *
 * It used to be nested cards two levels deep, which is a structure you can read
 * but not *see*. A plan is a shape — where the depth is, which branch carries
 * the risk, which company owns which limb — and a shape has to be looked at,
 * not reconstructed from indentation you have to measure by eye.
 *
 * So: one row per goal, rules connecting parents to children, and a twisty that
 * collapses a branch. Everything a file tree does, because a file tree is the
 * interface people already know for exactly this.
 *
 * Two departures from a file tree, both because this is a plan rather than a
 * directory.
 *
 * Rows carry their state on the right — party, progress, date — at a fixed
 * column, so scanning down the tree answers "what is late and whose is it"
 * without opening anything. A directory listing has nothing worth putting
 * there; a plan has the only two questions anybody asks.
 *
 * A collapsed branch still reports its subtree. Collapsing a folder hides its
 * contents and that is correct; collapsing a goal must not hide that three
 * things under it are overdue, or collapsing becomes a way to make bad news
 * disappear.
 */

/** How far each level steps in. Enough to read, tight enough that four levels fit. */
const INDENT = 22;

/** "14 Sep" — short enough that a before and an after fit one column. */
function shortDate(iso: string | null | undefined): string {
  if (!iso) return "none";
  return new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short" });
}

export interface TreeStats {
  total: number;
  overdue: number;
  unowned: number;
  maxDepth: number;
}

/** Everything under a node, including the node. */
function subtreeOf(goal: Goal): Goal[] {
  return [goal, ...(goal.children ?? []).flatMap(subtreeOf)];
}

export function statsFor(goals: Goal[]): TreeStats {
  const all = goals.flatMap(subtreeOf);

  return {
    total: all.length,
    overdue: all.filter((g) => g.is_overdue).length,
    unowned: all.filter((g) => g.owner === null && g.responsible_party === null).length,
    maxDepth: all.reduce((d, g) => Math.max(d, g.depth), 0),
  };
}

/**
 * Which branches are shut, remembered per Circle.
 *
 * Stored rather than reset on every visit because a plan is something people
 * come back to daily, and re-collapsing the four branches you do not own is
 * the kind of small tax that stops someone opening a screen at all.
 *
 * Every read and write is guarded: an artifact viewer in a private window, or
 * a browser set to block site data, throws on access rather than returning
 * empty, and a tree that fails to render because of a preference is worse than
 * one that forgets.
 */
function useCollapsed(circleId: string) {
  const key = `circle.tree.collapsed.${circleId}`;

  const [collapsed, setCollapsed] = useState<Set<string>>(() => {
    try {
      const raw = localStorage.getItem(key);
      return new Set<string>(raw ? JSON.parse(raw) : []);
    } catch {
      return new Set<string>();
    }
  });

  useEffect(() => {
    try {
      localStorage.setItem(key, JSON.stringify([...collapsed]));
    } catch {
      /* Storage is a convenience here, never a requirement. */
    }
  }, [key, collapsed]);

  const toggle = useCallback((id: string) => {
    setCollapsed((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }, []);

  return { collapsed, toggle, setCollapsed };
}

export function GoalTree({
  circleId,
  goals,
  selectedId,
  onSelect,
  renderDetail,
}: {
  circleId: string;
  goals: Goal[];
  selectedId: string | null;
  onSelect: (id: string | null) => void;
  renderDetail: (goal: Goal) => React.ReactNode;
}) {
  const { collapsed, toggle, setCollapsed } = useCollapsed(circleId);

  const allParentIds = useMemo(
    () =>
      goals
        .flatMap(subtreeOf)
        .filter((g) => (g.children ?? []).length > 0)
        .map((g) => g.id)
        // A node a branch proposes adding has no id, so it has nothing to
        // remember a collapsed state under. It renders open, which is right:
        // the whole reason it is on screen is to be read.
        .filter((id): id is string => id !== null),
    [goals],
  );

  const allOpen = collapsed.size === 0;

  return (
    <div className="rounded-[var(--r-panel)] border border-[var(--rule)] bg-[var(--paper-raised)]">
      <div className="flex items-center gap-3 border-b border-[var(--rule)] px-3 py-1.5">
        <button
          onClick={() => setCollapsed(allOpen ? new Set(allParentIds) : new Set())}
          className="rounded-[var(--r-control)] px-2 py-1 text-xs text-[var(--ink-muted)] transition-colors hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)]"
          disabled={allParentIds.length === 0}
        >
          {allOpen ? "Collapse all" : "Expand all"}
        </button>

        {/* Widths mirror the row's columns exactly; a header a few pixels off
            its column reads as a rendering bug rather than a heading. */}
        <span className="ml-auto flex items-center gap-2 pr-3 text-[0.6875rem] uppercase tracking-[0.06em] text-[var(--ink-faint)]">
          <span className="hidden w-28 text-right sm:inline">Answerable</span>
          <span className="w-14 text-right">Progress</span>
          <span className="w-24 text-right">Due</span>
        </span>
      </div>

      <ul role="tree" className="py-1">
        {goals.map((goal, i) => (
          <GoalBranch
            key={goal.id ?? `add:${goal.branch?.change_id}`}
            goal={goal}
            depth={0}
            isLast={i === goals.length - 1}
            guides={[]}
            collapsed={collapsed}
            toggle={toggle}
            selectedId={selectedId}
            onSelect={onSelect}
            renderDetail={renderDetail}
          />
        ))}
      </ul>
    </div>
  );
}

function GoalBranch({
  goal,
  depth,
  isLast,
  guides,
  collapsed,
  toggle,
  selectedId,
  onSelect,
  renderDetail,
}: {
  goal: Goal;
  depth: number;
  isLast: boolean;
  /** For each ancestor level, whether a vertical rule continues past this row. */
  guides: boolean[];
  collapsed: Set<string>;
  toggle: (id: string) => void;
  selectedId: string | null;
  onSelect: (id: string | null) => void;
  renderDetail: (goal: Goal) => React.ReactNode;
}) {
  const children = goal.children ?? [];
  const hasChildren = children.length > 0;
  const isOpen = hasChildren && !(goal.id !== null && collapsed.has(goal.id));
  const isSelected = selectedId === goal.id;

  // A shut branch still reports what is under it, so collapsing cannot be used
  // to make a late subtree disappear.
  const buried = useMemo(
    () =>
      hasChildren && !isOpen
        ? children.flatMap(subtreeOf).filter((g) => g.is_overdue).length
        : 0,
    [children, hasChildren, isOpen],
  );

  return (
    <li
      role="treeitem"
      aria-expanded={hasChildren ? isOpen : undefined}
      // The target of the chip a record carries. Null only for a node a branch
      // proposes adding, which nothing can be filed against yet.
      id={goal.id ?? undefined}
    >
      <div
        onClick={() => onSelect(goal.id === null ? null : isSelected ? null : goal.id)}
        className={`group relative flex cursor-pointer items-center gap-2 pr-3 transition-colors ${
          isSelected
            ? "bg-[var(--paper-sunk)]"
            : goal.branch
              ? "bg-[color-mix(in_srgb,var(--derived)_7%,transparent)] hover:bg-[color-mix(in_srgb,var(--derived)_12%,transparent)]"
              : "hover:bg-[var(--paper-inset)]"
        }`}
      >
        {/* Ancestor rules. Drawn as absolutely positioned hairlines rather than
            borders on nested divs, so a row is one flex line at any depth. */}
        {guides.map((continues, level) =>
          continues ? (
            <span
              key={level}
              aria-hidden="true"
              className="absolute top-0 h-full w-px bg-[var(--rule)]"
              style={{ left: level * INDENT + 15 }}
            />
          ) : null,
        )}

        {/* This row's own elbow into its parent. */}
        {depth > 0 && (
          <>
            <span
              aria-hidden="true"
              className="absolute top-0 w-px bg-[var(--rule)]"
              style={{
                left: (depth - 1) * INDENT + 15,
                height: isLast ? "50%" : "100%",
              }}
            />
            <span
              aria-hidden="true"
              className="absolute h-px bg-[var(--rule)]"
              style={{ left: (depth - 1) * INDENT + 15, top: "50%", width: INDENT - 8 }}
            />
          </>
        )}

        <span style={{ width: depth * INDENT }} className="shrink-0" aria-hidden="true" />

        {/* Twisty, or a node marker for a leaf. Both occupy the same box so
            titles line up whether or not a goal has been broken down. */}
        {hasChildren ? (
          <button
            onClick={(e) => {
              e.stopPropagation();
              if (goal.id !== null) toggle(goal.id);
            }}
            aria-label={isOpen ? `Collapse ${goal.title}` : `Expand ${goal.title}`}
            className="z-10 flex h-5 w-5 shrink-0 items-center justify-center rounded-[3px] bg-[var(--paper-raised)] text-[var(--ink-faint)] transition-colors hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)] group-hover:bg-transparent"
          >
            <svg
              viewBox="0 0 12 12"
              className={`h-3 w-3 transition-transform duration-150 ${isOpen ? "rotate-90" : ""}`}
              fill="none"
              stroke="currentColor"
              strokeWidth="1.75"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M4.5 2.5 L8 6 L4.5 9.5" />
            </svg>
          </button>
        ) : (
          // Empty, not a bullet. The state dot beside the title is already this
          // node's marker, and two dots in a row read as one thing misaligned.
          <span aria-hidden="true" className="h-5 w-5 shrink-0" />
        )}

        {goal.branch ? <BranchMark state={goal.branch.state} /> : <StateDot goal={goal} />}

        <span
          className={`min-w-0 flex-1 truncate py-[7px] text-[0.875rem] ${
            goal.branch?.state === "removed"
              ? "text-[var(--ink-faint)] line-through"
              : goal.accepted_at
                ? "text-[var(--ink-muted)]"
                : depth === 0
                  ? "font-[590] text-[var(--ink)]"
                  : "font-[450] text-[var(--ink)]"
          }`}
          title={goal.title}
        >
          {goal.title}
          {/*
            The proposed title inline rather than only in the review panel. A
            rename you can only see by opening something else is a rename people
            approve without reading.
          */}
          {goal.branch?.to?.title != null && goal.branch.to.title !== goal.title && (
            <>
              <span className="mx-1.5 text-[var(--ink-faint)]">→</span>
              <span className="font-[560] text-[var(--derived)]">
                {String(goal.branch.to.title)}
              </span>
            </>
          )}
        </span>

        {buried > 0 && (
          <span
            className="shrink-0 rounded-[var(--r-chip)] bg-[var(--signal-soft)] px-1.5 py-0.5 text-[0.6875rem] font-[560] text-[var(--signal)]"
            title={`${buried} overdue inside this branch, currently collapsed.`}
          >
            {buried} late inside
          </span>
        )}

        {(goal.counts.commitments > 0 ||
          goal.counts.decisions > 0 ||
          goal.counts.claims > 0) && (
          <span
            className="hidden shrink-0 text-[0.6875rem] text-[var(--ink-faint)] md:inline"
            title={`${goal.counts.commitments} commitments · ${goal.counts.decisions} decisions · ${goal.counts.claims} claims`}
          >
            {[goal.counts.commitments, goal.counts.decisions, goal.counts.claims]
              .filter((n) => n > 0)
              .join(" · ")}
          </span>
        )}

        <span
          className="hidden w-28 shrink-0 truncate text-right text-[0.75rem] text-[var(--ink-muted)] sm:inline"
          title={
            goal.responsible_party
              ? `${goal.responsible_party.label} is answerable for this as ${goal.responsible_party.role}.`
              : "No company is answerable for this."
          }
        >
          {goal.responsible_party?.label ?? (
            <span className="text-[var(--ink-faint)]">—</span>
          )}
        </span>

        <span className="w-14 shrink-0 text-right">
          <ProgressBar goal={goal} />
        </span>

        {/*
          A proposed date shows here, next to the one it replaces. Somebody
          being asked to agree to a schedule change must be able to see the
          schedule change without opening anything — a diff you have to go
          looking for is a diff people approve without reading.
        */}
        {goal.branch?.to && "due_at" in goal.branch.to ? (
          // Wider and abbreviated: two dates do not fit the single-date column,
          // and a clipped date is worse than no date on the screen where
          // somebody signs off on the move.
          <span
            className="w-24 shrink-0 truncate text-right text-[0.75rem]"
            title={`${goal.due_at ? formatDate(goal.due_at) : "no date"} → ${
              goal.branch.to.due_at ? formatDate(String(goal.branch.to.due_at)) : "no date"
            }`}
          >
            <span className="text-[var(--ink-faint)] line-through">
              {shortDate(goal.due_at)}
            </span>
            <span className="mx-0.5 text-[var(--ink-faint)]">→</span>
            <span className="font-[560] text-[var(--derived)]">
              {shortDate(goal.branch.to.due_at as string | null)}
            </span>
          </span>
        ) : (
          <span
            className={`w-24 shrink-0 truncate text-right text-[0.75rem] ${
              goal.is_overdue ? "font-[560] text-[var(--signal)]" : "text-[var(--ink-faint)]"
            }`}
            title={goal.due_at ? formatDate(goal.due_at) : undefined}
          >
            {goal.due_at ? relativeDays(goal.due_at) : "—"}
          </span>
        )}
      </div>

      {isSelected && (
        <div
          className="border-y border-[var(--rule)] bg-[var(--paper-inset)]"
          style={{ paddingLeft: depth * INDENT + 34 }}
        >
          {renderDetail(goal)}
        </div>
      )}

      {isOpen && (
        <ul role="group">
          {children.map((child, i) => (
            <GoalBranch
              key={child.id ?? `add:${child.branch?.change_id}`}
              goal={child}
              depth={depth + 1}
              isLast={i === children.length - 1}
              guides={[...guides, !isLast]}
              collapsed={collapsed}
              toggle={toggle}
              selectedId={selectedId}
              onSelect={onSelect}
              renderDetail={renderDetail}
            />
          ))}
        </ul>
      )}
    </li>
  );
}

/**
 * State as one mark rather than a word.
 *
 * A word per row would be four columns of prose down the tree and would drown
 * the titles, which are the thing being scanned. The tooltip carries the word;
 * the detail panel carries the full status.
 */
/**
 * What the branch does to this row, in the slot the state dot normally holds.
 *
 * Same position rather than an extra column: on a branch, "what would happen to
 * this" is the state worth knowing, and adding a mark beside the dot would make
 * every row two symbols wide for the sake of information you already have.
 */
function BranchMark({ state }: { state: "added" | "changed" | "removed" }) {
  const [symbol, colour, label] =
    state === "added"
      ? ["+", "var(--settled)", "Would be added"]
      : state === "removed"
        ? ["−", "var(--signal)", "Would be dropped"]
        : ["~", "var(--derived)", "Would change"];

  return (
    <span
      className="mono flex h-[13px] w-[13px] shrink-0 items-center justify-center rounded-[3px] text-[0.625rem] font-[700] leading-none"
      style={{ background: `color-mix(in srgb, ${colour} 16%, transparent)`, color: colour }}
      title={label}
      aria-label={label}
    >
      {symbol}
    </span>
  );
}

function StateDot({ goal }: { goal: Goal }) {
  const [colour, label] = goal.accepted_at
    ? ["var(--settled)", "Accepted"]
    : goal.is_overdue
      ? ["var(--signal)", "Overdue"]
      : goal.status === "blocked"
        ? ["var(--signal)", "Blocked"]
        : goal.status === "in_review"
          ? ["var(--derived)", "In review"]
          : goal.status === "met"
            ? ["var(--settled)", "Met"]
            : goal.status === "abandoned"
              ? ["var(--ink-faint)", "Abandoned"]
              : ["var(--ink-faint)", "Active"];

  return (
    <span
      className="h-[7px] w-[7px] shrink-0 rounded-full"
      style={{ background: colour }}
      title={label}
      aria-label={label}
    />
  );
}

/**
 * Progress as a bar and a number, with its provenance kept.
 *
 * A parent's figure is the average of its children and is drawn hollow — the
 * distinction between a number somebody typed and one the system worked out is
 * exactly what a rolled-up plan is prone to losing.
 */
function ProgressBar({ goal }: { goal: Goal }) {
  return (
    <span
      className="inline-flex items-center gap-1.5"
      title={
        goal.progress_is_derived
          ? `Averaged from this goal's sub-goals (${goal.progress}%).`
          : `Reported by whoever owns this (${goal.progress}%).`
      }
    >
      <span className="relative hidden h-1 w-6 overflow-hidden rounded-full bg-[var(--rule)] lg:inline-block">
        <span
          className="absolute inset-y-0 left-0 rounded-full"
          style={{
            width: `${Math.max(0, Math.min(100, goal.progress))}%`,
            background: goal.progress_is_derived ? "var(--ink-faint)" : "var(--accent)",
          }}
        />
      </span>
      <span
        className={`tabular text-[0.75rem] ${
          goal.progress_is_derived
            ? "text-[var(--ink-faint)]"
            : "font-[560] text-[var(--ink-muted)]"
        }`}
      >
        {goal.progress}%
      </span>
    </span>
  );
}
