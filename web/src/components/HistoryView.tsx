import { useState } from "react";
import { api, formatDate, type AuditEventRow, type ChainStatus } from "../lib/api";
import { CircleFrame } from "./CircleFrame";
import { Copyable, Empty, ErrorNote, Loading, Panel, useAsync, filterClass, Button } from "./ui";

interface HistoryPayload {
  data: AuditEventRow[];
  meta: { chain: ChainStatus };
}

const ACTOR_TONE: Record<string, string> = {
  user: "text-[var(--ink)]",
  agent: "text-[var(--derived)]",
  system: "text-[var(--ink-faint)]",
};

/**
 * The History view (spec §12): the audit stream, with the chain verified live.
 *
 * The verification banner is the point of the screen. A tamper-evident log that
 * never tells you whether it currently verifies is just a list.
 */
export function HistoryView({ circleId }: { circleId: string }) {
  return (
    <CircleFrame circleId={circleId} tab="history">
      {() => <Body circleId={circleId} />}
    </CircleFrame>
  );
}

function Body({ circleId }: { circleId: string }) {
  const [type, setType] = useState("");
  const [actor, setActor] = useState("");

  const { data, error, loading } = useAsync<HistoryPayload>(
    () =>
      api.get<HistoryPayload>(
        `/circles/${circleId}/history?limit=500` +
          (type ? `&event_type=${encodeURIComponent(type)}` : "") +
          (actor ? `&actor_type=${actor}` : ""),
      ),
    [circleId, type, actor],
  );

  if (loading) return <Panel><Loading what="history" /></Panel>;
  if (error) return <ErrorNote error={error} />;
  if (!data) return null;

  const chain = data.meta.chain;
  const eventTypes = Array.from(new Set(data.data.map((e) => e.event_type))).sort();

  return (
    <div className="space-y-5">
      <ChainBanner chain={chain} circleId={circleId} />

      <Panel
        title="Every recorded action"
        meta={<span className="text-xs text-[var(--ink-faint)]">{data.data.length} shown</span>}
      >
        <div className="flex flex-wrap gap-2 px-5 pb-4">
          <select value={actor} onChange={(e) => setActor(e.target.value)} className={filterClass}>
            <option value="">anyone</option>
            <option value="user">people</option>
            <option value="agent">agents</option>
            <option value="system">the system</option>
          </select>
          <select value={type} onChange={(e) => setType(e.target.value)} className={filterClass}>
            <option value="">any event</option>
            {eventTypes.map((t) => (
              <option key={t} value={t}>{t}</option>
            ))}
          </select>
        </div>

        {data.data.length === 0 ? (
          <Empty>No events match.</Empty>
        ) : (
          <ol>
            {data.data.map((event, i) => (
              <EventCard key={event.id} event={event} index={i} />
            ))}
          </ol>
        )}
      </Panel>
    </div>
  );
}

