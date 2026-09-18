import { useState } from "react";
import {
  api,
  formatFee,
  relativeDays,
  type Engagement,
  type WorkApplication,
  type WorkOpening,
} from "../lib/api";
import { CircleFrame } from "./CircleFrame";
import { Button, Empty, ErrorNote, Field, Loading, Panel, inputClass, useAsync } from "./ui";

/*
  Hiring, from inside the Circle doing the hiring (spec §21.1).

  The counterpart of MarketView, and the sides are deliberately asymmetric:
  the applicant sees a job, and the client sees a project with a gap in it.
  Which is why the bid list lives here and nowhere else — publishing who else
  applied would tell every bidder who their competition is, and the bids would
  stop being honest within a week.

  The sequence the screen walks somebody through is the one the schema
  enforces: post, shortlist (which is the moment access changes hands), wait
  for them to propose, award (which merges).
*/
export function HiringView({ circleId }: { circleId: string }) {
  return (
    <CircleFrame circleId={circleId} tab="hiring">
      {(circle) => (
        <Body
          circleId={circleId}
          canPost={
            circle?.my_access?.permissions.includes("work.post") === true && !circle?.is_closed
          }
          canAward={
            circle?.my_access?.permissions.includes("work.award") === true && !circle?.is_closed
          }
        />
      )}
    </CircleFrame>
  );
}

function Body({
  circleId,
  canPost,
  canAward,
}: {
  circleId: string;
  canPost: boolean;
  canAward: boolean;
}) {
  const [posting, setPosting] = useState(false);

  const openings = useAsync<WorkOpening[]>(
    () => api.get<{ data: WorkOpening[] }>(`/circles/${circleId}/openings`).then((r) => r.data),
    [circleId],
  );

  const engagements = useAsync<Engagement[]>(
    () => api.get<{ data: Engagement[] }>(`/circles/${circleId}/engagements`).then((r) => r.data),
    [circleId],
  );

  if (openings.loading) return <Panel><Loading what="openings" /></Panel>;
  if (openings.error) return <ErrorNote error={openings.error} />;

  const all = openings.data ?? [];
  const live = all.filter((o) => ["draft", "open"].includes(o.status));
  const settled = all.filter((o) => !["draft", "open"].includes(o.status));
  const contracts = engagements.data ?? [];

  return (
    <div className="space-y-5">
      {canPost && (
        <div className="flex justify-end">
          <Button variant={posting ? "quiet" : "primary"} onClick={() => setPosting((v) => !v)}>
            {posting ? "Cancel" : "Post work"}
          </Button>
        </div>
      )}

      {posting && (
        <Composer
          circleId={circleId}
          onDone={() => {
            setPosting(false);
            openings.reload();
          }}
        />
      )}

      <Panel title="Open">
        {live.length === 0 ? (
          <Empty>
            Nothing posted. Work in the plan with nobody answerable for it can be offered
            outside the Circle from here.
          </Empty>
        ) : (
          live.map((o) => (
            <OpeningRow
              key={o.id}
              opening={o}
              canAward={canAward}
              onChanged={() => {
                openings.reload();
                engagements.reload();
              }}
            />
          ))
        )}
      </Panel>

      {contracts.length > 0 && (
        <Panel title="Contracts in this Circle">
          {contracts.map((e) => (
            <div key={e.id} className="border-b border-[var(--rule)] py-3 last:border-b-0">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-sm text-[var(--ink)]">{e.title}</span>
                <span className="text-xs text-[var(--ink-faint)]">{e.status_label}</span>
              </div>
              <p className="mt-1 text-xs text-[var(--ink-faint)]">
                {e.contractor_party} · {e.principal_name} ·{" "}
                {formatFee(e.fee_amount_minor, e.currency)} {e.fee_basis_label}
                {" · scope: "}
                {e.scope ?? "the whole Circle"}
              </p>
              {!e.permits_work && e.refusal_reason && (
                <p className="mt-1 text-xs text-[var(--ink-soft)]">{e.refusal_reason}</p>
              )}
            </div>
          ))}
        </Panel>
      )}

      {settled.length > 0 && (
        <Panel title="Settled">
          {settled.map((o) => (
            <OpeningRow key={o.id} opening={o} canAward={false} onChanged={openings.reload} />
          ))}
        </Panel>
      )}
    </div>
  );
}

