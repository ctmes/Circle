import type { ReactNode } from "react";
import { Fragment, useEffect, useState } from "react";
import {
  api,
  clearToken,
  formatDate,
  relativeDays,
  timeAgo,
  type Circle,
  type CircleDigest,
} from "../lib/api";
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
import { ThemeToggle } from "./ThemeToggle";
import { Ring, progressTone } from "./CircleMeter";
import { ConveneDrop } from "./ConveneDrop";
import { Wordmark } from "./Wordmark";

/**
 * The Circle index.
 *
 * Only Circles the signed-in person is a member of appear here — there is no
 * organisation-wide listing, because organisation membership conveys no access
 * (spec §2).
 */
export function CircleListView() {
  const [creating, setCreating] = useState(false);
  const { data, error, loading, mutate } = useAsync<ListedCircle[]>(
    () => api.get<{ data: ListedCircle[] }>("/circles").then((r) => r.data),
    [],
  );

  // Read from the URL after hydration rather than during render, so the
  // server's markup and the first client render agree.
  const [tab, setTab] = useState<Tab>("active");
  useEffect(() => {
    const t = new URLSearchParams(location.search).get("tab");
    if (t === "archived" || t === "deleted") setTab(t);
  }, []);

  function show(next: Tab) {
    setTab(next);
    history.replaceState(null, "", next === "active" ? location.pathname : `?tab=${next}`);
  }

  // Every archive, delete and restore answers with the Circle as it now
  // stands, so it is spliced in and the card moves tab without a refetch.
  // The answer carries no digest — only the list computes one — so the card
  // keeps the one it had. A closed Circle shows none of the parts that could
  // have gone stale.
  function replace(next: Circle) {
    mutate((all) => all.map((c) => (c.id === next.id ? { ...next, digest: c.digest } : c)));
  }

  // Which company is convening is one answer, not one per panel. It was inside
  // the form until there were two ways to open a Circle, at which point picking
  // it in one and having the other not know was a bug waiting to be filed.
  const { data: orgs } = useAsync<Org[]>(
    () => api.get<{ data: Org[] }>("/organisations").then((r) => r.data),
    [],
  );
  const [organisationId, setOrganisationId] = useState("");

  // Most people belong to exactly one organisation; do not make them pick.
  useEffect(() => {
    if (!organisationId && orgs?.length === 1) setOrganisationId(orgs[0].id);
  }, [orgs, organisationId]);

  const circles = data ?? [];
  const groups: Record<Tab, ListedCircle[]> = {
    // Whatever needs you rises to the top; the rest keep their order, newest
    // first. The sort is stable, so the order among equals never shuffles.
    active: circles
      .filter((c) => !c.is_closed && !c.is_deleted)
      .sort((a, b) => waitingTotal(b) - waitingTotal(a)),
    archived: circles.filter((c) => c.is_closed && !c.is_deleted),
    deleted: circles.filter((c) => c.is_deleted),
  };
  const shown = groups[tab];

  const waiting = groups.active.reduce((sum, c) => sum + waitingTotal(c), 0);
  const waitingIn = groups.active.filter((c) => waitingTotal(c) > 0).length;

  return (
    <div className="min-h-screen">
      <header className="blurbar sticky top-0 z-20 border-b border-[var(--rule)]">
        <div className="mx-auto flex max-w-[900px] items-center justify-between px-6 py-3">
          <Wordmark size={17} />
          <div className="flex items-center gap-1">
            {/* Meetings in, and what each one changed (spec 24). */}
            <a
              href="/meetings"
              className="rounded-[var(--r-control)] px-2.5 py-1.5 text-[0.8125rem] text-[var(--ink-muted)] no-underline transition-colors hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)]"
            >
              Meetings
            </a>
            {/* Open work, contracts and the record — none of which is a Circle. */}
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
                location.href = "/login";
              }}
              className="rounded-[var(--r-control)] px-2.5 py-1.5 text-[0.8125rem] text-[var(--ink-muted)] transition-colors hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)]"
            >
              Sign out
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-[900px] px-6 py-9">
        <div className="mb-7 flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="display text-[2rem] font-[680] leading-tight">Your Circles</h1>
            <p className="mt-2 max-w-xl text-[0.9375rem] leading-relaxed text-[var(--ink-muted)]">
              Each Circle holds what one piece of work needs, and ends when that
              work does.
            </p>
          </div>
          <Button variant={creating ? "default" : "primary"} onClick={() => setCreating((v) => !v)}>
            {creating ? "Cancel" : "Open a Circle"}
          </Button>
        </div>

        {creating && (
          <div className="mb-6 space-y-4">
            {/*
              Two ways in, and the document first because it is the one that
              does the work for you. The form underneath is not a fallback —
              plenty of missions begin before anybody has signed anything — but
              where a contract exists it already contains the plan, and typing
              it out again is where the copy starts to diverge from what was
              agreed.
            */}
            <ConveneDrop organisationId={organisationId} />

            <p className="px-1 text-xs text-[var(--ink-faint)]">
              Or set it up by hand.
            </p>

            {/* On success the Creator navigates straight into the new
                Circle, so there is nothing for this page to refresh. */}
            <Creator
              orgs={orgs ?? []}
              organisationId={organisationId}
              onOrganisation={setOrganisationId}
            />
          </div>
        )}

        {loading && (
          <Panel>
            <Loading what="Circles" />
          </Panel>
        )}
        {!!error && <ErrorNote error={error} />}

        {data && (
          <>
            {/* One line across every Circle, so the first question — is anything
                waiting on me? — is answered before reading a single card. */}
            {groups.active.length > 0 && (
              <p className="mb-4 flex items-center gap-2 px-1 text-[0.8125rem]">
                {waiting > 0 ? (
                  <>
                    <span className="inline-block size-[7px] shrink-0 rounded-full bg-[var(--signal)]" aria-hidden="true" />
                    <span className="font-[600] text-[var(--ink)]">
                      {waiting} {waiting === 1 ? "thing" : "things"} waiting on you
                    </span>
                    <span className="text-[var(--ink-muted)]">
                      {waitingIn === 1 ? "in 1 Circle" : `across ${waitingIn} Circles`}
                    </span>
                  </>
                ) : (
                  <span className="text-[var(--ink-faint)]">Nothing is waiting on you.</span>
                )}
              </p>
            )}

            <nav className="mb-5 flex flex-wrap gap-1 border-b border-[var(--rule)]" aria-label="Circle lists">
              {TABS.map((t) => (
                <button
                  key={t.key}
                  type="button"
                  onClick={() => show(t.key)}
                  aria-current={tab === t.key ? "page" : undefined}
                  className={`-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-[0.8125rem] transition-colors ${
                    tab === t.key
                      ? "border-[var(--ink)] font-medium text-[var(--ink)]"
                      : "border-transparent text-[var(--ink-faint)] hover:text-[var(--ink-muted)]"
                  }`}
                >
                  {t.label}
                  {groups[t.key].length > 0 && (
                    <span className="tabular text-xs text-[var(--ink-faint)]">
                      {groups[t.key].length}
                    </span>
                  )}
                </button>
              ))}
            </nav>

            {TAB_NOTE[tab] && (
              <p className="mb-4 max-w-xl px-1 text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
                {TAB_NOTE[tab]}
              </p>
            )}

            {shown.length === 0 ? (
              <Panel>
                <Empty>{TAB_EMPTY[tab]}</Empty>
              </Panel>
            ) : (
              <ul className="space-y-3">
                {shown.map((c, i) => (
                  <CircleCard key={c.id} circle={c} index={i} onChange={replace} />
                ))}
              </ul>
            )}
          </>
        )}
      </main>
    </div>
  );
}

