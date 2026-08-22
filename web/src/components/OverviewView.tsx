import { useState } from "react";
import { api, formatDate, relativeDays, type Overview } from "../lib/api";
import { CircleFrame } from "./CircleFrame";
import { DerivedStamp, StatusChip } from "./Trust";
import { Button, Empty, ErrorNote, Loading, Panel, useAsync } from "./ui";

/**
 * The "Now" view (spec §12).
 *
 * Deliberately narrow: what is blocked, who owns it, what decision is pending,
 * and what the Steward last said. Everything else lives behind a tab. The
 * temptation with a page like this is to show activity; activity is not the
 * same as what needs attention.
 */
export function OverviewView({ circleId }: { circleId: string }) {
  return (
    <CircleFrame circleId={circleId} tab="">
      {(circle) => <Body circleId={circleId} canRunAgent={
        circle.my_access?.permissions.includes("agent.run") === true && !circle.is_closed
      } />}
    </CircleFrame>
  );
}

function Body({ circleId, canRunAgent }: { circleId: string; canRunAgent: boolean }) {
  const { data, error, loading, reload } = useAsync<Overview>(
    () => api.get<{ data: Overview }>(`/circles/${circleId}/overview`).then((r) => r.data),
    [circleId],
  );

  if (loading) return <Loading what="mission state" />;
  if (error) return <ErrorNote error={error} />;
  if (!data) return null;

  const blockers = data.commitments.filter((c) => c.overdue || c.status === "blocked");

  return (
    <div className="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <div className="space-y-5">
        {/* What must happen next — first, and alone, so it cannot be missed. */}
        <Panel
          title="Next decision required"
          tone={data.next_decision ? "signal" : "default"}
          className="lay-in"
        >
          {data.next_decision ? (
            <div className="px-4 py-4">
              <a
                href={`/circles/${circleId}/decisions`}
                className="display block text-lg font-600 leading-snug text-[var(--ink)] no-underline hover:underline"
              >
                {data.next_decision.title}
              </a>
              <p className="mt-1.5 text-sm text-[var(--ink-muted)]">
                Assigned to{" "}
                <span className="mono text-xs">{data.next_decision.approver ?? "nobody yet"}</span>
                {data.next_decision.expires_at && (
                  <> · {relativeDays(data.next_decision.expires_at)}</>
                )}
              </p>
              {data.pending_decisions.length > 1 && (
                <p className="mt-3 text-xs text-[var(--ink-faint)]">
                  {data.pending_decisions.length - 1} other decision
                  {data.pending_decisions.length > 2 ? "s" : ""} pending.
                </p>
              )}
            </div>
          ) : (
            <Empty>No decision is waiting on anyone.</Empty>
          )}
        </Panel>

        {blockers.length > 0 && (
          <Panel title="Blocked or overdue" tone="signal" className="lay-in" >
            <ul>
              {blockers.map((c) => (
                <li
                  key={c.id}
                  className="flex flex-wrap items-baseline justify-between gap-3 border-b border-[var(--rule)] px-4 py-2.5 last:border-0"
                >
                  <span className="text-sm">{c.title}</span>
                  <span className="flex items-center gap-3">
                    <span className="mono text-xs text-[var(--ink-muted)]">
                      {c.owner ?? "unassigned"}
                    </span>
                    <span className="mono text-xs text-[var(--signal)]">
                      {relativeDays(c.due_at)}
                    </span>
                  </span>
                </li>
              ))}
            </ul>
          </Panel>
        )}

        <Panel
          title="Active commitments"
          meta={
            <span className="mono text-xs text-[var(--ink-faint)]">
              {data.commitments.length} open · {data.overdue_count} overdue
            </span>
          }
          className="lay-in"
        >
          {data.commitments.length === 0 ? (
            <Empty>Nobody has committed to anything yet.</Empty>
          ) : (
            <ul>
              {data.commitments.map((c) => (
                <li
                  key={c.id}
                  className="flex flex-wrap items-baseline justify-between gap-3 border-b border-[var(--rule)] px-4 py-2.5 last:border-0"
                >
                  <span className="text-sm">{c.title}</span>
                  <span className="flex items-center gap-3">
                    <StatusChip status={c.status} />
                    <span className="mono text-xs text-[var(--ink-muted)]">
                      {c.owner ?? "unassigned"}
                    </span>
                    <span
                      className={`mono text-xs ${c.overdue ? "text-[var(--signal)]" : "text-[var(--ink-faint)]"}`}
                    >
                      {relativeDays(c.due_at)}
                    </span>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>

      <div className="space-y-5">
        <StewardPanel
          circleId={circleId}
          brief={data.latest_brief}
          canRun={canRunAgent}
          onRan={reload}
        />

        <Panel title="Recent approvals" className="lay-in">
          {data.recent_approvals.length === 0 ? (
            <Empty>Nothing has been approved yet.</Empty>
          ) : (
            <ul>
              {data.recent_approvals.map((d) => (
                <li key={d.id} className="border-b border-[var(--rule)] px-4 py-2.5 last:border-0">
                  <div className="flex items-baseline justify-between gap-2">
                    <span className="text-sm leading-snug">{d.title}</span>
                    <StatusChip status={d.status} tone={d.status === "approved" ? "settled" : "signal"} />
                  </div>
                  <p className="mt-1 mono text-[0.6875rem] text-[var(--ink-faint)]">
                    {d.approver ?? "—"} · {formatDate(d.resolved_at, true)}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>
    </div>
  );
}

/**
 * The Steward's latest brief. Rendered in the derived treatment — hatched rule,
 * cool ink, explicit stamp — because it is a machine reading of the evidence,
 * not a finding.
 */
function StewardPanel({
  circleId,
  brief,
  canRun,
  onRan,
}: {
  circleId: string;
  brief: Overview["latest_brief"];
  canRun: boolean;
  onRan: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [runError, setRunError] = useState<unknown>(null);

  async function run() {
    setBusy(true);
    setRunError(null);

    try {
      await api.post(`/circles/${circleId}/agent-runs/steward-brief`);
      onRan();
    } catch (e) {
      // A refused or failed run is worth showing in place: the reason is the
      // policy gate's or the provider's, and it is actionable.
      setRunError(e);
    } finally {
      setBusy(false);
    }
  }

  const content = brief?.content ?? null;

  return (
    <Panel
      title="Circle Steward"
      tone="derived"
      className="lay-in"
      meta={
        canRun && (
          <Button variant="quiet" onClick={run} disabled={busy}>
            {busy ? "Running…" : "Run brief"}
          </Button>
        )
      }
    >
      <div className="hatch h-1 opacity-40" />

      {!!runError && (
        <div className="px-4 pt-4">
          <ErrorNote error={runError} />
        </div>
      )}

      {!brief ? (
        <Empty>The Steward has not produced a brief yet.</Empty>
      ) : (
        <div className="space-y-3 px-4 py-4">
          <DerivedStamp />

          {content?.summary && (
            <p className="text-[0.9375rem] leading-snug">{content.summary}</p>
          )}

          {content?.status && (
            <p className="label">
              assessed as <span className="text-[var(--derived)]">{String(content.status).replace(/_/g, " ")}</span>
            </p>
          )}

          {content?.uncertainty && (
            <div className="border-l-2 border-[var(--rule-strong)] pl-3">
              <p className="label">What it could not determine</p>
              <p className="mt-0.5 text-sm italic text-[var(--ink-muted)]">
                {content.uncertainty}
              </p>
            </div>
          )}

          {Array.isArray(content?.missing_evidence) && content.missing_evidence.length > 0 && (
            <div>
              <p className="label">Evidence it says is missing</p>
              <ul className="mt-1 space-y-1">
                {content.missing_evidence.map((m: any, i: number) => (
                  <li key={i} className="text-sm text-[var(--ink-muted)]">
                    — {m.description}
                  </li>
                ))}
              </ul>
            </div>
          )}

          <p className="mono text-[0.6875rem] text-[var(--ink-faint)]">
            {brief.model ?? "unknown model"} · {formatDate(brief.created_at, true)}
            {brief.agent_run_id && (
              <>
                {" · "}
                <a href={`/circles/${circleId}/history`} className="underline decoration-dotted">
                  what it read
                </a>
              </>
            )}
          </p>
        </div>
      )}
    </Panel>
  );
}
