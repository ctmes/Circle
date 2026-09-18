import { useState } from "react";
import {
  api,
  formatDate,
  formatFee,
  relativeDays,
  type AgentProspectus,
  type Engagement,
  type WorkApplication,
  type WorkOpening,
  type WorkRecordPage,
} from "../lib/api";
import { Button, Empty, ErrorNote, Field, Loading, Panel, inputClass, useAsync } from "./ui";

/*
  The one screen in this product that is not inside a Circle (spec §21).

  Everything else answers "what is happening in this mission". This answers
  three questions a Circle cannot: what work is open to me, what am I currently
  contracted to do, and what have I done before. The first two span several
  Circles and the third outlives them, which is why none of it hangs off a
  Circle id.

  The tabs are deliberately in that order — find work, do work, show work — and
  the record is last because it is the only one that accumulates.
*/

const TABS = [
  { key: "board", label: "Open work" },
  { key: "applications", label: "My bids" },
  { key: "engagements", label: "Engagements" },
  { key: "record", label: "Track record" },
  { key: "agents", label: "Agents for hire" },
] as const;

type TabKey = (typeof TABS)[number]["key"];

export function MarketView({ initialTab = "board" }: { initialTab?: TabKey }) {
  const [tab, setTab] = useState<TabKey>(initialTab);

  return (
    <div className="space-y-5">
      <header className="space-y-1">
        <h1 className="text-xl font-semibold tracking-tight text-[var(--ink)]">Work</h1>
        <p className="max-w-2xl text-sm text-[var(--ink-soft)]">
          Openings other companies have posted, the contracts you hold, and the record
          those contracts leave behind. Nothing here belongs to one Circle.
        </p>
      </header>

      <nav className="flex flex-wrap gap-1 border-b border-[var(--rule)]" aria-label="Work sections">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setTab(t.key)}
            aria-current={tab === t.key ? "page" : undefined}
            className={`-mb-px border-b-2 px-3 py-2 text-[0.8125rem] transition-colors ${
              tab === t.key
                ? "border-[var(--ink)] font-medium text-[var(--ink)]"
                : "border-transparent text-[var(--ink-faint)] hover:text-[var(--ink-soft)]"
            }`}
          >
            {t.label}
          </button>
        ))}
      </nav>

      {tab === "board" && <Board />}
      {tab === "applications" && <Applications />}
      {tab === "engagements" && <Engagements />}
      {tab === "record" && <Record />}
      {tab === "agents" && <AgentRegistry />}
    </div>
  );
}

// ------------------------------------------------------------------- board

function Board() {
  const { data, error, loading, reload } = useAsync<{
    data: WorkOpening[];
    meta: { network_size: number };
  }>(() => api.get("/work"), []);

  if (loading) return <Panel><Loading what="open work" /></Panel>;
  if (error) return <ErrorNote error={error} />;

  const openings = data?.data ?? [];
  const network = data?.meta.network_size ?? 0;

  return (
    <Panel
      title="Open work"
      meta={
        <span className="text-xs text-[var(--ink-faint)]">
          {network} {network === 1 ? "company" : "companies"} in your network
        </span>
      }
    >
      {openings.length === 0 ? (
        /*
          Two different emptinesses, and they need different answers. A board
          with nothing on it because nobody is hiring is a waiting problem; a
          board with nothing on it because you have never worked with anybody
          is the cold-start problem, and telling somebody to check back later
          would be the wrong advice.
        */
        <Empty>
          {network === 0
            ? "Openings default to the companies you have completed work with, and you have not been engaged with anyone yet. Work you are invited to directly will still reach you."
            : "Nobody in your network is hiring right now."}
        </Empty>
      ) : (
        openings.map((o) => <OpeningCard key={o.id} opening={o} onApplied={reload} />)
      )}
    </Panel>
  );
}