type Tab = "active" | "archived" | "deleted";

const TABS: Array<{ key: Tab; label: string }> = [
  { key: "active", label: "Active" },
  { key: "archived", label: "Archived" },
  { key: "deleted", label: "Deleted" },
];

const TAB_NOTE: Record<Tab, string | null> = {
  active: null,
  archived:
    "Closed for good. Your own people keep a read-only record they can still export; external collaborators and agents have no access.",
  deleted:
    "Out of everyone's reach, you included. Nothing in them has been erased — restore one and it comes back as an archived record.",
};

const TAB_EMPTY: Record<Tab, string> = {
  active:
    "You're not in any open Circle yet. One will show up here once someone invites you.",
  archived: "Nothing archived yet.",
  deleted: "Nothing deleted.",
};

type Act = "archive" | "delete" | "restore";

function CircleCard({
  circle,
  index,
  onChange,
}: {
  circle: ListedCircle;
  index: number;
  onChange: (next: Circle) => void;
}) {
  const [confirming, setConfirming] = useState<Exclude<Act, "restore"> | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  const digest = circle.digest;
  // Only an open Circle has anything waiting, late, or next.
  const live = !circle.is_closed && !circle.is_deleted;

  // Offered only where the gate would allow them, so nothing is shown and
  // then refused. `circle.delete` covers restoring as well as deleting.
  const perms = circle.my_access?.permissions ?? [];
  const canArchive = !circle.is_closed && perms.includes("circle.close");
  const canDelete = !circle.is_deleted && perms.includes("circle.delete");
  const canRestore = circle.is_deleted && perms.includes("circle.delete");

  // A deleted Circle cannot be opened by anyone, so it is not a link.
  const openable = !circle.is_deleted;

  async function act(kind: Act) {
    setBusy(true);
    setError(null);
    try {
      const res =
        kind === "archive"
          ? await api.post<{ data: Circle }>(`/circles/${circle.id}/archive`)
          : kind === "delete"
            ? await api.del<{ data: Circle }>(`/circles/${circle.id}`)
            : await api.post<{ data: Circle }>(`/circles/${circle.id}/restore`);
      onChange(res.data);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <li className="lay-in" style={{ animationDelay: `${index * 40}ms` }}>
      <div
        className={`card relative px-5 py-4 ${
          openable
            ? "transition-[transform,box-shadow] duration-200 hover:-translate-y-px hover:shadow-[var(--shadow-ring),var(--shadow-float)]"
            : ""
        }`}
      >
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          {/* The ring carries how far along the mission is; the figure is in
              the footer, because a ring alone is a shape, not a number. */}
          <h3 className="display flex items-center gap-2.5 text-[1.0625rem] font-[600] text-[var(--ink)]">
            <Ring progress={circle.progress} tone={progressTone(circle)} size={20} />
            {openable ? (
              // Stretched over the whole card, so the card stays one big
              // target while the buttons below sit above it and stay buttons.
              <a
                href={`/circles/${circle.id}`}
                className="no-underline after:absolute after:inset-0 after:rounded-[var(--r-card)] focus-visible:shadow-none focus-visible:after:shadow-[0_0_0_2px_var(--canvas),0_0_0_4px_color-mix(in_srgb,var(--accent)_60%,transparent)]"
              >
                {circle.name}
              </a>
            ) : (
              circle.name
            )}
          </h3>
          <span
            className={`text-[0.8125rem] ${
              circle.is_closed
                ? "text-[var(--ink-faint)]"
                : circle.is_expired
                  ? "font-[560] text-[var(--signal)]"
                  : "text-[var(--ink-muted)]"
            }`}
          >
            {circle.is_deleted
              ? `Deleted ${formatDate(circle.deleted_at)}`
              : circle.is_closed
                ? `Archived ${formatDate(circle.closed_at)}`
                : relativeDays(circle.expires_at)}
          </span>
        </div>

        {/* One line. The whole purpose is the first thing inside; here it
            only has to say which mission this is. */}
        <p className="mt-1 line-clamp-1 text-sm leading-relaxed text-[var(--ink-muted)]">
          {circle.purpose}
        </p>

        {live && digest && <WaitingOnYou circleId={circle.id} waiting={digest.waiting} />}

        <div className="mt-3 flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
          <div className="min-w-0 space-y-1 text-xs">
            {/* How the mission stands. Lateness and what is next only mean
                anything while the Circle is open. */}
            <Dotted
              className="text-[var(--ink-muted)]"
              items={[
                <span className="tabular font-[560]">{circle.progress}%</span>,
                digest && digest.jobs.total > 0 && (
                  <span className="tabular">
                    {digest.jobs.done} of {digest.jobs.total} {digest.jobs.total === 1 ? "job" : "jobs"} done
                  </span>
                ),
                live && digest && digest.overdue > 0 && (
                  <span className="tabular font-[560] text-[var(--signal)]">{digest.overdue} overdue</span>
                ),
                live && digest?.next_due && (
                  <span className="flex min-w-0 max-w-[22rem] gap-1">
                    <span className="shrink-0">Next:</span>
                    <span className="truncate text-[var(--ink)]">{digest.next_due.title}</span>
                    <span className="shrink-0">{dueIn(digest.next_due.due_at)}</span>
                  </span>
                ),
              ]}
            />

            {/* Who is in it, and whether anything is happening. */}
            <Dotted
              className="text-[var(--ink-faint)]"
              items={[
                <span className="capitalize">{circle.my_role ?? "—"}</span>,
                circle.my_access?.is_external && (
                  <span className="rounded-[var(--r-chip)] bg-[var(--signal-soft)] px-1.5 py-0.5 font-[560] text-[var(--signal)]">
                    External
                  </span>
                ),
                <span>Owner {circle.owner.name ?? "—"}</span>,
                digest && digest.members > 0 && (
                  <span className="tabular">
                    {digest.members} {digest.members === 1 ? "person" : "people"}
                  </span>
                ),
                digest?.last_activity_at && <span>Updated {timeAgo(digest.last_activity_at)}</span>,
              ]}
            />
          </div>

          {!confirming && (canArchive || canDelete || canRestore) && (
            <div className="relative z-10 -my-1 flex items-center gap-1">
              {canArchive && (
                <Button variant="quiet" className={compact} onClick={() => setConfirming("archive")}>
                  Archive
                </Button>
              )}
              {canDelete && (
                <Button
                  variant="quiet"
                  className={`${compact} !text-[var(--signal)] hover:!bg-[var(--signal-soft)]`}
                  onClick={() => setConfirming("delete")}
                >
                  Delete
                </Button>
              )}
              {canRestore && (
                <Button variant="quiet" className={compact} disabled={busy} onClick={() => act("restore")}>
                  {busy ? "Restoring…" : "Restore"}
                </Button>
              )}
            </div>
          )}
        </div>

        {confirming && (
          <div className="relative z-10 mt-4 space-y-3 rounded-[var(--r-control)] bg-[var(--paper-inset)] px-4 py-3">
            <p className="text-sm leading-snug">
              {confirming === "archive"
                ? "Archiving closes this Circle for good. External collaborators lose access, agents stop, and nothing more can be added. Your own people keep a read-only record they can still export."
                : circle.is_closed
                  ? "Deleting takes this Circle out of everyone's reach, you included. Nothing in it is erased, and you can restore it from Deleted."
                  : "Deleting takes this Circle out of everyone's reach, you included. It is archived first, so external access ends and agents stop. Nothing in it is erased, and you can restore it from Deleted."}
            </p>
            <div className="flex flex-wrap items-center gap-2">
              <Button variant="danger" disabled={busy} onClick={() => act(confirming)}>
                {confirming === "archive"
                  ? busy ? "Archiving…" : "Yes, archive it"
                  : busy ? "Deleting…" : "Yes, delete it"}
              </Button>
              <Button
                variant="quiet"
                disabled={busy}
                onClick={() => {
                  setConfirming(null);
                  setError(null);
                }}
              >
                Cancel
              </Button>
            </div>
          </div>
        )}

        {!!error && (
          <div className="relative z-10 mt-3">
            <ErrorNote error={error} />
          </div>
        )}
      </div>
    </li>
  );
}

/** Footer-sized, so the actions do not outweigh the Circle they act on. */
const compact = "!px-2.5 !py-1.5 !text-xs";

/** A Circle as the list serves it: the Circle, plus the list's own summary. */
type ListedCircle = Circle & { digest: CircleDigest | null };

type WaitingKind = keyof CircleDigest["waiting"];

/**
 * What can be waiting on you, most binding first, and where each is dealt
 * with. A decision only you can resolve outranks a mention you can read at
 * leisure, so the order is the order of the strip.
 */
const WAITING: Array<{ key: WaitingKind; label: (n: number) => string; path: string }> = [
  { key: "decisions", label: (n) => `${n} ${n === 1 ? "decision" : "decisions"} to approve`, path: "/decisions" },
  { key: "date_changes", label: (n) => `${n} date ${n === 1 ? "change" : "changes"} to agree`, path: "" },
  { key: "revisions", label: (n) => `${n} plan ${n === 1 ? "revision" : "revisions"} to sign`, path: "/tree" },
  { key: "to_accept", label: (n) => `${n} ${n === 1 ? "job" : "jobs"} to accept`, path: "" },
  { key: "agent_actions", label: (n) => `${n} agent ${n === 1 ? "action" : "actions"} to review`, path: "/agents" },
  { key: "yours_overdue", label: (n) => `${n} of yours overdue`, path: "" },
  { key: "mentions", label: (n) => `${n} ${n === 1 ? "mention" : "mentions"}`, path: "" },
];

/** Kinds listed on a card before the rest collapse into "+N more". */
const WAITING_LISTED = 3;

function waitingTotal(c: ListedCircle): number {
  if (!c.digest || c.is_closed || c.is_deleted) return 0;
  return Object.values(c.digest.waiting).reduce((sum, n) => sum + n, 0);
}

/**
 * The one tinted thing on a card, and only when something needs you. Absent
 * otherwise — a strip saying "nothing" on every card would teach people to
 * stop reading it.
 */
function WaitingOnYou({
  circleId,
  waiting,
}: {
  circleId: string;
  waiting: CircleDigest["waiting"];
}) {
  const kinds = WAITING.filter((w) => waiting[w.key] > 0);
  if (kinds.length === 0) return null;

  const listed = kinds.slice(0, WAITING_LISTED);
  const more = kinds.slice(WAITING_LISTED).reduce((sum, w) => sum + waiting[w.key], 0);

  // Only the links sit above the card's stretched link, so a click anywhere
  // else on the strip still opens the Circle.
  const link = "relative z-10 no-underline hover:underline";

  return (
    <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-[var(--r-control)] bg-[var(--signal-soft)] px-3 py-2 text-[0.8125rem]">
      <span className="flex items-center gap-2 font-[600] text-[var(--ink)]">
        <span className="inline-block size-[7px] shrink-0 rounded-full bg-[var(--signal)]" aria-hidden="true" />
        Waiting on you
      </span>
      {listed.map((w) => (
        <a key={w.key} href={`/circles/${circleId}${w.path}`} className={`${link} text-[var(--accent)]`}>
          {w.label(waiting[w.key])}
        </a>
      ))}
      {more > 0 && (
        <a href={`/circles/${circleId}`} className={`${link} text-[var(--ink-muted)]`}>
          +{more} more
        </a>
      )}
    </div>
  );
}

/** A run of short facts separated by dots, skipping any that are absent. */
function Dotted({ items, className }: { items: ReactNode[]; className: string }) {
  const present = items.filter(Boolean);

  return (
    <p className={`flex flex-wrap items-center gap-x-2 gap-y-1 ${className}`}>
      {present.map((item, i) => (
        <Fragment key={i}>
          {i > 0 && <span aria-hidden="true">·</span>}
          {item}
        </Fragment>
      ))}
    </p>
  );
}

/** When something is next due, relative while it is close. */
function dueIn(iso: string): string {
  const days = Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000);
  if (days <= 0) return "today";
  if (days === 1) return "tomorrow";
  if (days < 14) return `in ${days} days`;
  return `on ${formatDate(iso)}`;
}

