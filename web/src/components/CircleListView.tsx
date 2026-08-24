import type { ReactNode } from "react";
import { useEffect, useState } from "react";
import { api, clearToken, formatDate, relativeDays, type Circle } from "../lib/api";
import {
  Button,
  Empty,
  ErrorNote,
  Field,
  Loading,
  Meta,
  Panel,
  inputClass,
  useAsync,
} from "./ui";
import { ThemeToggle } from "./ThemeToggle";
import { Ring, progressTone } from "./CircleMeter";
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
  const { data, error, loading } = useAsync<Circle[]>(
    () => api.get<{ data: Circle[] }>("/circles").then((r) => r.data),
    [],
  );

  const active = (data ?? []).filter((c) => !c.is_closed);
  const closed = (data ?? []).filter((c) => c.is_closed);

  return (
    <div className="min-h-screen">
      <header className="blurbar sticky top-0 z-20 border-b border-[var(--rule)]">
        <div className="mx-auto flex max-w-[900px] items-center justify-between px-6 py-3">
          <Wordmark size={17} />
          <div className="flex items-center gap-1">
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
              Each Circle holds only what one mission needs, and ends when the
              mission does.
            </p>
          </div>
          <Button variant={creating ? "default" : "primary"} onClick={() => setCreating((v) => !v)}>
            {creating ? "Cancel" : "Open a Circle"}
          </Button>
        </div>

        {creating && (
          <div className="mb-6">
            {/* On success the Creator navigates straight into the new
                Circle, so there is nothing for this page to refresh. */}
            <Creator />
          </div>
        )}

        {loading && (
          <Panel>
            <Loading what="Circles" />
          </Panel>
        )}
        {!!error && <ErrorNote error={error} />}

        {data && (
          <div className="space-y-8">
            <Section title="Active">
              {active.length === 0 ? (
                <Panel>
                  <Empty>
                    You are not in any open Circle. You will appear here once
                    someone invites you to one.
                  </Empty>
                </Panel>
              ) : (
                <ul className="space-y-3">
                  {active.map((c, i) => (
                    <CircleCard key={c.id} circle={c} index={i} />
                  ))}
                </ul>
              )}
            </Section>

            {closed.length > 0 && (
              <Section title="Closed" meta={`${closed.length}`}>
                <ul className="space-y-3">
                  {closed.map((c, i) => (
                    <CircleCard key={c.id} circle={c} index={i} />
                  ))}
                </ul>
              </Section>
            )}
          </div>
        )}
      </main>
    </div>
  );
}

/**
 * A group heading that sits on the page rather than inside a card — so each
 * Circle can be its own tappable card instead of a row in a table.
 */
function Section({
  title,
  meta,
  children,
}: {
  title: string;
  meta?: string;
  children: ReactNode;
}) {
  return (
    <section>
      <div className="mb-3 flex items-baseline justify-between px-1">
        <h2 className="display text-[0.9375rem] font-[600] text-[var(--ink)]">{title}</h2>
        {meta && <Meta>{meta}</Meta>}
      </div>
      {children}
    </section>
  );
}

function CircleCard({ circle, index }: { circle: Circle; index: number }) {
  return (
    <li className="lay-in" style={{ animationDelay: `${index * 40}ms` }}>
      <a
        href={`/circles/${circle.id}`}
        className="card block px-5 py-4 no-underline transition-[transform,box-shadow] duration-200 hover:-translate-y-px hover:shadow-[var(--shadow-ring),var(--shadow-float)]"
      >
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          {/* The ring carries how far along the mission is; the figure is in
              the footer, because a ring alone is a shape, not a number. */}
          <h3 className="display flex items-center gap-2.5 text-[1.0625rem] font-[600] text-[var(--ink)]">
            <Ring progress={circle.progress} tone={progressTone(circle)} size={20} />
            {circle.name}
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
            {circle.is_closed
              ? `Closed ${formatDate(circle.closed_at)}`
              : relativeDays(circle.expires_at)}
          </span>
        </div>

        <p className="mt-1.5 line-clamp-2 text-sm leading-relaxed text-[var(--ink-muted)]">
          {circle.purpose}
        </p>

        <p className="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-[var(--ink-faint)]">
          <span className="tabular font-[560] text-[var(--ink-muted)]">
            {circle.progress}%
          </span>
          <span aria-hidden="true">·</span>
          <span className="capitalize">{circle.my_role ?? "—"}</span>
          {circle.my_access?.is_external && (
            <span className="rounded-[var(--r-chip)] bg-[var(--signal-soft)] px-1.5 py-0.5 font-[560] text-[var(--signal)]">
              External
            </span>
          )}
          <span aria-hidden="true">·</span>
          <span>Owner {circle.owner.name ?? "—"}</span>
        </p>
      </a>
    </li>
  );
}

interface Org { id: string; name: string; slug: string }

function Creator() {
  const { data: orgs } = useAsync<Org[]>(
    () => api.get<{ data: Org[] }>("/organisations").then((r) => r.data),
    [],
  );
  const [organisationId, setOrganisationId] = useState("");
  const [name, setName] = useState("");
  const [purpose, setPurpose] = useState("");
  const [expires, setExpires] = useState("");
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  // Most people belong to exactly one organisation; do not make them pick.
  useEffect(() => {
    if (!organisationId && orgs?.length === 1) setOrganisationId(orgs[0].id);
  }, [orgs, organisationId]);

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
          label="Mission"
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
              onChange={(e) => setOrganisationId(e.target.value)}
              className={inputClass}
            >
              <option value="">Choose…</option>
              {(orgs ?? []).map((o) => (
                <option key={o.id} value={o.id}>{o.name}</option>
              ))}
            </select>
          </Field>

          <Field label="Closes on" hint="A Circle is temporary by design.">
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
