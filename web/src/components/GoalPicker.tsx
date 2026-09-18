import { api, type Goal } from "../lib/api";
import { Field, inputClass, useAsync } from "./ui";

/**
 * Filing a record against a node of the plan.
 *
 * §20.2 calls the goal tree the spine, and the schema has agreed since it
 * landed: `commitments`, `decisions` and `claims` all carry a nullable
 * `goal_id`, and the tree counts them. Nothing a person could do ever filled
 * it — the commitment endpoint validated the id and then dropped it, the other
 * two never accepted one — so every count pip on every tree was zero unless an
 * agent had written the row. The spine was decorative.
 *
 * This is the control that ends that. One select, in every composer, defaulting
 * to nothing: attaching is the normal case but not a required one, because a
 * good deal of what a Circle records really is about the mission at large.
 */

/** Depth-first, so a select reads in the same order as the tree it mirrors. */
export function flattenGoals(goals: Goal[]): Goal[] {
  return goals.flatMap((g) => [g, ...flattenGoals(g.children ?? [])]);
}

/**
 * The plan, for anything that needs to point at part of it.
 *
 * Shared by the three composers, so the tree is fetched once per view rather
 * than once per open composer.
 */
export function useGoals(circleId: string) {
  return useAsync<Goal[]>(
    () => api.get<{ data: Goal[] }>(`/circles/${circleId}/goals`).then((r) => r.data),
    [circleId],
  );
}

/**
 * The select itself.
 *
 * Nodes a branch only proposes are excluded: they have no id, so there is
 * nothing to file against until the branch merges.
 */
export function GoalField({
  goals,
  value,
  onChange,
  hint = "What part of the plan is this about? Leave it empty if it is about the mission at large.",
}: {
  goals: Goal[];
  value: string;
  onChange: (goalId: string) => void;
  hint?: string;
}) {
  const flat = flattenGoals(goals).filter((g) => g.id !== null);

  // A Circle with no tree has nothing to file against, and an empty select
  // reads as something broken rather than as something absent.
  if (flat.length === 0) return null;

  return (
    <Field label="Part of the plan" hint={hint}>
      <select value={value} onChange={(e) => onChange(e.target.value)} className={inputClass}>
        <option value="">the mission at large</option>
        {flat.map((g) => (
          <option key={g.id} value={g.id!}>
            {/* Figure space, so the indent survives a select element. */}
            {" ".repeat(g.depth * 3)}
            {g.title}
          </option>
        ))}
      </select>
    </Field>
  );
}

/**
 * Where a record says which goal it belongs to.
 *
 * It links into the tree rather than just naming it: from a merged record list
 * the next question is almost always "what else is on that goal", and the plan
 * is where that is answered.
 */
export function GoalChip({
  goal,
  circleId,
}: {
  goal: { id: string; title: string | null } | null;
  circleId: string;
}) {
  if (!goal) return null;

  return (
    <a
      href={`/circles/${circleId}#${goal.id}`}
      title="Open this goal in the plan"
      className="rounded-[var(--r-chip)] bg-[var(--paper-sunk)] px-2 py-0.5 text-xs font-[560] text-[var(--ink-muted)] no-underline transition-colors hover:text-[var(--ink)]"
    >
      {goal.title ?? "a goal"}
    </a>
  );
}