function OpeningRow({
  opening,
  canAward,
  onChanged,
}: {
  opening: WorkOpening;
  canAward: boolean;
  onChanged: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  async function act(path: string, body?: unknown) {
    setBusy(true);
    setError(null);
    try {
      await api.post(path, body ?? {});
      onChanged();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  const bids = opening.applications ?? [];

  return (
    <article className="border-b border-[var(--rule)] py-4 last:border-b-0">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-sm font-medium text-[var(--ink)]">{opening.title}</h3>
        <span className="text-xs text-[var(--ink-faint)]">
          {opening.status_label ?? opening.status}
          {opening.work_title ? ` · ${opening.work_title}` : ""}
        </span>
      </div>

      <dl className="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-xs text-[var(--ink-faint)]">
        <Pair label="Fee">
          {formatFee(opening.fee_amount_minor, opening.currency)} · {opening.fee_basis_label}
        </Pair>
        <Pair label="Visible to">{visibilityWords(opening.visibility)}</Pair>
        {opening.closes_at && <Pair label="Closes">{relativeDays(opening.closes_at)}</Pair>}
        <Pair label="Bids">{opening.application_count ?? 0}</Pair>
      </dl>

      {error ? <div className="mt-2"><ErrorNote error={error} /></div> : null}

      {opening.status === "draft" && (
        <div className="mt-3 flex gap-2">
          <Button variant="primary" disabled={busy} onClick={() => act(`/openings/${opening.id}/publish`)}>
            Publish
          </Button>
        </div>
      )}

      {opening.status === "open" && (
        <div className="mt-3">
          <Button variant="quiet" disabled={busy} onClick={() => act(`/openings/${opening.id}/close`)}>
            Stop taking bids
          </Button>
        </div>
      )}

      {bids.length > 0 && (
        <div className="mt-3 space-y-2 border-l-2 border-[var(--rule)] pl-4">
          {bids.map((b) => (
            <Bid key={b.id} bid={b} canAward={canAward} busy={busy} act={act} />
          ))}
        </div>
      )}
    </article>
  );
}

function Bid({
  bid,
  canAward,
  busy,
  act,
}: {
  bid: WorkApplication;
  canAward: boolean;
  busy: boolean;
  act: (path: string, body?: unknown) => Promise<void>;
}) {
  return (
    <div className="py-1.5">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <span className="text-[0.8125rem] text-[var(--ink)]">
          {bid.organisation}
          {bid.is_agent && (
            <span className="text-[var(--ink-faint)]">
              {" "}
              · {bid.agent} v{bid.agent_version}
            </span>
          )}
        </span>
        <span className="text-xs text-[var(--ink-faint)]">{bid.status_label}</span>
      </div>

      <p className="mt-0.5 text-xs text-[var(--ink-faint)]">
        {formatFee(bid.fee_amount_minor, bid.currency)}
        {bid.availability ? ` · ${bid.availability}` : ""}
        {bid.submitted_at ? ` · ${relativeDays(bid.submitted_at)}` : ""}
      </p>

      {bid.statement && (
        <p className="mt-1 max-w-2xl text-xs leading-relaxed text-[var(--ink-soft)]">
          {bid.statement}
        </p>
      )}

      {canAward && bid.status === "submitted" && (
        <div className="mt-2 flex flex-wrap gap-2">
          <Button variant="default" disabled={busy} onClick={() => act(`/applications/${bid.id}/shortlist`)}>
            Shortlist
          </Button>
          <Button variant="quiet" disabled={busy} onClick={() => act(`/applications/${bid.id}/decline`)}>
            Decline
          </Button>
        </div>
      )}

      {canAward && bid.status === "shortlisted" && (
        <div className="mt-2 space-y-1.5">
          {/*
            Awarding merges their assignment branch, and a branch nobody has
            proposed is a proposal still being written. Saying which of the two
            states this bid is in saves the client pressing a button that
            refuses.
          */}
          {bid.branch_status === "draft" ? (
            <p className="text-xs text-[var(--ink-soft)]">
              Admitted to this work. Waiting for them to propose their assignment —
              you cannot award it until they do.
            </p>
          ) : (
            <div className="flex flex-wrap gap-2">
              <Button variant="primary" disabled={busy} onClick={() => act(`/applications/${bid.id}/award`)}>
                Award the work
              </Button>
              <Button variant="quiet" disabled={busy} onClick={() => act(`/applications/${bid.id}/decline`)}>
                Decline
              </Button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function Composer({ circleId, onDone }: { circleId: string; onDone: () => void }) {
  const [title, setTitle] = useState("");
  const [brief, setBrief] = useState("");
  const [goalId, setGoalId] = useState("");
  const [visibility, setVisibility] = useState("network");
  const [kind, setKind] = useState("either");
  const [feeBasis, setFeeBasis] = useState("fixed");
  const [fee, setFee] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  // Only work with nobody answerable for it can be offered. Posting something
  // a company already holds is either a mistake or a re-tender, and the API
  // refuses it — so the picker refuses it first.
  const goals = useAsync<Array<{ id: string; title: string; responsible_party: any }>>(
    () => api.get<{ data: any[] }>(`/circles/${circleId}/goals`).then((r) => flatten(r.data)),
    [circleId],
  );

  const available = (goals.data ?? []).filter((g) => !g.responsible_party?.id);

  async function submit() {
    setBusy(true);
    setError(null);

    try {
      await api.post(`/circles/${circleId}/openings`, {
        title,
        brief: brief || null,
        goal_id: goalId || null,
        visibility,
        principal_kind: kind,
        fee_basis: feeBasis,
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
    <Panel title="Post work">
      <div className="space-y-3">
        {error ? <ErrorNote error={error} /> : null}

        <Field label="Title">
          <input className={inputClass} value={title} onChange={(e) => setTitle(e.target.value)} />
        </Field>

        <Field
          label="The work"
          hint="Only work nobody is answerable for yet can be offered. An applicant sees this title and nothing else about the plan."
        >
          <select className={inputClass} value={goalId} onChange={(e) => setGoalId(e.target.value)}>
            <option value="">Not tied to the plan yet</option>
            {available.map((g) => (
              <option key={g.id} value={g.id}>
                {g.title}
              </option>
            ))}
          </select>
        </Field>

        <Field label="Brief">
          <textarea
            className={inputClass}
            rows={3}
            value={brief}
            onChange={(e) => setBrief(e.target.value)}
          />
        </Field>

        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Looking for">
            <select className={inputClass} value={kind} onChange={(e) => setKind(e.target.value)}>
              <option value="either">A person or an agent</option>
              <option value="human">A person</option>
              <option value="agent">An agent</option>
            </select>
          </Field>

          <Field label="Fee basis">
            <select
              className={inputClass}
              value={feeBasis}
              onChange={(e) => setFeeBasis(e.target.value)}
            >
              <option value="fixed">Fixed fee</option>
              <option value="hourly">Per hour</option>
              <option value="daily">Per day</option>
              <option value="per_deliverable">Per deliverable</option>
              <option value="per_action">Per approved action</option>
            </select>
          </Field>

          <Field label="Amount">
            <input className={inputClass} value={fee} onChange={(e) => setFee(e.target.value)} />
          </Field>
        </div>

        <Field
          label="Who can see it"
          hint="Companies you have completed work with, by default. A public posting reaches anyone signed in, and cannot be narrowed again once bids are in."
        >
          <select
            className={inputClass}
            value={visibility}
            onChange={(e) => setVisibility(e.target.value)}
          >
            <option value="network">Companies we have worked with</option>
            <option value="circle">Everyone in this Circle</option>
            <option value="party">Our company only</option>
            <option value="public">Anyone signed in</option>
          </select>
        </Field>

        <div className="flex gap-2">
          <Button variant="primary" onClick={submit} disabled={busy || title === ""}>
            {busy ? "Saving…" : "Save as draft"}
          </Button>
          <Button variant="quiet" onClick={onDone}>
            Cancel
          </Button>
        </div>

        <p className="text-xs text-[var(--ink-faint)]">
          Drafts are invisible to everybody else. Publishing is a second step, because
          this is the first thing about your project a stranger will see.
        </p>
      </div>
    </Panel>
  );
}

// ----------------------------------------------------------------- shared

/** The goal tree arrives nested; the picker wants it flat. */
function flatten(nodes: any[]): any[] {
  return nodes.flatMap((n) => [n, ...flatten(n.children ?? [])]);
}

function visibilityWords(v: string): string {
  switch (v) {
    case "party":
      return "our company";
    case "circle":
      return "this Circle";
    case "network":
      return "companies we have worked with";
    default:
      return "anyone signed in";
  }
}

function Pair({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="inline text-[var(--ink-faint)]">{label}: </dt>
      <dd className="inline text-[var(--ink-soft)]">{children}</dd>
    </div>
  );
}