interface Org { id: string; name: string; slug: string }

function Creator({
  orgs,
  organisationId,
  onOrganisation,
}: {
  orgs: Org[];
  organisationId: string;
  onOrganisation: (id: string) => void;
}) {
  const [name, setName] = useState("");
  const [purpose, setPurpose] = useState("");
  const [expires, setExpires] = useState("");
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      const res = await api.post<{ data: Circle }>("/circles", {
        organisation_id: organisationId,
        name,
        purpose,
        expires_at: expires ? new Date(expires).toISOString() : undefined,
      });
      location.href = `/circles/${res.data.id}`;
    } catch (e) {
      setError(e);
      setBusy(false);
    }
  }

  return (
    <Panel title="Open a Circle" className="lay-in">
      <div className="space-y-4 px-5 pb-5">
        <Field
          label="Name"
          hint="Name the outcome, not the team. “Rail Access Package — Bid Review”, not “Ops”."
        >
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            className={inputClass}
            placeholder="Rail Access Package — Bid Review"
          />
        </Field>

        <Field
          label="Purpose"
          hint="What has to be true for this Circle to be finished? Everyone invited will read this first."
        >
          <textarea
            value={purpose}
            onChange={(e) => setPurpose(e.target.value)}
            rows={3}
            className={`${inputClass} resize-y leading-relaxed`}
            placeholder="Assemble and review the bid package for the Bay Junction rail access matting works, and decide go/no-go."
          />
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Organisation">
            <select
              value={organisationId}
              onChange={(e) => onOrganisation(e.target.value)}
              className={inputClass}
            >
              <option value="">Choose…</option>
              {orgs.map((o) => (
                <option key={o.id} value={o.id}>{o.name}</option>
              ))}
            </select>
          </Field>

          <Field label="Closes on" hint="Circles are meant to be temporary.">
            <input
              type="date"
              value={expires}
              onChange={(e) => setExpires(e.target.value)}
              className={inputClass}
            />
          </Field>
        </div>

        {!!error && <ErrorNote error={error} />}

        <Button
          variant="primary"
          onClick={submit}
          disabled={busy || !name.trim() || !purpose.trim() || !organisationId.trim()}
        >
          {busy ? "Opening…" : "Open Circle"}
        </Button>
      </div>
    </Panel>
  );
}
