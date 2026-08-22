import type { ReactNode } from "react";
import { api, clearToken, relativeDays, type Circle } from "../lib/api";
import { ErrorNote, Loading, useAsync } from "./ui";

const TABS = [
  { key: "", label: "Now" },
  { key: "context", label: "Context" },
  { key: "claims", label: "Claims" },
  { key: "decisions", label: "Decisions" },
  { key: "commitments", label: "Commitments" },
  { key: "people", label: "People" },
  { key: "history", label: "History" },
  { key: "export", label: "Export" },
] as const;

/**
 * The title block that heads every Circle page.
 *
 * It answers the two questions the spec's Definition of Done puts first — what
 * is this mission, and when does it end — before anything else on the page, and
 * it never leaves the screen. Closure and expiry are stated loudly, because a
 * closed Circle behaves differently and people need to know why an action just
 * disappeared.
 */
export function CircleFrame({
  circleId,
  tab,
  children,
}: {
  circleId: string;
  tab: string;
  children: (circle: Circle) => ReactNode;
}) {
  const { data: circle, error, loading } = useAsync<Circle>(
    () => api.get<{ data: Circle }>(`/circles/${circleId}`).then((r) => r.data),
    [circleId],
  );

  if (loading) return <Loading what="mission" />;
  if (error) return <div className="mx-auto max-w-3xl p-8"><ErrorNote error={error} /></div>;
  if (!circle) return null;

  const state = circle.is_closed
    ? { text: "closed", tone: "text-[var(--ink-faint)]" }
    : circle.is_expired
      ? { text: "expired", tone: "text-[var(--signal)]" }
      : { text: circle.status, tone: "text-[var(--settled)]" };

  return (
    <div className="min-h-screen">
      <header className="border-b-2 border-[var(--ink)] bg-[var(--paper-raised)]">
        <div className="mx-auto max-w-[1400px] px-6">
          {/* Top strip: product mark and account. */}
          <div className="flex items-center justify-between border-b border-[var(--rule)] py-2">
            <a href="/circles" className="flex items-center gap-2 no-underline">
              <svg width="15" height="15" viewBox="0 0 32 32" aria-hidden="true">
                <circle cx="16" cy="16" r="12" fill="none" stroke="currentColor" strokeWidth="2.5" />
                <circle cx="16" cy="16" r="4" fill="var(--signal)" />
              </svg>
              <span className="display text-xs font-700 uppercase tracking-[0.22em]">Circle</span>
            </a>
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

          {/* The title block proper. */}
          <div className="grid gap-x-8 gap-y-3 py-4 lg:grid-cols-[minmax(0,1fr)_auto]">
            <div className="min-w-0">
              <p className="label">Mission</p>
              <h1 className="display mt-0.5 text-[1.75rem] font-700 leading-tight">
                {circle.name}
              </h1>
              <p className="mt-1.5 max-w-3xl text-[0.9375rem] leading-snug text-[var(--ink-muted)]">
                {circle.purpose}
              </p>
            </div>

            <dl className="grid grid-cols-2 gap-x-6 gap-y-1.5 self-start border-l border-[var(--rule)] pl-6 sm:grid-cols-3 lg:grid-cols-2">
              <div>
                <dt className="label">Status</dt>
                <dd className={`mono text-xs font-500 ${state.tone}`}>{state.text}</dd>
              </div>
              <div>
                <dt className="label">Closes</dt>
                <dd className="mono text-xs">
                  {circle.expires_at ? relativeDays(circle.expires_at) : "no deadline"}
                </dd>
              </div>
              <div>
                <dt className="label">Owner</dt>
                <dd className="mono text-xs">{circle.owner.name ?? "—"}</dd>
              </div>
              <div>
                <dt className="label">Your role</dt>
                <dd className="mono text-xs">
                  {circle.my_role ?? "—"}
                  {circle.my_access?.is_external && (
                    <span className="ml-1 text-[var(--signal)]">· external</span>
                  )}
                </dd>
              </div>
            </dl>
          </div>

          <nav className="flex flex-wrap gap-x-1 overflow-x-auto">
            {TABS.map((t) => {
              const active = t.key === tab;
              const href = t.key ? `/circles/${circleId}/${t.key}` : `/circles/${circleId}`;
              return (
                <a
                  key={t.key}
                  href={href}
                  aria-current={active ? "page" : undefined}
                  className={`display -mb-px border-b-2 px-3 py-2 text-xs font-600 uppercase tracking-[0.11em] no-underline transition-colors ${
                    active
                      ? "border-[var(--signal)] text-[var(--ink)]"
                      : "border-transparent text-[var(--ink-faint)] hover:text-[var(--ink)]"
                  }`}
                >
                  {t.label}
                </a>
              );
            })}
          </nav>
        </div>
      </header>

      {circle.is_closed && (
        <div className="border-b border-[var(--rule-strong)] bg-[var(--paper-sunk)]">
          <p className="mx-auto max-w-[1400px] px-6 py-2 text-xs text-[var(--ink-muted)]">
            <span className="stamp mr-2 text-[var(--ink-faint)]">closed</span>
            This Circle is closed. It is a read-only record: external participants
            and agents no longer have access, and nothing further can be added.
          </p>
        </div>
      )}

      <main className="mx-auto max-w-[1400px] px-6 py-6">{children(circle)}</main>
    </div>
  );
}
