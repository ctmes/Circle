import { useEffect, useState } from "react";
import { api, clearToken, formatDate, relativeDays, type Circle } from "../lib/api";
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
      <header className="border-b-2 border-[var(--ink)] bg-[var(--paper-raised)]">
        <div className="mx-auto flex max-w-[1100px] items-center justify-between px-6 py-3">
          <div className="flex items-center gap-2.5">
            <svg width="17" height="17" viewBox="0 0 32 32" aria-hidden="true">
              <circle cx="16" cy="16" r="12" fill="none" stroke="currentColor" strokeWidth="2.5" />
              <circle cx="16" cy="16" r="4" fill="var(--signal)" />
            </svg>
            <span className="display text-sm font-700 uppercase tracking-[0.22em]">Circle</span>
          </div>
          <button
            onClick={() => {
              clearToken();
              location.href = "/login";
            }}
            className="label hover:text-[var(--ink)]"
          >
            Sign out
          </button>
        </div>
      </header>

      <main className="mx-auto max-w-[1100px] px-6 py-8">
        <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="display text-2xl font-700">Your Circles</h1>
            <p className="mt-1 max-w-2xl text-sm text-[var(--ink-muted)]">
              Each Circle holds only what one mission needs, and ends when the
              mission does.
            </p>
          </div>
          <Button variant={creating ? "quiet" : "primary"} onClick={() => setCreating((v) => !v)}>
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

        {loading && <Loading what="Circles" />}
        {!!error && <ErrorNote error={error} />}

        {data && (
          <div className="space-y-6">
            <Panel title="Active">
              {active.length === 0 ? (
                <Empty>
                  You are not in any open Circle. You will appear here once
                  someone invites you to one.
                </Empty>
              ) : (
                <ul>
                  {active.map((c, i) => (
                    <CircleRow key={c.id} circle={c} index={i} />
                  ))}
                </ul>
              )}
            </Panel>

            {closed.length > 0 && (
              <Panel title="Closed">
                <ul>
                  {closed.map((c, i) => (
                    <CircleRow key={c.id} circle={c} index={i} />
                  ))}
                </ul>
              </Panel>
            )}
          </div>
        )}
      </main>
    </div>
  );
}

function CircleRow({ circle, index }: { circle: Circle; index: number }) {
  return (
    <li className="lay-in" style={{ animationDelay: `${index * 30}ms` }}>
      <a
        href={`/circles/${circle.id}`}
        className="block border-b border-[var(--rule)] px-4 py-3.5 no-underline transition-colors last:border-0 hover:bg-[var(--paper-sunk)]"
      >
        <div className="flex flex-wrap items-baseline justify-between gap-3">
          <h3 className="display text-base font-600 text-[var(--ink)]">{circle.name}</h3>
          <span
            className={`mono text-xs ${
              circle.is_closed
                ? "text-[var(--ink-faint)]"
                : circle.is_expired
                  ? "text-[var(--signal)]"
                  : "text-[var(--ink-muted)]"
            }`}
          >
            {circle.is_closed
              ? `closed ${formatDate(circle.closed_at)}`
              : relativeDays(circle.expires_at)}
          </span>
        </div>

        <p className="mt-1 max-w-3xl text-sm leading-snug text-[var(--ink-muted)]">
          {circle.purpose}
        </p>

        <p className="mt-1.5 mono text-[0.6875rem] text-[var(--ink-faint)]">
          your role: {circle.my_role ?? "—"}
          {circle.my_access?.is_external && (
            <span className="text-[var(--signal)]"> · external</span>
          )}
          {" · owner: "}
          {circle.owner.name ?? "—"}
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
      <div className="space-y-3 px-4 py-4">
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
            className={inputClass}
            placeholder="Assemble and review the bid package for the Bay Junction rail access matting works, and decide go/no-go."
          />
        </Field>

        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Organisation">
            <select
              value={organisationId}
              onChange={(e) => setOrganisationId(e.target.value)}
              className={inputClass}
            >
              <option value="">choose…</option>
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