function ChainBanner({ chain, circleId }: { chain: ChainStatus; circleId: string }) {
  const [state, setState] = useState(chain);
  const [busy, setBusy] = useState(false);

  async function reverify() {
    setBusy(true);
    try {
      const res = await api.get<{ data: ChainStatus }>(`/circles/${circleId}/history/verify`);
      setState(res.data);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Panel
      title="Tamper evidence"
      tone={state.valid ? "default" : "signal"}
      meta={
        <Button variant="quiet" onClick={reverify} disabled={busy}>
          {busy ? "Checking…" : "Re-verify"}
        </Button>
      }
    >
      {/*
        The verdict leads, at a size you cannot skim past, with the count of
        what was actually checked next to it. Everything below is the reasoning.
      */}
      <div className="px-5 pb-5">
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
          <span
            className="inline-flex items-center gap-2 rounded-[var(--r-chip)] px-3 py-1.5"
            style={{
              color: state.valid ? "var(--settled)" : "var(--signal)",
              background: state.valid ? "var(--settled-soft)" : "var(--signal-soft)",
            }}
          >
            <span className="inline-block size-2 rounded-full bg-current" aria-hidden="true" />
            <span className="text-[0.9375rem] font-[620]">
              {state.valid ? "Chain intact" : "Chain broken"}
            </span>
          </span>
          <span className="text-[0.8125rem] text-[var(--ink-muted)] tabular">
            {state.events_checked} events re-hashed and checked in order
          </span>
        </div>

        {state.valid ? (
          <p className="mt-3 max-w-3xl text-sm leading-relaxed text-[var(--ink-muted)]">
            Every event's hash was recomputed from its contents and matched, and
            every event links to the one before it. Nothing in this Circle's
            record has been altered, removed or reordered since it was written.
          </p>
        ) : (
          <p className="mt-3 max-w-3xl text-sm leading-relaxed text-[var(--ink)]">
            {state.reason}
            {state.broken_at_event_id && (
              <>
                {" "}First break at event{" "}
                <span className="mono text-xs">{state.broken_at_event_id}</span>.
              </>
            )}
          </p>
        )}

        <p className="mt-3 max-w-3xl text-xs leading-relaxed text-[var(--ink-faint)]">
          Each event hashes its own contents together with the previous event's
          hash, which makes tampering detectable. It covers this app's own record
          only — it is not an independently anchored ledger.
        </p>
      </div>
    </Panel>
  );
}

function EventCard({ event, index }: { event: AuditEventRow; index: number }) {
  const [open, setOpen] = useState(false);
  const denied = event.event_type === "access.denied";

  return (
    <li
      className={`lay-in border-t border-[var(--rule)] ${denied ? "bg-[var(--signal-soft)]" : ""}`}
      style={{ animationDelay: `${Math.min(index, 24) * 18}ms` }}
    >
      <div className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-baseline gap-3 px-5 py-3">
        <span className="text-xs text-[var(--ink-faint)] tabular">
          #{event.sequence}
        </span>

        <div className="min-w-0">
          <p className={`text-sm leading-snug ${denied ? "text-[var(--signal)]" : ""}`}>
            {event.summary}
          </p>
          <p className="text-xs text-[var(--ink-faint)]">
            <span className={ACTOR_TONE[event.actor_type]}>{event.actor_type}</span>
            {" · "}
            {event.event_type}
            {event.resource_version && ` · v${event.resource_version}`}
          </p>
        </div>

        <div className="flex shrink-0 items-baseline gap-3">
          <span className="text-xs text-[var(--ink-faint)]">
            {formatDate(event.occurred_at, true)}
          </span>
          <button
            onClick={() => setOpen((v) => !v)}
            className="rounded-md px-1.5 py-0.5 text-xs font-[560] text-[var(--accent)] transition-colors hover:bg-[var(--accent-soft)]"
          >
            {open ? "Hide" : "Raw"}
          </button>
        </div>
      </div>

      {open && (
        <div className="border-t border-[var(--rule)] bg-[var(--paper-inset)] px-5 py-4">
          <div className="grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
            <p className="mono text-[0.6875rem]">
              <span className="label">event</span> <Copyable value={event.id} />
            </p>
            <p className="mono text-[0.6875rem]">
              <span className="label">hash</span> <Copyable value={event.event_hash} truncate={24} />
            </p>
            <p className="mono text-[0.6875rem]">
              <span className="label">prev</span>{" "}
              {event.previous_hash === "GENESIS" ? (
                <span className="text-[var(--ink-faint)]">GENESIS (first event)</span>
              ) : (
                <Copyable value={event.previous_hash ?? ""} truncate={24} />
              )}
            </p>
            {event.resource_id && (
              <p className="mono text-[0.6875rem]">
                <span className="label">subject</span> {event.resource_type} <Copyable value={event.resource_id} truncate={14} />
              </p>
            )}
          </div>

          {event.metadata && Object.keys(event.metadata).length > 0 && (
            <pre className="mono mt-2.5 overflow-x-auto rounded-[var(--r-control)] bg-[var(--paper-inset)] p-3 text-[0.6875rem] leading-relaxed">
              {JSON.stringify(event.metadata, null, 2)}
            </pre>
          )}
        </div>
      )}
    </li>
  );
}