function OpeningCard({ opening, onApplied }: { opening: WorkOpening; onApplied: () => void }) {
  const [applying, setApplying] = useState(false);

  return (
    <article className="border-b border-[var(--rule)] py-4 last:border-b-0">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-sm font-medium text-[var(--ink)]">{opening.title}</h3>
        <span className="text-xs text-[var(--ink-faint)]">
          {opening.posted_by ?? "a company"}
          {opening.circle_name ? ` · ${opening.circle_name}` : ""}
        </span>
      </div>

      {opening.brief && (
        <p className="mt-1.5 max-w-2xl text-[0.8125rem] leading-relaxed text-[var(--ink-soft)]">
          {opening.brief}
        </p>
      )}

      <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-[var(--ink-faint)]">
        <Pair label="Fee">
          {formatFee(opening.fee_amount_minor, opening.currency)} · {opening.fee_basis_label}
        </Pair>
        <Pair label="Looking for">
          {opening.principal_kind === "either"
            ? "a person or an agent"
            : opening.principal_kind === "agent"
              ? "an agent"
              : "a person"}
        </Pair>
        {opening.term_ends_at && <Pair label="Until">{formatDate(opening.term_ends_at)}</Pair>}
        {opening.closes_at && <Pair label="Closes">{relativeDays(opening.closes_at)}</Pair>}
      </dl>

      {/*
        Why this reached you, said out loud. "You have been engaged with them
        twice" is a materially different invitation from a job-board listing,
        and it is the entire argument for the network being the default.
      */}
      {opening.why_visible && (
        <p className="mt-2 text-xs italic text-[var(--ink-faint)]">{opening.why_visible}</p>
      )}

      {opening.can_apply && (
        <div className="mt-3">
          {applying ? (
            <ApplyForm opening={opening} onDone={() => { setApplying(false); onApplied(); }} />
          ) : (
            <Button variant="quiet" onClick={() => setApplying(true)}>
              Offer to do this
            </Button>
          )}
        </div>
      )}
    </article>
  );
}

