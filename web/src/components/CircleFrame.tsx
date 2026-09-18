import type { ReactNode } from "react";
import { api, clearToken, relativeDays, type Circle } from "../lib/api";
import { CircleMeter, CircleMeterControl } from "./CircleMeter";
import { MissionStatement } from "./MissionStatement";
import { ErrorNote, forgetCached, useAsync } from "./ui";
import { ThemeToggle } from "./ThemeToggle";
import { Wordmark } from "./Wordmark";

/*
  Three groups, in the order the work happens, with a rule between them.

  A flat run of ten peer destinations says nothing about what to do first —
  which was the original complaint about this product. Grouping states it: you
  do the work in the first group, the second is the record it produces, and the
  third is administration. The separators cost nothing and carry the ordering.
*/
const TAB_GROUPS = [
  [
    // The tree lands first. It is the only screen that says what the mission
    // *is*; everything else reports on parts of it, and opening a Circle onto a
    // status summary meant the shape of the work was a click away from being
    // the thing you never looked at.
    //
    // "Plan", not "Work": the chrome above carries a link to /work, which is a
    // different destination entirely, and the two sat a few pixels apart under
    // the same word. The view already calls itself "The plan" in its heading.
    { key: "", label: "Plan" },
    { key: "now", label: "Now" },
    // Sits beside Now rather than with the record, because that is what it is:
    // what is live and unfiled, as against what has been settled. §20.3
    // refused this tab; the answer to its objection is the "file against"
    // control inside it, not the absence of the tab.
    { key: "discussion", label: "Discussion" },
    { key: "agents", label: "Agents" },
    { key: "context", label: "Context" },
  ],
  [
    // Was three tabs. They are three shapes of one thing — what this mission
    // has put on the record — and as peers in the bar they read as three
    // separate places to go and look. The segmented control inside sorts them
    // out in the one place a person is already standing; the old three routes
    // still resolve, each opening on its own segment.
    { key: "record", label: "Record" },
  ],
  [
    { key: "people", label: "People" },
    // Hiring sits with People rather than with the work, because that is what
    // it is: deciding who is in the room. The contracts it writes are what
    // bound them once they are (spec §21.2).
    { key: "hiring", label: "Hiring" },
    { key: "history", label: "History" },
  ],
] as const;

/**
 * The header that frames every Circle page.
 *
 * It answers the two questions the spec's Definition of Done puts first — what
 * is this mission, and when does it end — before anything else on the page, and
 * the tab bar never leaves the screen. Closure and expiry are stated plainly,
 * because a closed Circle behaves differently and people need to know why an
 * action just disappeared.
 */
