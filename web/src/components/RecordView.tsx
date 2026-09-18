import { useState } from "react";
import type { Circle } from "../lib/api";
import { CircleFrame } from "./CircleFrame";
import { ClaimsBody } from "./ClaimsView";
import { CommitmentsBody } from "./CommitmentsView";
import { DecisionsBody } from "./DecisionsView";
import { useGoals } from "./GoalPicker";

/**
 * The record — what the mission has settled, in one place.
 *
 * Claims, decisions and commitments had a tab each. Three peer destinations in
 * a bar of twelve, for three collections that answer one question between them:
 * what has this Circle actually put on the record? They are different shapes —
 * a claim carries citations, a decision binds to an exact version, a commitment
 * carries status updates — so each keeps its own renderer. What they stop
 * having is a separate address.
 *
 * The three old routes still resolve here, each opening on its own segment, so
 * every link already written into the product and into people's bookmarks lands
 * where it used to.
 */

const KINDS = [
  { key: "commitments", label: "Commitments" },
  { key: "decisions", label: "Decisions" },
  { key: "claims", label: "Claims" },
] as const;

export type RecordKind = (typeof KINDS)[number]["key"];

export function RecordView({
  circleId,
  initialKind = "commitments",
}: {
  circleId: string;
  initialKind?: RecordKind;
}) {
  const [kind, setKind] = useState<RecordKind>(initialKind);

  return (
    <CircleFrame circleId={circleId} tab="record">
      {(circle) => (
        <div className="space-y-5">
          <nav
            className="flex flex-wrap gap-1 border-b border-[var(--rule)]"
            aria-label="Record sections"
          >
            {KINDS.map((k) => (
              <button
                key={k.key}
                type="button"
                onClick={() => {
                  setKind(k.key);
                  /*
                    Keep the address honest without a navigation. Someone who
                    reloads, or copies the URL out of the bar mid-review, gets
                    the segment they were looking at rather than the default.
                  */
                  window.history.replaceState({}, "", `/circles/${circleId}/${k.key}`);
                }}
                aria-current={kind === k.key ? "page" : undefined}
                className={`-mb-px border-b-2 px-3 py-2 text-[0.8125rem] transition-colors ${
                  kind === k.key
                    ? "border-[var(--ink)] font-medium text-[var(--ink)]"
                    : "border-transparent text-[var(--ink-faint)] hover:text-[var(--ink-soft)]"
                }`}
              >
                {k.label}
              </button>
            ))}
          </nav>

          <Section circleId={circleId} circle={circle} kind={kind} />
        </div>
      )}
    </CircleFrame>
  );
}

function Section({
  circleId,
  circle,
  kind,
}: {
  circleId: string;
  circle: Circle | null;
  kind: RecordKind;
}) {
  const perms = circle?.my_access?.permissions ?? [];
  const closed = circle?.is_closed === true;
  const may = (p: string) => perms.includes(p) && !closed;

  /*
    The plan is fetched once here rather than inside each composer. All three
    need it for the same control, and a person filing three records against the
    same goal should not pay for the tree three times.
  */
  const goals = useGoals(circleId);
  const tree = goals.data ?? [];

  if (kind === "decisions") {
    return (
      <DecisionsBody
        circleId={circleId}
        canCreate={may("decision.create")}
        canApprove={may("decision.approve")}
        goals={tree}
      />
    );
  }

  if (kind === "claims") {
    return (
      <ClaimsBody
        circleId={circleId}
        canCreate={may("claim.create")}
        canReview={may("claim.review")}
        goals={tree}
      />
    );
  }

  return (
    <CommitmentsBody
      circleId={circleId}
      canCreate={may("commitment.create")}
      canUpdate={may("commitment.update")}
      goals={tree}
    />
  );
}