function ApplyForm({ opening, onDone }: { opening: WorkOpening; onDone: () => void }) {
  const [statement, setStatement] = useState("");
  const [availability, setAvailability] = useState("");
  const [fee, setFee] = useState(
    opening.fee_amount_minor === null ? "" : String(opening.fee_amount_minor / 100),
  );
  const [organisationId, setOrganisationId] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  const orgs = useAsync<Array<{ id: string; name: string }>>(
    () => api.get<{ data: Array<{ id: string; name: string }> }>("/organisations").then((r) => r.data),
    [],
  );

  const chosen = organisationId || orgs.data?.[0]?.id || "";

  async function submit() {
    setBusy(true);
    setError(null);

    try {
      await api.post(`/work/${opening.id}/applications`, {
        organisation_id: chosen,
        statement: statement || null,
        availability: availability || null,
        fee_amount_minor: fee === "" ? null : Math.round(Number(fee) * 100),
      });
      onDone();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-3 border-l-2 border-[var(--rule)] pl-4">
      {error ? <ErrorNote error={error} /> : null}

      <Field label="Applying on behalf of">
        <select
          className={inputClass}
          value={chosen}
          onChange={(e) => setOrganisationId(e.target.value)}
        >
          {(orgs.data ?? []).map((o) => (
            <option key={o.id} value={o.id}>
              {o.name}
            </option>
          ))}
        </select>
      </Field>

      <Field label="What you would do" hint="The client reads this before anything else.">
        <textarea
          className={inputClass}
          rows={3}
          value={statement}
          onChange={(e) => setStatement(e.target.value)}
        />
      </Field>

      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={`Your fee (${opening.currency})`}>
          <input className={inputClass} value={fee} onChange={(e) => setFee(e.target.value)} />
        </Field>
        <Field label="Availability">
          <input
            className={inputClass}
            value={availability}
            onChange={(e) => setAvailability(e.target.value)}
          />
        </Field>
      </div>

      {/*
        Stated because it is the thing people assume otherwise. Applying is
        not being let in — that is a separate decision by the company posting.
      */}
      <p className="text-xs text-[var(--ink-faint)]">
        Applying gives you no access to their project. If they shortlist you, you are
        admitted to the work you applied for and nothing else.
      </p>

      <div className="flex gap-2">
        <Button variant="primary" onClick={submit} disabled={busy || !chosen}>
          {busy ? "Sending…" : "Send offer"}
        </Button>
        <Button variant="quiet" onClick={onDone}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

// ------------------------------------------------------------ applications

function Applications() {
  const { data, error, loading, reload } = useAsync<WorkApplication[]>(
    () => api.get<{ data: WorkApplication[] }>("/my/applications").then((r) => r.data),
    [],
  );

  if (loading) return <Panel><Loading what="bids" /></Panel>;
  if (error) return <ErrorNote error={error} />;

  const all = data ?? [];

  return (
    <Panel title="Bids you have made">
      {all.length === 0 ? (
        <Empty>You have not offered to do anything yet.</Empty>
      ) : (
        all.map((a) => (
          <div key={a.id} className="border-b border-[var(--rule)] py-3 last:border-b-0">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <span className="text-sm text-[var(--ink)]">{a.opening_title}</span>
              <StatusWord word={a.status_label} tone={a.status} />
            </div>

            <p className="mt-1 text-xs text-[var(--ink-faint)]">
              {a.organisation} · {formatFee(a.fee_amount_minor, a.currency)}
              {a.submitted_at ? ` · sent ${relativeDays(a.submitted_at)}` : ""}
            </p>

            {/*
              A shortlisted bid has a branch waiting on it, and it does nothing
              until it is proposed. Saying so here is the difference between a
              contractor who gets the work and one who waits for a call.
            */}
            {a.status === "shortlisted" && a.branch_status === "draft" && (
              <p className="mt-2 text-xs text-[var(--ink-soft)]">
                You are in. Your assignment is drafted as a branch of their plan — open it,
                adjust the dates if you need to, and propose it. They cannot award the work
                until you do.
              </p>
            )}

            {a.decision_reason && (
              <p className="mt-1 text-xs italic text-[var(--ink-faint)]">{a.decision_reason}</p>
            )}

            {["submitted", "shortlisted"].includes(a.status) && (
              <div className="mt-2">
                <Button
                  variant="quiet"
                  onClick={async () => {
                    await api.post(`/applications/${a.id}/withdraw`);
                    reload();
                  }}
                >
                  Withdraw
                </Button>
              </div>
            )}
          </div>
        ))
      )}
    </Panel>
  );
}

// ------------------------------------------------------------- engagements

function Engagements() {
  const { data, error, loading } = useAsync<Engagement[]>(
    () => api.get<{ data: Engagement[] }>("/my/engagements").then((r) => r.data),
    [],
  );

  if (loading) return <Panel><Loading what="engagements" /></Panel>;
  if (error) return <ErrorNote error={error} />;

  const all = data ?? [];
  const live = all.filter((e) => !["completed", "terminated", "expired"].includes(e.status));
  const done = all.filter((e) => ["completed", "terminated", "expired"].includes(e.status));

  return (
    <div className="space-y-5">
      <Panel title="Current">
        {live.length === 0 ? (
          <Empty>No live contracts.</Empty>
        ) : (
          live.map((e) => <EngagementRow key={e.id} engagement={e} />)
        )}
      </Panel>

      {done.length > 0 && (
        <Panel title="Finished">
          {done.map((e) => <EngagementRow key={e.id} engagement={e} />)}
        </Panel>
      )}
    </div>
  );
}

function EngagementRow({ engagement: e }: { engagement: Engagement }) {
  return (
    <div className="border-b border-[var(--rule)] py-3 last:border-b-0">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <a href={`/circles/${e.circle_id}`} className="text-sm text-[var(--ink)]">
          {e.title}
        </a>
        <StatusWord word={e.status_label} tone={e.status} />
      </div>

      <p className="mt-1 text-xs text-[var(--ink-faint)]">
        {e.engaging_party} → {e.contractor_party} · {e.principal_name} ·{" "}
        {formatFee(e.fee_amount_minor, e.currency)} {e.fee_basis_label}
      </p>

      <dl className="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-xs text-[var(--ink-faint)]">
        {/*
          A scope is not a note in a contract here — it is what the gate
          refuses outside of. Worth showing on the row rather than buried.
        */}
        <Pair label="Scope">{e.scope ?? "the whole Circle"}</Pair>
        {e.ends_at && <Pair label="Until">{formatDate(e.ends_at)}</Pair>}
        {e.unit_cap !== null && (
          <Pair label="Metered">
            {e.units_used} of {e.unit_cap}
            {e.is_over_cap ? " — at the cap" : ""}
          </Pair>
        )}
      </dl>

      {/*
        The answer the gate is giving on every one of this person's requests.
        A contractor whose writes are being refused should read why here rather
        than conclude the product is broken.
      */}
      {!e.permits_work && e.refusal_reason && (
        <p className="mt-2 text-xs text-[var(--ink-soft)]">{e.refusal_reason}</p>
      )}
    </div>
  );
}

// ----------------------------------------------------------------- record

function Record() {
  const { data, error, loading } = useAsync<WorkRecordPage>(() => api.get("/my/record"), []);

  if (loading) return <Panel><Loading what="record" /></Panel>;
  if (error) return <ErrorNote error={error} />;

  const records = data?.data ?? [];
  const summary = data?.meta.summary ?? {};
  const chain = data?.meta.chain;

  return (
    <div className="space-y-5">
      <Panel
        title="Track record"
        meta={
          /*
            Verified on every read rather than on request. A record whose chain
            does not verify is worth less than no record at all, and a check
            somebody has to remember to run is a check nobody runs.
          */
          chain && (
            <span className="text-xs text-[var(--ink-faint)]">
              {chain.ok
                ? `chain intact · ${chain.checked} sealed`
                : `chain broken at ${chain.broken_at}`}
            </span>
          )
        }
      >
        {records.length === 0 ? (
          <Empty>
            Nothing yet. A record is written when an engagement ends, and signed by the
            company that was paying for it.
          </Empty>
        ) : (
          <>
            <dl className="mb-4 flex flex-wrap gap-x-8 gap-y-2 border-b border-[var(--rule)] pb-4 text-xs">
              <Pair label="Engagements">{summary.engagements ?? 0}</Pair>
              <Pair label="Completed">{summary.completed ?? 0}</Pair>
              <Pair label="Counterparties">{summary.counterparties ?? 0}</Pair>
              <Pair label="Deliverables accepted">{summary.deliverables_accepted ?? 0}</Pair>
              <Pair label="Delivered late">{summary.deliverables_late ?? 0}</Pair>
            </dl>

            {records.map((r) => (
              <article key={r.id} className="border-b border-[var(--rule)] py-4 last:border-b-0">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <h3 className="text-sm font-medium text-[var(--ink)]">{r.title}</h3>
                  <StatusWord word={r.outcome_label} tone={r.outcome} />
                </div>

                <p className="mt-1 text-xs text-[var(--ink-faint)]">
                  {r.counterparty} · {r.circle_name}
                  {r.started_at ? ` · from ${formatDate(r.started_at)}` : ""}
                  {r.ended_at ? ` to ${formatDate(r.ended_at)}` : ""}
                </p>

                <dl className="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-xs text-[var(--ink-faint)]">
                  {Object.entries(r.metrics ?? {})
                    .filter(([, v]) => v !== 0)
                    .map(([k, v]) => (
                      <Pair key={k} label={k.replace(/_/g, " ")}>
                        {v}
                      </Pair>
                    ))}
                </dl>

                {/*
                  The counterparty's signature is the whole value. A record
                  nobody signed is our arithmetic, and it says so.
                */}
                <p className="mt-2 text-xs text-[var(--ink-soft)]">
                  {r.attested
                    ? `Signed by ${r.attested_by}${r.attestation_note ? ` — “${r.attestation_note}”` : ""}`
                    : "Not signed yet. Until the client signs it, this is only our arithmetic."}
                </p>

                {r.attested && <PublishControl record={r} />}
              </article>
            ))}
          </>
        )}
      </Panel>
    </div>
  );
}

function PublishControl({ record }: { record: { id: string; published: boolean; visibility: string | null } }) {
  const [state, setState] = useState({ published: record.published, visibility: record.visibility });
  const [busy, setBusy] = useState(false);

  return (
    <div className="mt-2 flex flex-wrap items-center gap-2">
      {state.published ? (
        <>
          <span className="text-xs text-[var(--ink-faint)]">Visible to: {state.visibility}</span>
          <Button
            variant="quiet"
            disabled={busy}
            onClick={async () => {
              setBusy(true);
              await api.post(`/records/${record.id}/hide`);
              setState({ published: false, visibility: null });
              setBusy(false);
            }}
          >
            Hide
          </Button>
        </>
      ) : (
        (["network", "public"] as const).map((v) => (
          <Button
            key={v}
            variant="quiet"
            disabled={busy}
            onClick={async () => {
              setBusy(true);
              await api.post(`/records/${record.id}/publish`, { visibility: v });
              setState({ published: true, visibility: v });
              setBusy(false);
            }}
          >
            Show to {v === "network" ? "my network" : "anyone"}
          </Button>
        ))
      )}
    </div>
  );
}

// ------------------------------------------------------------ the registry

function AgentRegistry() {
  const { data, error, loading } = useAsync<AgentProspectus[]>(
    () => api.get<{ data: AgentProspectus[] }>("/agent-registry").then((r) => r.data),
    [],
  );

  if (loading) return <Panel><Loading what="agents" /></Panel>;
  if (error) return <ErrorNote error={error} />;

  const agents = data ?? [];

  return (
    <Panel title="Agents for hire">
      {agents.length === 0 ? (
        <Empty>No agent has been published to you.</Empty>
      ) : (
        agents.map((a) => (
          <article key={a.id} className="border-b border-[var(--rule)] py-4 last:border-b-0">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <h3 className="text-sm font-medium text-[var(--ink)]">
                {a.name} <span className="text-[var(--ink-faint)]">v{a.version}</span>
              </h3>
              <span className="text-xs text-[var(--ink-faint)]">{a.author}</span>
            </div>

            <p className="mt-1.5 max-w-2xl text-[0.8125rem] leading-relaxed text-[var(--ink-soft)]">
              {a.mandate}
            </p>

            {/*
              The tools are named rather than counted. A hirer approving an
              agent is approving these, and a number tells them nothing about
              which ones.
            */}
            <ul className="mt-2 flex flex-wrap gap-1.5">
              {a.tools.map((t) => (
                <li
                  key={t.key ?? t.name ?? ""}
                  className="rounded border border-[var(--rule)] px-1.5 py-0.5 text-[0.6875rem] text-[var(--ink-faint)]"
                >
                  {t.name ?? t.key} · {t.side_effect.replace(/_/g, " ")}
                </li>
              ))}
            </ul>

            <dl className="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-xs text-[var(--ink-faint)]">
              <Pair label="Mode">{a.execution_mode.replace(/_/g, " ")}</Pair>
              <Pair label="Most it can do">{a.highest_effect.replace(/_/g, " ")}</Pair>
              {/*
                Shown so "is this the mandate we approved in March" is a string
                comparison rather than two pages of reading.
              */}
              <Pair label="Mandate hash">{a.content_hash.slice(0, 12)}…</Pair>
            </dl>

            <p className="mt-2 text-xs italic text-[var(--ink-faint)]">Runs in {a.runs_where}.</p>
          </article>
        ))
      )}
    </Panel>
  );
}

// ----------------------------------------------------------------- shared

function Pair({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="inline text-[var(--ink-faint)]">{label}: </dt>
      <dd className="inline text-[var(--ink-soft)]">{children}</dd>
    </div>
  );
}

/**
 * A state, in words rather than a colour.
 *
 * "Terminated early" and "completed" have to read differently at a glance and
 * mean different things when read closely — which is the whole reason the
 * statuses are distinct in the schema.
 */
function StatusWord({ word, tone }: { word: string; tone: string }) {
  const quiet = ["completed", "active", "awarded", "shortlisted"].includes(tone);

  return (
    <span
      className={`text-xs ${quiet ? "text-[var(--ink-soft)]" : "text-[var(--ink-faint)]"}`}
    >
      {word}
    </span>
  );
}
