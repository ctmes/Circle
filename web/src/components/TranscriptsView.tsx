import { useEffect, useState } from "react";
import { api, clearToken, formatDate } from "../lib/api";
import {
  createConnectorToken,
  getImport,
  inFlight,
  listConnectorTokens,
  listImports,
  retryImport,
  revokeConnectorToken,
  sendTranscript,
  type ConnectorToken,
  type TranscriptImport,
} from "../lib/transcripts";
import { ThemeToggle } from "./ThemeToggle";
import { DerivedStamp } from "./Trust";
import {
  Button,
  Copyable,
  Empty,
  ErrorNote,
  Field,
  Loading,
  Meta,
  Panel,
  inputClass,
  useAsync,
} from "./ui";
import { Wordmark } from "./Wordmark";

/**
 * Meeting transcripts in, and what each one did (spec §24).
 *
 * Three things on one page, because they are one feature seen from three
 * sides: sending a meeting in by hand, connecting a note-taker so meetings
 * arrive on their own, and the log of what every meeting changed.
 *
 * The log is the part that matters most. Nothing a transcript does is checked
 * by a person before it happens — that is the point — so this page is where a
 * person finds out afterwards. Every line says where the meeting went, why it
 * went there, what it changed, and the words from the meeting each change rests
 * on, with the model-made content in the derived treatment used everywhere else.
 */

interface Org {
  id: string;
  name: string;
  slug: string;
}

const API_BASE = import.meta.env.PUBLIC_API_URL ?? "http://localhost:8000/api";

