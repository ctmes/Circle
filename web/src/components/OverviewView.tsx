import type { ReactNode } from "react";
import { useState } from "react";
import {
  api,
  formatDate,
  relativeDays,
  type AgentActionRow,
  type Circle,
  type Goal,
  type MentionRow,
  type Overview,
} from "../lib/api";
import { CircleFrame } from "./CircleFrame";
import { EffectChip } from "./AgentsView";
import { DerivedStamp, StatusChip } from "./Trust";
import { Button, Empty, ErrorNote, Loading, Meta, Panel, useAsync } from "./ui";

/**
 * Now — what needs a person, in the order it needs them.
 *
 * The previous version of this screen reported activity: pending decisions,
 * open commitments, the last agent brief. Useful, but it answered "what is
 * happening" rather than "what is waiting on me", and those are different
 * questions — the second one is why anyone opens the app.
 *
 * So the first card is the only one that is about the reader. Everything below
 * it is context for the mission, and the plan itself lives one tab across.
 */
export function OverviewView({ circleId }: { circleId: string }) {
  return (
    <CircleFrame circleId={circleId} tab="now">
      {(circle) => <Body circleId={circleId} circle={circle} />}
    </CircleFrame>
  );
}

function Body({ circleId, circle }: { circleId: string; circle: Circle | null }) {
  const perms = circle?.my_access?.permissions ?? [];
  const canRunAgent = perms.includes("agent.run") && !circle?.is_closed;
  const canApprove = perms.includes("agent.approve") && !circle?.is_closed;

  const overview = useAsync<Overview>(
    () => api.get<{ data: Overview }>(`/circles/${circleId}/overview`).then((r) => r.data),
    [circleId],
  );
  const goals = useAsync<Goal[]>(
    () => api.get<{ data: Goal[] }>(`/circles/${circleId}/goals`).then((r) => r.data),
    [circleId],
  );
  const inbox = useAsync<{ mentions: MentionRow[] }>(
    () => api.get<{ mentions: MentionRow[] }>(`/circles/${circleId}/inbox`),
    [circleId],
  );
  const queue = useAsync<{ data: AgentActionRow[] }>(
    () => api.get<{ data: AgentActionRow[] }>(`/circles/${circleId}/agent-actions`),
    [circleId],
  );

  if (overview.loading) {
    return (
      <Panel>
        <Loading what="mission state" />
      </Panel>
    );
  }
  if (overview.error) return <ErrorNote error={overview.error} />;

  const data = overview.data;
  if (!data) return null;

  const mentions = inbox.data?.mentions ?? [];
  const waitingAgents = queue.data?.data ?? [];
  const blockers = data.commitments.filter((c) => c.overdue || c.status === "blocked");
  const tree = goals.data ?? [];

  return (
    <div className="grid gap-5 lg:grid-cols-[minmax(0,1.85fr)_minmax(0,1fr)]">
      <div className="space-y-5">
        <NeedsYou
          circleId={circleId}
          mentions={mentions}
          agentActions={waitingAgents}
          nextDecision={data.next_decision}
          pendingCount={data.pending_decisions.length}
          canApprove={canApprove}
          onRead={inbox.reload}
        />

        <PlanSummary circleId={circleId} goals={tree} loading={goals.loading} />

        {blockers.length > 0 && (
          <Panel title="Blocked or overdue" tone="signal" className="lay-in">
            <ul>
              {blockers.map((c) => (
                <Line
                  key={c.id}
                  title={c.title}
                  owner={c.owner}
                  when={relativeDays(c.due_at)}
                  urgent
                />
              ))}
            </ul>
          </Panel>
        )}

        <Panel
          title="Active commitments"
          meta={
            <Meta>
              {data.commitments.length} open · {data.overdue_count} overdue
            </Meta>
          }
          className="lay-in"
        >
          {data.commitments.length === 0 ? (
            <Empty>Nobody has committed to anything yet.</Empty>
          ) : (
            <ul>
              {data.commitments.map((c) => (
                <Line
                  key={c.id}
                  title={c.title}
                  owner={c.owner}
                  when={relativeDays(c.due_at)}
                  urgent={c.overdue}
                  chip={<StatusChip status={c.status} />}
                />
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
          onRan={overview.reload}
        />

        <Panel title="Recent approvals" className="lay-in">
          {data.recent_approvals.length === 0 ? (
            <Empty>Nothing has been approved yet.</Empty>
          ) : (
            <ul>
              {data.recent_approvals.map((d) => (
                <li
                  key={d.id}
                  className="border-t border-[var(--rule)] px-5 py-3 first:border-t-0"
                >
                  <div className="flex items-start justify-between gap-2.5">
                    <span className="text-sm leading-snug">{d.title}</span>
                    <StatusChip
                      status={d.status}
                      tone={d.status === "approved" ? "settled" : "signal"}
                    />
                  </div>
                  <p className="mt-1.5 text-xs text-[var(--ink-faint)]">
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
 * The one card on this page that is about the reader rather than the mission.
 *
 * Mentions come first because they are the only signal a person was actually
 * addressed; an agent waiting is next because it is blocked until someone acts.
 * A pending decision is last — real, but it has a named approver and a date.
 */
function NeedsYou({
  circleId,
  mentions,
  agentActions,
  nextDecision,
  pendingCount,
  canApprove,
  onRead,
}: {
  circleId: string;
  mentions: MentionRow[];
  agentActions: AgentActionRow[];
  nextDecision: Overview["next_decision"];
  pendingCount: number;
  canApprove: boolean;
  onRead: () => void;
}) {
  const total = mentions.length + (canApprove ? agentActions.length : 0);
  const quiet = total === 0 && !nextDecision;

  return (
    <Panel
      title="Waiting on you"
      tone={total > 0 ? "signal" : "default"}
      className="lay-in"
      meta={
        mentions.length > 0 && (
          <Button
            variant="quiet"
            onClick={async () => {
              await api.post(`/circles/${circleId}/mentions/read`);
              onRead();
            }}
          >
            Mark mentions read
          </Button>
        )
      }
    >
      {quiet ? (
        <Empty>Nothing is waiting on you.</Empty>
      ) : (
        <ul>
          {mentions.map((m) => (
            <li
              key={m.id}
              className="border-t border-[var(--rule)] px-5 py-3 first:border-t-0"
            >
              <div className="flex flex-wrap items-center gap-2">
                <span className="rounded-[var(--r-chip)] bg-[var(--accent-soft)] px-2 py-0.5 text-xs font-[560] text-[var(--accent)]">
                  Mentioned
                </span>
                <span className="text-xs text-[var(--ink-faint)]">
                  on a {m.subject.type.replace(/_/g, " ")} ·{" "}
                  {formatDate(m.created_at, true)}
                </span>
              </div>
              <p className="mt-1.5 text-sm leading-snug text-[var(--ink)]">{m.excerpt}</p>
              {m.subject.type === "goal" && (
                <a
                  href={`/circles/${circleId}`}
                  className="mt-1 inline-block text-xs text-[var(--accent)] no-underline hover:underline"
                >
                  Open in Plan
                </a>
              )}
            </li>
          ))}

          {canApprove &&
            agentActions.map((a) => (
              <li
                key={a.id}
                className="border-t border-[var(--rule)] px-5 py-3 first:border-t-0"
              >
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-sm font-[560] text-[var(--ink)]">
                    {a.tool_name}
                  </span>
                  <EffectChip effect={a.side_effect} />
                  <span className="text-xs text-[var(--ink-faint)]">
                    {a.agent ?? "An agent"} wants to run this
                  </span>
                </div>
                {a.intent && (
                  <p className="mt-1.5 text-[0.8125rem] leading-snug text-[var(--ink-muted)]">
                    {a.intent}
                  </p>
                )}
                <a
                  href={`/circles/${circleId}/agents`}
                  className="mt-1 inline-block text-xs text-[var(--accent)] no-underline hover:underline"
                >
                  Decide in Agents
                </a>
              </li>
            ))}

          {nextDecision && (
            <li className="border-t border-[var(--rule)] px-5 py-3 first:border-t-0">
              <a
                href={`/circles/${circleId}/decisions`}
                className="text-sm font-[560] text-[var(--ink)] no-underline hover:text-[var(--accent)]"
              >
                {nextDecision.title}
              </a>
              <p className="mt-1 text-xs text-[var(--ink-faint)]">
                Decision for {nextDecision.approver ?? "nobody yet"}
                {nextDecision.expires_at && <> · {relativeDays(nextDecision.expires_at)}</>}
                {pendingCount > 1 && <> · {pendingCount - 1} other pending</>}
              </p>
            </li>
          )}
        </ul>
      )}
    </Panel>
  );
}

/**
 * The plan, small.
 *
 * Top-level goals only, with derived progress. The full tree is one tab away,
 * and reproducing it here would mean two places to keep honest.
 */
function PlanSummary({
  circleId,
  goals,
  loading,
}: {
  circleId: string;
  goals: Goal[];
  loading: boolean;
}) {
  return (
    <Panel
      title="The plan"
      className="lay-in"
      meta={
        <a
          href={`/circles/${circleId}`}
          className="text-xs text-[var(--accent)] no-underline hover:underline"
        >
          Open Plan
        </a>
      }
    >
      {loading ? (
        <Loading what="the plan" />
      ) : goals.length === 0 ? (
        <Empty>
          No goals yet. The plan is what everything else hangs off — start it in
          Plan.
        </Empty>
      ) : (
        <ul>
          {goals.map((g) => (
            <li
              key={g.id}
              className="border-t border-[var(--rule)] px-5 py-3 first:border-t-0"
            >
              <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5">
                <span className="min-w-0 flex-1 text-sm leading-snug text-[var(--ink)]">
                  {g.title}
                </span>
                <span className="flex shrink-0 items-center gap-3">
                  {g.responsible_party && (
                    <span className="text-xs text-[var(--ink-faint)]">
                      {g.responsible_party.label}
                    </span>
                  )}
                  <span
                    className={`text-[0.8125rem] tabular ${
                      g.is_overdue
                        ? "font-[560] text-[var(--signal)]"
                        : "text-[var(--ink-faint)]"
                    }`}
                  >
                    {g.due_at ? relativeDays(g.due_at) : "—"}
                  </span>
                  <Bar value={g.progress} />
                </span>
              </div>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

/** A progress bar narrow enough to sit in a list row without becoming a chart. */
function Bar({ value }: { value: number }) {
  return (
    <span className="flex items-center gap-1.5">
      <span className="block h-1 w-14 overflow-hidden rounded-full bg-[var(--paper-sunk)]">
        <span
          className="block h-full rounded-full bg-[var(--accent)] transition-[width] duration-300"
          style={{ width: `${Math.max(value, 2)}%` }}
        />
      </span>
      <span className="tabular w-8 text-right text-xs text-[var(--ink-faint)]">
        {value}%
      </span>
    </span>
  );
}

/**
 * One commitment in a list. Title on the left, everything about its state on
 * the right in a fixed order, so a column of them can be scanned rather than
 * read one at a time.
 */
function Line({
  title,
  owner,
  when,
  urgent,
  chip,
}: {
  title: string;
  owner: string | null;
  when: string;
  urgent?: boolean;
  chip?: ReactNode;
}) {
  return (
    <li className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-[var(--rule)] px-5 py-3 first:border-t-0">
      <span className="min-w-0 flex-1 text-sm leading-snug">{title}</span>
      <span className="flex shrink-0 items-center gap-3">
        {chip}
        <span className="text-[0.8125rem] text-[var(--ink-muted)]">
          {owner ?? "Unassigned"}
        </span>
        <span
          className={`w-24 text-right text-[0.8125rem] tabular ${
            urgent ? "font-[560] text-[var(--signal)]" : "text-[var(--ink-faint)]"
          }`}
        >
          {when}
        </span>
      </span>
    </li>
  );
}

/**
 * The Steward's latest brief. Rendered in the derived treatment — indigo
 * accent, explicit chip — because it is a machine reading of the evidence, not
 * a finding.
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
      {!!runError && (
        <div className="px-5 pb-4">
          <ErrorNote error={runError} />
        </div>
      )}

      {!brief ? (
        <Empty>The Steward has not produced a brief yet.</Empty>
      ) : (
        <div className="space-y-4 px-5 pb-5">
          <DerivedStamp />

          {content?.summary && (
            <p className="text-[0.9375rem] leading-relaxed">{content.summary}</p>
          )}

          {content?.status && (
            <p className="text-[0.8125rem] text-[var(--ink-muted)]">
              Assessed as{" "}
              <span className="font-[590] capitalize text-[var(--derived)]">
                {String(content.status).replace(/_/g, " ")}
              </span>
            </p>
          )}

          {content?.uncertainty && (
            <div className="rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3.5 py-3">
              <p className="label">What it could not determine</p>
              <p className="mt-1 text-sm leading-relaxed text-[var(--ink-muted)]">
                {content.uncertainty}
              </p>
            </div>
          )}

          {Array.isArray(content?.missing_evidence) && content.missing_evidence.length > 0 && (
            <div>
              <p className="label">Evidence it says is missing</p>
              <ul className="mt-1.5 space-y-1.5">
                {content.missing_evidence.map((m: any, i: number) => (
                  <li
                    key={i}
                    className="flex gap-2 text-sm leading-snug text-[var(--ink-muted)]"
                  >
                    <span className="text-[var(--derived)]" aria-hidden="true">•</span>
                    {m.description}
                  </li>
                ))}
              </ul>
            </div>
          )}

          <p className="border-t border-[var(--rule)] pt-3 text-xs text-[var(--ink-faint)]">
            {brief.model ?? "unknown model"} · {formatDate(brief.created_at, true)}
            {brief.agent_run_id && (
              <>
                {" · "}
                <a
                  href={`/circles/${circleId}/history`}
                  className="text-[var(--accent)] no-underline hover:underline"
                >
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
