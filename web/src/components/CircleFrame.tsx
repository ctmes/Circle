import { useEffect, useRef, useState, type ReactNode } from "react";
import { api, clearToken, relativeDays, type Circle } from "../lib/api";
import { CircleMeter, CircleMeterControl } from "./CircleMeter";
import { MissionStatement } from "./MissionStatement";
import { ErrorNote, forgetCached, useAsync } from "./ui";
import { ThemeToggle } from "./ThemeToggle";
import { Wordmark } from "./Wordmark";

/*
  Four destinations and a menu.

  Ten peer tabs said nothing about what to do first, which was the original
  complaint about this product, and grouping them with rules only softened it.
  These four are what a person does repeatedly: read the plan, put something on
  the record, find or file evidence, and answer an agent. Everything else is
  occasional — editing the plan's structure, admitting people, closing the thing
  down — and occasional work belongs behind one door rather than in the row you
  scan every time you arrive.
*/
const PRIMARY = [
  // The branch diagram, and the panels that hang off it. It absorbed the old
  // "Now" screen entirely: what is waiting on you is a strip at the top of it,
  // and the conversation, the room and what is late are panels below.
  //
  // "Main", not "Plan": it is no longer only the plan, and "Work" was never
  // available — the chrome carries a link to /work, a different destination.
  { key: "", label: "Main" },
  // Claims, decisions and commitments — three shapes of one question, settled
  // by a segmented control rather than by three tabs.
  { key: "record", label: "Record" },
  // The evidence vault. The one collection that genuinely cannot fold into the
  // plan: evidence carries no goal, and reaches the tree only through the
  // claims that cite it.
  { key: "context", label: "Context" },
  // An agent proposing something a person has to carry is the one recurring
  // interruption this product is built around, so it keeps a door of its own.
  { key: "agents", label: "Agents" },
] as const;

/**
 * What sits behind "More".
 *
 * Not a junk drawer — everything here is real, and each one is something a
 * Circle needs a handful of times across its whole life rather than daily.
 */
const MORE: ReadonlyArray<{
  key: string;
  label: string;
  /** Shown only to whoever can actually build a packet or close the Circle. */
  gated: boolean;
}> = [
  { key: "tree", label: "Tree", gated: false },
  // Where a Circle was built from a document, this is the reading it was built
  // from — which of these goals the contract stated and which were worked out.
  // Kept behind More because it is a thing you check once, and empty for a
  // Circle nobody convened, where it doubles as the way to start.
  { key: "convening", label: "Convening", gated: false },
  { key: "discussion", label: "Discussion", gated: false },
  { key: "people", label: "People", gated: false },
  { key: "hiring", label: "Hiring", gated: false },
  { key: "history", label: "History", gated: false },
  { key: "export", label: "Export", gated: true },
];

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
  // once, by one or two people, so they live in the More menu and appear only
  // for whoever holds either permission.
  //
  // Not gated on closure: the packet of a closed Circle is the whole point of
  // having one, and ExportView drops the closing half on its own.
  const canWrapUp =
    circle?.my_access?.permissions.some(
      (p) => p === "export.create" || p === "circle.close",
    ) === true;

  /*
    One width, every tab — and the reading measure rather than the window.

    Main briefly opted into a wider shell for the diagram's sake, which threw
    the page sideways whenever you changed tab. Widening everything to match
    fixed the jump and introduced a worse problem: a screen edge to edge on a
    large monitor is not a page, it is a wall. The diagram scrolls inside its
    own panel when it needs more room, which is the right thing to give room to.
  */
  const shell = "max-w-[1180px]";

  return (
    <div className="min-h-screen">
      {/*
        Everything above the content scrolls away except the tab bar, which is
        the only part needed to move between views. Stacking the two sticky
        strips would eat a third of a laptop screen.
      */}
      <header>
        <div className={`mx-auto px-6 ${shell}`}>
          <div className="flex items-center justify-between py-3.5">
            <a href="/circles" className="no-underline">
              <Wordmark size={17} />
            </a>
            <div className="flex items-center gap-1">
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
                      title="External collaborators can't download or share by default, and lose access altogether when the Circle closes."
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
          {/*
            The menu sits outside the scroller on purpose. `overflow-x-auto`
            establishes a clipping context in both axes, so a dropdown opened
            from inside it was cut off at the bar's own height.
          */}
          <div className={`mx-auto flex items-center gap-1 px-4 py-1.5 ${shell}`}>
            <nav
              className="flex gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
              aria-label="Circle sections"
            >
              {PRIMARY.map((t) => (
                <TabLink
                  key={t.key}
                  href={t.key ? `/circles/${circleId}/${t.key}` : `/circles/${circleId}`}
                  label={t.label}
                  active={t.key === tab}
                />
              ))}
            </nav>

            <span
              className="mx-1.5 h-4 w-px shrink-0 bg-[var(--rule-strong)]"
              aria-hidden="true"
            />

            <MoreMenu circleId={circleId} tab={tab} canWrapUp={canWrapUp} />
          </div>
        </div>
      </header>

      {circle?.is_closed && (
        <div className="border-b border-[var(--rule)] bg-[var(--paper-sunk)]">
          <p className={`mx-auto px-6 py-2.5 text-[0.8125rem] leading-snug text-[var(--ink-muted)] ${shell}`}>
            <span className="mr-2 font-[590] text-[var(--ink)]">This Circle is closed.</span>
            It's now a read-only record — external participants and agents have
            lost access, and nothing more can be added.
          </p>
        </div>
      )}

      <main className={`mx-auto px-6 py-7 ${shell}`}>{children(circle)}</main>
    </div>
  );
}