export function TranscriptsView() {
  const { data: orgs } = useAsync<Org[]>(
    () => api.get<{ data: Org[] }>("/organisations").then((r) => r.data),
    [],
  );
  const [organisationId, setOrganisationId] = useState("");

  useEffect(() => {
    if (!organisationId && orgs?.length) setOrganisationId(orgs[0].id);
  }, [orgs, organisationId]);

  const [imports, setImports] = useState<TranscriptImport[] | null>(null);
  const [error, setError] = useState<unknown>(null);

  async function refresh() {
    try {
      setImports(await listImports(organisationId || undefined));
    } catch (e) {
      setError(e);
    }
  }

  useEffect(() => {
    if (organisationId) void refresh();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [organisationId]);

  // A transcript is processed in the background. Poll while anything is in
  // flight, and stop the moment nothing is — a page left open all day should
  // not be making requests all day.
  useEffect(() => {
    if (!imports || !inFlight(imports)) return;
    const t = setTimeout(() => void refresh(), 3000);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [imports]);

  return (
    <div className="min-h-screen">
      <header className="blurbar sticky top-0 z-20 border-b border-[var(--rule)]">
        <div className="mx-auto flex max-w-[900px] items-center justify-between px-6 py-3">
          <a href="/circles" className="no-underline">
            <Wordmark size={17} />
          </a>
          <div className="flex items-center gap-1">
            <a
              href="/circles"
              className="rounded-[var(--r-control)] px-2.5 py-1.5 text-[0.8125rem] text-[var(--ink-muted)] no-underline transition-colors hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)]"
            >
              Circles
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

      <main className="mx-auto max-w-[900px] space-y-6 px-6 py-9">
        <div>
          <h1 className="display text-[2rem] font-[680] leading-tight">Meetings</h1>
          <p className="mt-2 max-w-xl text-[0.9375rem] leading-relaxed text-[var(--ink-muted)]">
            Send in a meeting transcript and the Circle it was about is updated: work agreed is
            added, work reported done is closed, work dropped is abandoned, and dates that moved
            are moved. Nobody approves each change — every one is logged below with the words
            from the meeting it rests on.
          </p>
        </div>

        {(orgs?.length ?? 0) > 1 && (
          <Field label="Company">
            <select
              value={organisationId}
              onChange={(e) => setOrganisationId(e.target.value)}
              className={inputClass}
            >
              {(orgs ?? []).map((o) => (
                <option key={o.id} value={o.id}>
                  {o.name}
                </option>
              ))}
            </select>
          </Field>
        )}

        {!!error && <ErrorNote error={error} />}

        <SendPanel organisationId={organisationId} onSent={refresh} />

        <Log imports={imports} onChanged={refresh} />

        <ConnectorPanel organisationId={organisationId} />
      </main>
    </div>
  );
}

// ------------------------------------------------------------------- send

function SendPanel({ organisationId, onSent }: { organisationId: string; onSent: () => void }) {
  const [title, setTitle] = useState("");
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  async function send() {
    setBusy(true);
    setError(null);
    try {
      await sendTranscript({
        organisation_id: organisationId,
        text,
        title: title.trim() || undefined,
        occurred_at: date || undefined,
      });
      setText("");
      setTitle("");
      onSent();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Panel title="Send a meeting" className="lay-in">
      <div className="space-y-4 px-5 pb-5">
        <div className="grid gap-4 sm:grid-cols-[1fr_12rem]">
          <Field label="Title" hint="What the meeting was. Helps it find the right Circle.">
            <input
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              className={inputClass}
              placeholder="Bay Junction weekly site meeting"
            />
          </Field>
          <Field label="Held on">
            <input
              type="date"
              value={date}
              onChange={(e) => setDate(e.target.value)}
              className={inputClass}
            />
          </Field>
        </div>

        <Field
          label="Transcript"
          hint="Paste it as it came out of the note-taker. Speaker names help it tell who took what on."
        >
          <textarea
            value={text}
            onChange={(e) => setText(e.target.value)}
            rows={8}
            className={`${inputClass} resize-y font-mono text-[0.8125rem] leading-relaxed`}
            placeholder={"Dana: The crane pad went down on Tuesday.\nPriya: Northline will issue the traffic plan within ten working days."}
          />
        </Field>

        {!!error && <ErrorNote error={error} />}

        <Button variant="primary" onClick={send} disabled={busy || !text.trim() || !organisationId}>
          {busy ? "Sending…" : "Send"}
        </Button>

        <p className="border-t border-[var(--rule)] pt-3 text-xs leading-snug text-[var(--ink-faint)]">
          It goes to whichever of your Circles it was about, opens a new one if it was the start
          of new work, and is set aside if it was not about work at all. The transcript is filed
          in that Circle as evidence; a meeting set aside is not kept.
        </p>
      </div>
    </Panel>
  );
}

// -------------------------------------------------------------------- log

const STATUS: Record<string, { label: string; tone: string }> = {
  pending: { label: "queued", tone: "var(--ink-faint)" },
  processing: { label: "reading", tone: "var(--ink-muted)" },
  applied: { label: "applied", tone: "var(--settled, var(--ink))" },
  failed: { label: "failed", tone: "var(--signal)" },
  duplicate: { label: "duplicate", tone: "var(--ink-faint)" },
  ignored: { label: "set aside", tone: "var(--ink-faint)" },
};

const ROUTING: Record<string, string> = {
  routed_to_existing: "matched to",
  opened_new: "opened",
  pinned: "sent to",
};

function Log({ imports, onChanged }: { imports: TranscriptImport[] | null; onChanged: () => void }) {
  return (
    <Panel
      title="What meetings changed"
      className="lay-in"
      meta={imports && <Meta>{imports.length}</Meta>}
    >
      {imports === null ? (
        <Loading what="log" />
      ) : imports.length === 0 ? (
        <Empty>No meetings yet. Send one above, or connect a note-taker below.</Empty>
      ) : (
        <ul className="divide-y divide-[var(--rule)]">
          {imports.map((i) => (
            <LogLine key={i.id} item={i} onChanged={onChanged} />
          ))}
        </ul>
      )}
    </Panel>
  );
}

function LogLine({ item, onChanged }: { item: TranscriptImport; onChanged: () => void }) {
  const [open, setOpen] = useState(false);
  const [detail, setDetail] = useState<TranscriptImport | null>(null);
  const [error, setError] = useState<unknown>(null);
  const status = STATUS[item.status] ?? { label: item.status, tone: "var(--ink-muted)" };

  async function toggle() {
    const next = !open;
    setOpen(next);
    if (next && !detail) {
      try {
        setDetail(await getImport(item.id));
      } catch (e) {
        setError(e);
      }
    }
  }

  async function retry() {
    try {
      await retryImport(item.id);
      onChanged();
    } catch (e) {
      setError(e);
    }
  }

  const c = item.counts ?? {};
  const changes = [
    [c.created ?? c.goals, "added"],
    [c.updated, "updated"],
    [c.completed, "closed"],
    [c.abandoned, "dropped"],
    [c.commitments, "commitments"],
    [c.failed, "failed"],
  ].filter(([n]) => (n as number | undefined) ?? 0) as Array<[number, string]>;

  return (
    <li className="px-5 py-3.5">
      <button onClick={toggle} className="block w-full text-left">
        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
          <span className="text-sm font-[560]">{item.title ?? "Untitled meeting"}</span>
          <span className="text-xs font-[560]" style={{ color: status.tone }}>
            {status.label}
          </span>
          <span className="text-xs text-[var(--ink-faint)]">
            {formatDate(item.occurred_at ?? item.created_at)} · {item.source}
          </span>
        </div>

        <div className="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-1 text-xs text-[var(--ink-muted)]">
          {item.circle && item.routing && (
            <span>
              {ROUTING[item.routing] ?? "in"}{" "}
              <a
                href={`/circles/${item.circle.id}`}
                onClick={(e) => e.stopPropagation()}
                className="font-[560]"
              >
                {item.circle.name ?? "a Circle"}
              </a>
            </span>
          )}
          {changes.map(([n, label]) => (
            <span key={label}>
              <span className="mono text-[var(--ink)]">{n}</span> {label}
            </span>
          ))}
          {item.status === "applied" && changes.length === 0 && <span>no changes</span>}
        </div>
      </button>

      {item.error && (
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <p className="text-xs leading-snug text-[var(--signal)]">{item.error}</p>
          {item.status === "failed" && (
            <Button variant="quiet" onClick={retry}>
              Retry
            </Button>
          )}
        </div>
      )}

      {open && (
        <div className="mt-3 space-y-3">
          {!!error && <ErrorNote error={error} />}
          {!detail && !error && <Loading what="changes" />}
          {detail && <Detail item={detail} />}
        </div>
      )}
    </li>
  );
}

function Detail({ item }: { item: TranscriptImport }) {
  return (
    <div className="space-y-3 rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3.5 py-3">
      <DerivedStamp compact />

      {item.routing_reason && (
        <p className="text-xs leading-snug text-[var(--ink-muted)]">
          <span className="label">Why here </span>
          {item.routing_reason}
        </p>
      )}

      {item.summary && <p className="text-sm leading-relaxed">{item.summary}</p>}

      {(item.actions ?? []).length > 0 && (
        <ul className="space-y-2.5">
          {(item.actions ?? []).map((a, n) => (
            <li key={n} className="text-sm leading-snug">
              <div className="flex flex-wrap items-baseline gap-x-2">
                <span className="font-[560]">{VERB[a.op] ?? a.op}</span>
                <span>{a.title ?? "—"}</span>
                {a.status !== "executed" && (
                  <span className="text-xs text-[var(--signal)]">{a.status}</span>
                )}
              </div>
              {a.evidence && (
                <p className="mt-0.5 border-l-2 border-[var(--rule-strong)] pl-2.5 text-xs italic text-[var(--ink-faint)]">
                  “{a.evidence}”
                </p>
              )}
              {a.due_note && (
                <p className="mt-0.5 text-[0.6875rem] text-[var(--derived)]">computed: {a.due_note}</p>
              )}
              {a.error && <p className="mt-0.5 text-xs text-[var(--signal)]">{a.error}</p>}
            </li>
          ))}
        </ul>
      )}

      {item.uncertainty && (
        <div>
          <p className="label">Heard but not acted on</p>
          <p className="mt-1 text-xs leading-relaxed text-[var(--ink-muted)]">{item.uncertainty}</p>
        </div>
      )}

      <p className="text-[0.6875rem] text-[var(--ink-faint)]">
        Sent by {item.submitted_by.name ?? "someone"} · {item.chars.toLocaleString()} characters
        {item.processed_at && ` · processed ${formatDate(item.processed_at, true)}`}
      </p>
    </div>
  );
}

const VERB: Record<string, string> = {
  create_goal: "Added",
  update_goal: "Updated",
  complete_goal: "Closed",
  abandon_goal: "Dropped",
  create_commitment: "Commitment",
};

// -------------------------------------------------------------- connector

/**
 * Connecting a note-taker so meetings arrive by themselves.
 *
 * Granola, Otter, Fireflies and the rest do not share a webhook format, so
 * this does not pretend to integrate with any one of them. It issues a token
 * that can do exactly one thing — post a transcript to this company — and
 * says how to point an automation at it. Zapier and Make both connect to all
 * of the common note-takers; this is the other end of that connection.
 */
function ConnectorPanel({ organisationId }: { organisationId: string }) {
  const { data: tokens, mutate, error: loadError } = useAsync<ConnectorToken[]>(
    () => listConnectorTokens(),
    [],
  );
  const [name, setName] = useState("Granola via Zapier");
  const [fresh, setFresh] = useState<ConnectorToken | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  async function create() {
    setBusy(true);
    setError(null);
    try {
      const token = await createConnectorToken(organisationId, name.trim());
      setFresh(token);
      mutate((all) => [token, ...(all ?? [])]);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  async function revoke(id: number | string) {
    try {
      await revokeConnectorToken(id);
      mutate((all) => (all ?? []).filter((t) => t.id !== id));
      if (fresh?.id === id) setFresh(null);
    } catch (e) {
      setError(e);
    }
  }

  const mine = (tokens ?? []).filter((t) => !organisationId || t.organisation_id === organisationId);
  const endpoint = `${API_BASE}/transcripts`;

  return (
    <Panel title="Connect a note-taker" className="lay-in">
      <div className="space-y-4 px-5 pb-5">
        <p className="text-sm leading-relaxed text-[var(--ink-muted)]">
          Point your note-taker's automation at Circle and every meeting it records is sent in by
          itself. With Granola, Otter or Fireflies that is usually a Zapier or Make step: when a
          note is ready, send a webhook.
        </p>

        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-[14rem] flex-1">
            <Field label="Name this connection">
              <input value={name} onChange={(e) => setName(e.target.value)} className={inputClass} />
            </Field>
          </div>
          <Button onClick={create} disabled={busy || !name.trim() || !organisationId}>
            {busy ? "Creating…" : "Create token"}
          </Button>
        </div>

        {!!(error || loadError) && <ErrorNote error={error ?? loadError} />}

        {fresh?.token && (
          <div className="space-y-3 rounded-[var(--r-control)] border border-[var(--rule-strong)] px-3.5 py-3">
            <p className="text-sm font-[560]">Copy this now — it is not shown again.</p>
            <Copyable value={fresh.token} />

            <div className="space-y-1.5 text-xs leading-relaxed text-[var(--ink-muted)]">
              <p className="label">In Zapier or Make, add a webhook step</p>
              <p>
                <span className="mono">POST</span> <Copyable value={endpoint} />
              </p>
              <p>
                Header <span className="mono">Authorization: Bearer &lt;the token above&gt;</span>
              </p>
              <p>JSON body, mapping your note-taker's fields:</p>
              <pre className="overflow-x-auto rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3 py-2 text-[0.75rem] leading-relaxed">
{`{
  "transcript":   "<the transcript text>",
  "title":        "<the meeting title>",
  "occurred_at":  "<the meeting date>",
  "external_id":  "<the note's id>",
  "source":       "granola"
}`}
              </pre>
              <p>
                <span className="mono">external_id</span> matters: automations retry, and it is
                how a meeting sent twice is applied once.
              </p>
            </div>
          </div>
        )}

        {mine.length > 0 && (
          <ul className="divide-y divide-[var(--rule)]">
            {mine.map((t) => (
              <li key={t.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                <div>
                  <p className="text-sm font-[560]">{t.name}</p>
                  <p className="text-xs text-[var(--ink-faint)]">
                    {t.last_used_at ? `last used ${formatDate(t.last_used_at, true)}` : "not used yet"}
                  </p>
                </div>
                <Button variant="danger" onClick={() => revoke(t.id)}>
                  Revoke
                </Button>
              </li>
            ))}
          </ul>
        )}

        <p className="border-t border-[var(--rule)] pt-3 text-xs leading-snug text-[var(--ink-faint)]">
          A connector token can send transcripts to this company and do nothing else — it cannot
          read a Circle, read this log, or reach any other part of your account. Meetings it sends
          run under your name.
        </p>
      </div>
    </Panel>
  );
}