export function CircleFrame({
  circleId,
  tab,
  children,
}: {
  circleId: string;
  tab: string;
  /**
   * Called with `null` until the Circle arrives.
   *
   * The view underneath fetches on `circleId` alone, so holding it back until
   * the header had its data bought nothing and cost a whole round trip: the
   * two requests now leave together. What the Circle actually decides is which
   * *actions* are offered, so a caller reading permissions off `null` must
   * default to offering none — which is the correct answer while we are still
   * asking.
   */
  children: (circle: Circle | null) => ReactNode;
}) {
  const { data: circle, error, mutate } = useAsync<Circle>(
    () => api.get<{ data: Circle }>(`/circles/${circleId}`).then((r) => r.data),
    [circleId],
    // Identical on every tab, so it is fetched once per browsing context
    // and re-read in the background afterwards.
    { cacheKey: `circle:${circleId}` },
  );

  // Only a failure with nothing to show stops the page. Once the Circle is on
  // screen, a failed re-read is not worth throwing the view away for.
  if (error && !circle) {
    return (
      <div className="mx-auto max-w-3xl p-8">
        <ErrorNote error={error} />
      </div>
    );
  }

  // The header meter and the mission statement are both settable by whoever
  // may already manage this Circle; everyone else reads them. Gating on the
  // permission the PATCH itself requires means the controls are never offered
  // and then refused — and a closed Circle offers neither, because its record
  // is the one it closed with.
  const canManage =
    circle?.my_access?.permissions.includes("circle.manage_members") === true &&
    !circle.is_closed;

  // Building the packet and closing the Circle are end-of-mission acts taken
  // once, by one or two people. As a permanent tab they spent a slot in an
  // already-crowded bar on a screen most members could not act on at all. In
  // the chrome the link is there for whoever holds either permission and
  // absent for everyone else.
  //
  // Not gated on closure: the packet of a closed Circle is the whole point of
  // having one, and ExportView drops the closing half on its own.
  const canWrapUp =
    circle?.my_access?.permissions.some(
      (p) => p === "export.create" || p === "circle.close",
    ) === true;

  return (
    <div className="min-h-screen">
      {/*
        Everything above the content scrolls away except the tab bar, which is
        the only part needed to move between views. Stacking the two sticky
        strips would eat a third of a laptop screen.
      */}
      <header>
        <div className="mx-auto max-w-[1180px] px-6">
          <div className="flex items-center justify-between py-3.5">
            <a href="/circles" className="no-underline">
              <Wordmark size={17} />
            </a>
            <div className="flex items-center gap-1">
              {canWrapUp && (
                <a
                  href={`/circles/${circleId}/export`}
                  aria-current={tab === "export" ? "page" : undefined}
                  className={`rounded-[var(--r-control)] px-2.5 py-1.5 text-[0.8125rem] no-underline transition-colors hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)] ${
                    tab === "export"
                      ? "bg-[var(--paper-sunk)] font-[590] text-[var(--ink)]"
                      : "text-[var(--ink-muted)]"
                  }`}
                >
                  Export
                </a>
              )}
              {/*
                The only destination in the app that is not a Circle (spec
                §21). It sits in the chrome rather than in the tab bar because
                it is not part of this mission — it is where the next one, and
                the record of the last one, live.
              */}
              <a
                href="/work"
                className="rounded-[var(--r-control)] px-2.5 py-1.5 text-[0.8125rem] text-[var(--ink-muted)] no-underline transition-colors hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)]"
              >
                Work
              </a>
              <ThemeToggle />
              <button
                onClick={() => {
                  clearToken();
                  // Whoever signs in next must not be handed this Circle.
                  forgetCached();
                  location.href = "/login";
                }}
                className="rounded-[var(--r-control)] px-2.5 py-1.5 text-[0.8125rem] text-[var(--ink-muted)] transition-colors hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)]"
              >
                Sign out
              </button>
            </div>
          </div>

          {/*
            The mission, at the size the most important sentence deserves.

            It is drawn from the Circle, which may still be in flight — so this
            block reserves its own height rather than letting the tab bar and
            the view below it jump down when the name lands.
          */}
          <div className="max-w-3xl pb-5 pt-2">
            {circle ? (
              <>
                <MissionStatement circle={circle} editable={canManage} onSaved={mutate} />

                {/*
                  The four facts that change how you read every other screen, on
                  one line. They were a bordered table before; as a single run of
                  chips they cost a quarter of the height and scan in one pass.
                */}
                <div className="mt-4 flex flex-wrap items-center gap-x-2.5 gap-y-2 text-[0.8125rem]">
                  {canManage ? (
                    <CircleMeterControl circle={circle} onSaved={mutate} />
                  ) : (
                    <CircleMeter circle={circle} />
                  )}
                  {/* Draft and closing are real states that "open" does not
                      capture, so they are said out loud rather than folded in. */}
                  {!circle.is_closed && circle.status !== "active" && (
                    <span className="rounded-[var(--r-chip)] bg-[var(--paper-sunk)] px-2 py-0.5 text-xs font-[560] capitalize text-[var(--ink-muted)]">
                      {circle.status.replace(/_/g, " ")}
                    </span>
                  )}
                  {circle.is_expired && !circle.is_closed && (
                    <span className="rounded-[var(--r-chip)] bg-[var(--signal-soft)] px-2 py-0.5 text-xs font-[560] text-[var(--signal)]">
                      Expired
                    </span>
                  )}
                  <Divider />
                  <span className="text-[var(--ink-muted)]">
                    {circle.expires_at ? relativeDays(circle.expires_at) : "No deadline"}
                  </span>
                  <Divider />
                  <span className="text-[var(--ink-muted)]">
                    Owner{" "}
                    <span className="font-[560] text-[var(--ink)]">
                      {circle.owner.name ?? "—"}
                    </span>
                  </span>
                  <Divider />
                  <span className="text-[var(--ink-muted)]">
                    You are{" "}
                    <span className="font-[560] capitalize text-[var(--ink)]">
                      {circle.my_role ?? "—"}
                    </span>
                  </span>
                  {circle.my_access?.is_external && (
                    <span
                      className="rounded-[var(--r-chip)] bg-[var(--signal-soft)] px-2 py-0.5 text-xs font-[560] text-[var(--signal)]"
                      title="External collaborators cannot download or share by default, and lose access entirely when the Circle closes."
                    >
                      External
                    </span>
                  )}
                </div>
              </>
            ) : (
              <MissionPlaceholder />
            )}
          </div>
        </div>

        {/*
          The tab bar. Sticky, translucent, and horizontally scrollable on
          narrow screens — this many destinations do not fit on a phone, and
          truncating them would hide whole areas of the product.
        */}
        <div className="blurbar sticky top-0 z-20 border-b border-[var(--rule)]">
          <nav
            className="mx-auto flex max-w-[1180px] gap-1 overflow-x-auto px-4 py-1.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            aria-label="Circle sections"
          >
            {TAB_GROUPS.map((group, groupIndex) => (
              <div key={groupIndex} className="flex shrink-0 items-center gap-1">
                {groupIndex > 0 && (
                  <span
                    className="mx-1.5 h-4 w-px shrink-0 bg-[var(--rule-strong)]"
                    aria-hidden="true"
                  />
                )}
                {group.map((t) => {
                  const active = t.key === tab;
                  const href = t.key ? `/circles/${circleId}/${t.key}` : `/circles/${circleId}`;
                  return (
                    <a
                      key={t.key}
                      href={href}
                      aria-current={active ? "page" : undefined}
                      className={`shrink-0 rounded-[var(--r-control)] px-3 py-1.5 text-[0.875rem] no-underline transition-colors duration-150 ${
                        active
                          ? "bg-[var(--paper-sunk)] font-[590] text-[var(--ink)]"
                          : "font-[450] text-[var(--ink-muted)] hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)]"
                      }`}
                    >
                      {t.label}
                    </a>
                  );
                })}
              </div>
            ))}
          </nav>
        </div>
      </header>

      {circle?.is_closed && (
        <div className="border-b border-[var(--rule)] bg-[var(--paper-sunk)]">
          <p className="mx-auto max-w-[1180px] px-6 py-2.5 text-[0.8125rem] leading-snug text-[var(--ink-muted)]">
            <span className="mr-2 font-[590] text-[var(--ink)]">This Circle is closed.</span>
            It is a read-only record: external participants and agents no longer
            have access, and nothing further can be added.
          </p>
        </div>
      )}

      <main className="mx-auto max-w-[1180px] px-6 py-7">{children(circle)}</main>
    </div>
  );
}

/**
 * Holds the header's height while the Circle is in flight.
 *
 * The measurements match the real block above line for line, so the tab bar
 * and the view below it never move once the name and chips arrive.
 */
function MissionPlaceholder() {
  return (
    <div aria-hidden="true" className="animate-pulse">
      <div className="h-[2.25rem] w-2/3 rounded-md bg-[var(--paper-sunk)]" />
      <div className="mt-2.5 h-[1.5rem] w-full rounded-md bg-[var(--paper-sunk)]" />
      <div className="mt-4 h-[1.25rem] w-1/2 rounded-md bg-[var(--paper-sunk)]" />
    </div>
  );
}

function Divider() {
  return (
    <span className="text-[var(--rule-strong)]" aria-hidden="true">
      ·
    </span>
  );
}