function TabLink({
  href,
  label,
  active,
}: {
  href: string;
  label: string;
  active: boolean;
}) {
  return (
    <a
      href={href}
      aria-current={active ? "page" : undefined}
      className={`shrink-0 rounded-[var(--r-control)] px-3 py-1.5 text-[0.875rem] no-underline transition-colors duration-150 ${
        active
          ? "bg-[var(--paper-sunk)] font-[590] text-[var(--ink)]"
          : "font-[450] text-[var(--ink-muted)] hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)]"
      }`}
    >
      {label}
    </a>
  );
}

/**
 * The one door everything occasional sits behind.
 *
 * It reads as active whenever the open page is one of its own, so a person who
 * has navigated into Hiring is not looking at a bar that claims they are
 * nowhere. Each entry carries a line of its own, because the whole risk of a
 * menu like this is that it becomes a list of words nobody can distinguish.
 */
function MoreMenu({
  circleId,
  tab,
  canWrapUp,
}: {
  circleId: string;
  tab: string;
  canWrapUp: boolean;
}) {
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;

    function away(e: MouseEvent) {
      if (!box.current?.contains(e.target as Node)) setOpen(false);
    }
    function esc(e: KeyboardEvent) {
      if (e.key === "Escape") setOpen(false);
    }

    document.addEventListener("mousedown", away);
    document.addEventListener("keydown", esc);
    return () => {
      document.removeEventListener("mousedown", away);
      document.removeEventListener("keydown", esc);
    };
  }, [open]);

  const items = MORE.filter((m) => !m.gated || canWrapUp);
  const here = items.some((m) => m.key === tab);

  return (
    <div ref={box} className="relative shrink-0">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        aria-haspopup="menu"
        className={`flex items-center gap-1.5 rounded-[var(--r-control)] px-3 py-1.5 text-[0.875rem] transition-colors duration-150 ${
          here || open
            ? "bg-[var(--paper-sunk)] font-[590] text-[var(--ink)]"
            : "font-[450] text-[var(--ink-muted)] hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)]"
        }`}
      >
        {here ? items.find((m) => m.key === tab)!.label : "More"}
        <svg
          viewBox="0 0 10 6"
          className={`h-1.5 w-2.5 transition-transform duration-150 ${open ? "rotate-180" : ""}`}
          fill="none"
          stroke="currentColor"
          strokeWidth="1.6"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
        >
          <path d="M1 1.5 L5 4.5 L9 1.5" />
        </svg>
      </button>

      {open && (
        <div
          role="menu"
          className="absolute left-0 top-full z-30 mt-1 w-44 overflow-hidden rounded-[var(--r-control)] border border-[var(--rule-strong)] bg-[var(--paper-raised)] p-1 shadow-[0_8px_24px_-6px_rgb(0_0_0/0.25)]"
        >
          {items.map((m) => (
            <a
              key={m.key}
              role="menuitem"
              href={`/circles/${circleId}/${m.key}`}
              className={`block rounded-[var(--r-control)] px-2.5 py-1.5 text-[0.875rem] no-underline transition-colors hover:bg-[var(--paper-sunk)] ${
                m.key === tab
                  ? "bg-[var(--paper-sunk)] font-[590] text-[var(--ink)]"
                  : "font-[450] text-[var(--ink-muted)] hover:text-[var(--ink)]"
              }`}
            >
              {m.label}
            </a>
          ))}
        </div>
      )}
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
