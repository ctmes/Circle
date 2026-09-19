import { useEffect, useMemo, useState } from "react";
import {
  CONVENE_MAX_FILES,
  acceptPlan,
  buildSteps,
  flattenPlan,
  loadProposal,
  type ConvenedPlan,
  type ConvenedProposal,
  type PlanParty,
  type PlanStep,
} from "../lib/convening";
import { api, type Circle, type EvidenceItem, type Party } from "../lib/api";
import { CircleFrame } from "./CircleFrame";
import { DerivedStamp } from "./Trust";
import {
  Button,
  Empty,
  ErrorNote,
  Fact,
  Field,
  Loading,
  Meta,
  Panel,
  filterClass,
  inputClass,
  useAsync,
} from "./ui";

/**
 * The review screen for a plan read out of an engagement of terms (spec §23).
 *
 * This screen has one job, and it is not "show the plan". It is to make the
 * difference between what the document said and what the machine decided
 * impossible to miss, before somebody accepts responsibility for the whole
 * thing. Three devices do that work:
 *
 *  - **Every line says stated or inferred.** An inferred line is drawn in the
 *    derived treatment used everywhere else for machine-made content. A person
 *    scanning this page can see in one pass which rows to read against the
 *    contract and which they can take on trust.
 *
 *  - **A computed date says how it was computed.** "27 March 2026" with
 *    "20 business days after 1 March" under it is a claim somebody can check.
 *    The same date on its own is a number they will assume came from the
 *    document.
 *
 *  - **What the resolver repaired is stated at the top, not hidden.** A dropped
 *    citation, a party that could not be matched, a level that had to be moved:
 *    all of it in words, above the plan rather than in a log.
 *
 * Which of the two things this screen is depends on whether the plan was
 * written in. Convening writes it by default, so the usual case is the receipt
 * below — the reading, kept as it was read, beside what it produced. The editor
 * is what remains for a proposal that was *not* applied: someone without the
 * permissions to restate the mission, or a second reading of a Circle that
 * already has a plan. In that case nothing is written until the button at the
 * bottom is pressed, and what is written is what is on this screen, edits
 * included.
 */
/** What the API accepts. `convener` is not offered: that seat belongs to whoever opened the Circle. */
const PARTY_ROLES: PlanParty["party_role"][] = [
  "principal",
  "contractor",
  "subcontractor",
  "advisor",
  "observer",
];

export function ConveningView({ circleId }: { circleId: string }) {
  // No tab is active: convening is somewhere you pass through once rather than
  // a destination, and highlighting "Main" would say it was one.
  return (
    <CircleFrame circleId={circleId} tab="convening">
      {(circle) => <Review circleId={circleId} circle={circle} />}
    </CircleFrame>
  );
}

function Review({ circleId, circle }: { circleId: string; circle: Circle | null }) {
  const [proposal, setProposal] = useState<ConvenedProposal | null | undefined>(undefined);
  const [error, setError] = useState<unknown>(null);

  // The mission statement and the anchor, held apart from the proposal because
  // they are what the person is editing.
  const [name, setName] = useState("");
  const [purpose, setPurpose] = useState("");
  const [anchor, setAnchor] = useState("");
  const [titles, setTitles] = useState<Record<string, string>>({});
  const [dropped, setDropped] = useState<Set<string>>(new Set());

  // Parties are editable for one reason: a company this Circle already knows
  // as "JWA Mats" and a contract that calls it "JWA Mats Pty Ltd" are the same
  // company, and nothing but a person can say so. Accepting both would put two
  // parties in the Circle with one set of obligations divided between them.
  const [partyNames, setPartyNames] = useState<Record<string, string>>({});
  const [partyRoles, setPartyRoles] = useState<Record<string, PlanParty["party_role"]>>({});
  const [droppedParties, setDroppedParties] = useState<Set<string>>(new Set());
  const [existing, setExisting] = useState<Party[]>([]);
  const [busy, setBusy] = useState(false);
  const [reanchoring, setReanchoring] = useState(false);
  const [done, setDone] = useState<{ goals: number; parties: number } | null>(null);

  useEffect(() => {
    let live = true;

    loadProposal(circleId)
      .then((p) => {
        if (!live) return;
        setProposal(p);
        if (p?.plan) {
          setName(p.plan.mission.name);
          setPurpose(p.plan.mission.purpose);
          setAnchor(p.plan.anchor_date);
        }
      })
      .catch((e) => live && setError(e));

    // What the Circle already knows, so a near-duplicate is visible before it
    // is written rather than after. A failure here is not worth surfacing: the
    // proposal still reviews, and the server reuses an exact match anyway.
    api
      .get<{ data: Party[] }>(`/circles/${circleId}/parties`)
      .then((r) => live && setExisting(r.data))
      .catch(() => {});

    return () => {
      live = false;
    };
  }, [circleId]);

  const plan = proposal?.plan ?? null;
  const steps = useMemo(() => (plan ? flattenPlan(plan.plan) : []), [plan]);
  const kept = useMemo(
    () => new Set(steps.map((s) => s.key).filter((k) => !dropped.has(k))),
    [steps, dropped],
  );
  const keptParties = useMemo(
    () => new Set((plan?.parties ?? []).map((p) => p.key).filter((k) => !droppedParties.has(k))),
    [plan, droppedParties],
  );

  /**
   * Re-read the same proposal against a different start date.
   *
   * Deliberately a server round trip rather than arithmetic here: the dates in
   * this plan were resolved by the calendar in PlanResolver, and a second
   * implementation in the browser would be a second answer to the same
   * question. It calls no model, so it is cheap and it comes back identical
   * every time.
   */
  async function reanchor(date: string) {
    setAnchor(date);

    if (!date) return;

    setReanchoring(true);
    try {
      const next = await loadProposal(circleId, date);
      if (next) setProposal(next);
    } catch (e) {
      setError(e);
    } finally {
      setReanchoring(false);
    }
  }

  async function accept() {
    if (!plan || !proposal) return;

    setBusy(true);
    setError(null);

    try {
      const result = await acceptPlan(circleId, proposal.id, {
        name: name.trim(),
        purpose: purpose.trim(),
        starts_at: plan.mission.commences_on,
        expires_at: plan.mission.concludes_on,
        parties: plan.parties
          .filter((p) => keptParties.has(p.key))
          .map((p) => ({
            key: p.key,
            display_name: (partyNames[p.key] ?? p.display_name).trim() || p.display_name,
            party_role: partyRoles[p.key] ?? p.party_role,
          })),
        steps: buildSteps(plan.plan, kept).map((s) => ({
          ...s,
          title: (titles[s.key] ?? s.title).trim() || s.title,
        })),
      });

      setDone(result);
    } catch (e) {
      setError(e);
      setBusy(false);
    }
  }

  if (proposal === undefined) return <Loading what="proposal" />;

  if (proposal === null || !plan) {
    return <ConveneHere circleId={circleId} onRead={setProposal} />;
  }

  if (done || proposal.applied_at) {
    return <Receipt circleId={circleId} proposal={proposal} plan={plan} />;
  }

  const canAccept =
    (circle?.my_access?.permissions ?? []).includes("goal.create") &&
    (circle?.my_access?.permissions ?? []).includes("party.manage");

  return (
    <div className="space-y-4">
      <Panel
        title={proposal.sources.length > 1 ? "Read from the documents" : "Read from the document"}
        tone="derived"
        className="lay-in"
        meta={<Meta>{plan.counts.steps} steps · {plan.counts.parties} parties</Meta>}
      >
        <div className="space-y-4 px-5 pb-5">
          <DerivedStamp />

          <p className="text-sm leading-relaxed text-[var(--ink-muted)]">
            Nothing below is in this Circle yet. Check it against the document, change what is
            wrong, remove what the document does not support, and accept it — at which point it
            becomes your plan, recorded under your name.
          </p>

          <div className="rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3.5 py-3">
            {proposal.sources.map((s) => (
              <Fact key={s.evidence_version_id} label="Read from">
                {s.name}
                {s.pages !== null && (
                  <span className="text-[var(--ink-faint)]"> · {s.pages} pages</span>
                )}
              </Fact>
            ))}
            <Fact label="Model">{proposal.model.name ?? "unknown"}</Fact>
          </div>

          {plan.uncertainty && (
            <div className="rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3.5 py-3">
              <p className="label">What it could not determine</p>
              <p className="mt-1 text-sm leading-relaxed text-[var(--ink-muted)]">
                {plan.uncertainty}
              </p>
            </div>
          )}

          {plan.notes.length > 0 && <Notes notes={plan.notes} rejected={plan.counts.citations_rejected} />}
        </div>
      </Panel>

      <Panel title="The mission" className="lay-in">
        <div className="space-y-4 px-5 pb-5">
          <Field label="Name" hint="Name the outcome, not the team.">
            <input value={name} onChange={(e) => setName(e.target.value)} className={inputClass} />
          </Field>

          <Field label="Purpose" hint="What has to be true for this engagement to be finished?">
            <textarea
              value={purpose}
              onChange={(e) => setPurpose(e.target.value)}
              rows={3}
              className={`${inputClass} resize-y leading-relaxed`}
            />
          </Field>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              label="Commences"
              hint={
                plan.anchor_source === "document"
                  ? "The document states this date. Every period in the plan is measured from it."
                  : "The document gives no start date, so periods are measured from here. Change it and the plan moves with it."
              }
            >
              <input
                type="date"
                value={anchor}
                onChange={(e) => reanchor(e.target.value)}
                className={inputClass}
                disabled={reanchoring}
              />
            </Field>

            <Field
              label="Concludes"
              hint={plan.mission.concludes_note ?? "As the document states it."}
            >
              <input
                value={plan.mission.concludes_on ?? "not stated"}
                readOnly
                className={`${inputClass} text-[var(--ink-muted)]`}
              />
            </Field>
          </div>
        </div>
      </Panel>

      {plan.parties.length > 0 && (
        <Panel
          title="Parties"
          className="lay-in"
          meta={<Meta>{keptParties.size} of {plan.parties.length} kept</Meta>}
        >
          {existing.length > 0 && (
            <p className="px-5 pb-3 text-xs leading-snug text-[var(--ink-muted)]">
              Already in this Circle: {existing.map((p) => p.display_name).join(", ")}. A name that
              matches one of those exactly is reused rather than added twice — anything else
              becomes a second company, so fix the spelling here rather than after.
            </p>
          )}

          <ul className="divide-y divide-[var(--rule)] px-5 pb-2">
            {plan.parties.map((party) => {
              const out = droppedParties.has(party.key);
              const name = partyNames[party.key] ?? party.display_name;
              const known = existing.some(
                (p) => p.display_name.trim().toLowerCase() === name.trim().toLowerCase(),
              );

              return (
                <li key={party.key} className="py-2.5">
                  <div className="flex items-start gap-3">
                    <input
                      type="checkbox"
                      checked={!out}
                      onChange={() =>
                        setDroppedParties((all) => {
                          const next = new Set(all);
                          next.has(party.key) ? next.delete(party.key) : next.add(party.key);
                          return next;
                        })
                      }
                      className="mt-[9px] shrink-0"
                      aria-label={`Add ${party.display_name}`}
                    />

                    <div className={`min-w-0 flex-1 ${out ? "opacity-45" : ""}`}>
                      <div className="flex flex-wrap items-center gap-2">
                        <input
                          value={name}
                          onChange={(e) =>
                            setPartyNames((all) => ({ ...all, [party.key]: e.target.value }))
                          }
                          disabled={out}
                          className="min-w-0 flex-1 rounded-[var(--r-control)] bg-transparent px-1.5 py-1 text-sm font-[560] hover:bg-[var(--paper-inset)] focus:bg-[var(--paper-inset)]"
                        />

                        <select
                          value={partyRoles[party.key] ?? party.party_role}
                          onChange={(e) =>
                            setPartyRoles((all) => ({
                              ...all,
                              [party.key]: e.target.value as PlanParty["party_role"],
                            }))
                          }
                          disabled={out}
                          className={filterClass}
                        >
                          {PARTY_ROLES.map((r) => (
                            <option key={r} value={r}>
                              {r}
                            </option>
                          ))}
                        </select>
                      </div>

                      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 px-1.5 pt-1">
                        <BasisMark basis={party.basis} />
                        {party.defined_term && (
                          <span className="text-xs text-[var(--ink-faint)]">
                            called “{party.defined_term}” in the document
                          </span>
                        )}
                        {known && (
                          <span className="text-xs text-[var(--ink-muted)]">
                            already a party — will be reused
                          </span>
                        )}
                      </div>
                    </div>
                  </div>
                </li>
              );
            })}
          </ul>

          <p className="px-5 pb-4 text-xs leading-snug text-[var(--ink-faint)]">
            Adding a party names a company in this Circle. It grants nobody access — people are
            invited separately, and an invitation is its own decision. Work assigned to a party
            you remove here arrives unassigned.
          </p>
        </Panel>
      )}

      <Panel
        title="The work"
        className="lay-in"
        meta={
          <Meta>
            {kept.size} of {steps.length} kept
          </Meta>
        }
      >
        {plan.plan.length === 0 ? (
          <Empty>The document did not set out any work.</Empty>
        ) : (
          <ul className="divide-y divide-[var(--rule)] px-5 pb-2">
            {plan.plan.map((step) => (
              <StepRow
                key={step.key}
                step={step}
                depth={0}
                parties={plan.parties}
                dropped={dropped}
                titles={titles}
                onToggle={(key) =>
                  setDropped((all) => {
                    const next = new Set(all);
                    next.has(key) ? next.delete(key) : next.add(key);
                    return next;
                  })
                }
                onRename={(key, value) => setTitles((all) => ({ ...all, [key]: value }))}
              />
            ))}
          </ul>
        )}
      </Panel>

      {plan.open_questions.length > 0 && (
        <Panel
          title={
            proposal.sources.length > 1
              ? "What the documents leave open"
              : "What the document leaves open"
          }
          tone="derived"
          className="lay-in"
        >
          <ul className="space-y-3 px-5 pb-5">
            {plan.open_questions.map((q, i) => (
              <li key={i}>
                <p className="text-sm leading-snug">{q.question}</p>
                {q.why_it_matters && (
                  <p className="mt-0.5 text-xs leading-snug text-[var(--ink-muted)]">
                    {q.why_it_matters}
                  </p>
                )}
              </li>
            ))}
          </ul>
          <p className="border-t border-[var(--rule)] px-5 py-3 text-xs leading-snug text-[var(--ink-faint)]">
            These are not carried into the Circle. Open a thread for the ones that matter — a
            question filed automatically is a question nobody owns.
          </p>
        </Panel>
      )}

      <Panel className="lay-in">
        <div className="space-y-3 px-5 py-5">
          {!!error && <ErrorNote error={error} />}

          {!canAccept && (
            <p className="text-sm leading-relaxed text-[var(--ink-muted)]">
              You can read this proposal but not accept it. Accepting writes the mission
              statement, the parties and the plan, which needs someone who can manage both.
            </p>
          )}

          <Button
            variant="primary"
            onClick={accept}
            disabled={busy || !canAccept || !name.trim() || !purpose.trim() || kept.size === 0}
          >
            {busy ? "Writing the plan…" : `Accept and write ${kept.size} pieces of work`}
          </Button>

          <p className="text-xs leading-snug text-[var(--ink-faint)]">
            Everything written lands under your name, on the record, as though you had entered it
            by hand. The proposal is kept beside it so the two can be compared.
          </p>
        </div>
      </Panel>
    </div>
  );
}

/** What the resolver had to repair, stated plainly rather than logged. */
function Notes({ notes, rejected }: { notes: string[]; rejected: number }) {
  return (
    <div className="rounded-[var(--r-control)] border border-[var(--rule-strong)] px-3.5 py-3">
      <p className="label">What had to be corrected</p>
      <ul className="mt-1.5 space-y-1.5">
        {notes.map((note, i) => (
          <li key={i} className="flex gap-2 text-sm leading-snug text-[var(--ink-muted)]">
            <span className="text-[var(--ink-faint)]" aria-hidden="true">•</span>
            {note}
          </li>
        ))}
        {rejected > 0 && (
          <li className="flex gap-2 text-sm leading-snug text-[var(--ink-muted)]">
            <span className="text-[var(--ink-faint)]" aria-hidden="true">•</span>
            {rejected} citation{rejected === 1 ? "" : "s"} pointed at nothing in the document and{" "}
            {rejected === 1 ? "was" : "were"} discarded.
          </li>
        )}
      </ul>
    </div>
  );
}

/**
 * Convening a Circle that already exists.
 *
 * The drop zone on the Circles page opens a new Circle around a document. This
 * is the other direction: a Circle somebody opened by hand, or one convened
 * from a head contract that has since been sent a scope of works, where the
 * document is already in the vault and what is wanted is the reading.
 *
 * Only documents marked agent-readable are offered, and the list says so rather
 * than quietly hiding the rest. The flag is the whole of the agent's permission
 * to read an item (spec §10), and a screen that silently omitted a file would
 * leave somebody hunting for why their contract is not listed.
 *
 * Several can be ticked and read together, for a contract whose schedules are
 * separate files — and for finishing a drop on the Circles page that stored
 * some of its documents and not others.
 */
function ConveneHere({
  circleId,
  onRead,
}: {
  circleId: string;
  onRead: (p: ConvenedProposal) => void;
}) {
  const { data: evidence } = useAsync<EvidenceItem[]>(
    () => api.get<{ data: EvidenceItem[] }>(`/circles/${circleId}/evidence`).then((r) => r.data),
    [circleId],
  );
  const [chosen, setChosen] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  const readable = (evidence ?? []).filter(
    (e) => e.agent_read && e.current_version?.extracted_text_status === "ready",
  );
  const unreadable = (evidence ?? []).filter((e) => !e.agent_read);
  const full = chosen.length >= CONVENE_MAX_FILES;

  async function read() {
    if (chosen.length === 0) return;

    setBusy(true);
    setError(null);

    try {
      const result = (
        await api.post<{ data: ConvenedProposal }>(`/circles/${circleId}/convening`, {
          evidence_item_ids: chosen,
        })
      ).data;

      onRead(result);
    } catch (e) {
      setError(e);
      setBusy(false);
    }
  }

  return (
    <Panel title="Convene from a document" className="lay-in">
      <div className="space-y-4 px-5 pb-5">
        <p className="text-sm leading-relaxed text-[var(--ink-muted)]">
          Nothing has been read into this Circle yet. Tick the engagement of terms — and its
          schedules, if they are separate files — and they will be read together into the
          mission statement, the parties, the plan, the dated deliverables and the questions
          they leave open.
        </p>

        {readable.length === 0 ? (
          <Empty>
            No document here is readable by an agent yet. Upload the contract to the evidence
            register with “agent-readable” ticked, or turn it on for a document already there.
          </Empty>
        ) : (
          <>
            <ul className="divide-y divide-[var(--rule)]">
              {readable.map((item) => {
                const ticked = chosen.includes(item.id);

                return (
                  <li key={item.id}>
                    <label
                      className={`flex items-center gap-3 py-2.5 ${
                        !ticked && full ? "cursor-not-allowed opacity-45" : "cursor-pointer"
                      }`}
                    >
                      <input
                        type="checkbox"
                        checked={ticked}
                        disabled={busy || (!ticked && full)}
                        onChange={(e) =>
                          setChosen((c) =>
                            e.target.checked ? [...c, item.id] : c.filter((id) => id !== item.id),
                          )
                        }
                      />
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-[560]">{item.name}</span>
                        <span className="block text-xs text-[var(--ink-faint)]">
                          {item.current_version?.original_filename}
                        </span>
                      </span>
                    </label>
                  </li>
                );
              })}
            </ul>

            <div className="flex flex-wrap items-center gap-3">
              <Button variant="primary" onClick={read} disabled={busy || chosen.length === 0}>
                {busy
                  ? "Reading…"
                  : chosen.length <= 1
                    ? "Read this"
                    : `Read these ${chosen.length} together`}
              </Button>
              {full && (
                <span className="text-xs text-[var(--ink-faint)]">
                  Up to {CONVENE_MAX_FILES} documents are read together.
                </span>
              )}
            </div>
          </>
        )}

        {unreadable.length > 0 && (
          <p className="text-xs leading-snug text-[var(--ink-faint)]">
            {unreadable.length} document{unreadable.length === 1 ? " is" : "s are"} not marked
            agent-readable and {unreadable.length === 1 ? "was" : "were"} not offered. That flag
            is per item and is never inherited.
          </p>
        )}

        {!!error && <ErrorNote error={error} />}
      </div>
    </Panel>
  );
}

/**
 * What this Circle was built from, after the fact.
 *
 * The plan is already in the Circle by the time anybody sees this screen, so it
 * is a receipt rather than a gate — and a receipt is worth more here than a
 * review step was. The goals, dates and deliverables are now editable in the
 * ordinary places, which is where somebody will actually change them; what they
 * cannot get anywhere else is the answer to "which of these did the contract
 * actually say, and which did the machine work out". That is this page.
 *
 * Nothing here can be edited, on purpose. Editing the reading after the fact
 * would produce a record of what the document said that somebody had adjusted,
 * which is the one thing a derived artifact must never be.
 */
function Receipt({
  circleId,
  proposal,
  plan,
}: {
  circleId: string;
  proposal: ConvenedProposal;
  plan: ConvenedPlan;
}) {
  const created = proposal.created ?? null;
  const steps = flattenPlan(plan.plan);
  const inferred = steps.filter((s) => s.basis === "inferred");

  return (
    <div className="space-y-4">
      <Panel
        title={proposal.sources.length > 1 ? "Built from the documents" : "Built from the document"}
        tone="derived"
        className="lay-in"
        meta={
          <Button variant="quiet" onClick={() => (location.href = `/circles/${circleId}`)}>
            Open the plan
          </Button>
        }
      >
        <div className="space-y-4 px-5 pb-5">
          <DerivedStamp />

          <div className="rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3.5 py-3">
            {proposal.sources.map((s) => (
              <Fact key={s.evidence_version_id} label="Read from">
                {s.name}
                {s.pages !== null && (
                  <span className="text-[var(--ink-faint)]"> · {s.pages} pages</span>
                )}
              </Fact>
            ))}
            <Fact label="Read by">
              {proposal.agent.name ?? "an agent"} · {proposal.model.name ?? "unknown model"}
            </Fact>
            {proposal.applied_by && (
              <Fact label="Written in by">{proposal.applied_by.name ?? "someone"}</Fact>
            )}
          </div>

          {created && (
            <div>
              <p className="label">What it wrote</p>
              <ul className="mt-1.5 grid gap-x-6 gap-y-1 text-sm text-[var(--ink-muted)] sm:grid-cols-2">
                <CountRow n={created.goals} one="piece of work" many="pieces of work" />
                <CountRow n={created.parties} one="party" many="parties" />
                <CountRow n={created.commitments} one="dated deliverable" many="dated deliverables" />
                <CountRow n={created.decisions} one="open question" many="open questions" />
              </ul>
              <p className="mt-2 text-xs leading-snug text-[var(--ink-faint)]">
                All of it under {proposal.applied_by?.name ?? "the person who convened this"},
                on the record, and editable in the ordinary places. The contract is filed
                against every piece of work it produced.
              </p>
            </div>
          )}

          {/*
            The number that matters most on this page, so it is stated as a
            sentence rather than left to be counted off a list.
          */}
          <p className="text-sm leading-relaxed">
            {inferred.length === 0 ? (
              <>Every piece of work here was stated in the document.</>
            ) : (
              <>
                <span className="font-[590] text-[var(--derived)]">
                  {inferred.length} of {steps.length}
                </span>{" "}
                {inferred.length === 1 ? "piece" : "pieces"} of work {inferred.length === 1 ? "was" : "were"}{" "}
                inferred rather than stated in the document. Those are the ones to read against
                the contract.
              </>
            )}
          </p>

          {plan.uncertainty && (
            <div className="rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3.5 py-3">
              <p className="label">What it could not determine</p>
              <p className="mt-1 text-sm leading-relaxed text-[var(--ink-muted)]">
                {plan.uncertainty}
              </p>
            </div>
          )}

          {plan.notes.length > 0 && (
            <Notes notes={plan.notes} rejected={plan.counts.citations_rejected} />
          )}
        </div>
      </Panel>

      <Panel
        title="The reading"
        className="lay-in"
        meta={<Meta>{steps.length} steps</Meta>}
      >
        {plan.plan.length === 0 ? (
          <Empty>The document did not set out any work.</Empty>
        ) : (
          <ul className="divide-y divide-[var(--rule)] px-5 pb-2">
            {plan.plan.map((step) => (
              <ReadStep key={step.key} step={step} depth={0} parties={plan.parties} />
            ))}
          </ul>
        )}
        <p className="border-t border-[var(--rule)] px-5 py-3 text-xs leading-snug text-[var(--ink-faint)]">
          This is what was read, kept as it was read. Changing the plan happens in the plan —
          a reading somebody had edited afterwards would be no use for checking anything.
        </p>
      </Panel>

      {plan.open_questions.length > 0 && (
        <Panel
          title={
            proposal.sources.length > 1
              ? "What the documents leave open"
              : "What the document leaves open"
          }
          tone="derived"
          className="lay-in"
        >
          <ul className="space-y-3 px-5 pb-5">
            {plan.open_questions.map((q, i) => (
              <li key={i}>
                <p className="text-sm leading-snug">{q.question}</p>
                {q.why_it_matters && (
                  <p className="mt-0.5 text-xs leading-snug text-[var(--ink-muted)]">
                    {q.why_it_matters}
                  </p>
                )}
              </li>
            ))}
          </ul>
          <p className="border-t border-[var(--rule)] px-5 py-3 text-xs leading-snug text-[var(--ink-faint)]">
            Each of these is a draft decision in the Circle with nobody named against it. Naming
            who decides is what makes one live — nothing here can do that for you.
          </p>
        </Panel>
      )}
    </div>
  );
}

function CountRow({ n, one, many }: { n: number; one: string; many: string }) {
  return (
    <li className="flex items-baseline gap-2">
      <span className="mono text-sm font-[590] text-[var(--ink)]">{n}</span>
      <span>{n === 1 ? one : many}</span>
    </li>
  );
}

/** One step of the reading, as it was read. No checkbox, no editing. */
function ReadStep({
  step,
  depth,
  parties,
}: {
  step: PlanStep;
  depth: number;
  parties: PlanParty[];
}) {
  const party = parties.find((p) => p.key === step.responsible_party_key);

  return (
    <>
      <li className="space-y-1.5 py-2.5" style={{ paddingLeft: depth * 18 }}>
        <p className="text-sm font-[560]">{step.title}</p>

        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <BasisMark basis={step.basis} />

          {party && <span className="text-xs text-[var(--ink-muted)]">{party.display_name}</span>}

          {!party && step.responsible_as_written && (
            <span
              className="text-xs text-[var(--ink-faint)]"
              title="The document names this, but it is not one of the parties, so the work arrived unassigned."
            >
              {step.responsible_as_written} — unmatched
            </span>
          )}

          {step.due_on && (
            <span
              className="text-xs text-[var(--ink-muted)]"
              title={step.due_note ?? "Stated in the document."}
            >
              due {step.due_on}
            </span>
          )}

          {step.clause && (
            <span className="mono text-[0.6875rem] text-[var(--ink-faint)]">{step.clause}</span>
          )}

          {step.citation?.page && (
            <span className="text-[0.6875rem] text-[var(--ink-faint)]">p{step.citation.page}</span>
          )}
        </div>

        {/*
          A date the software worked out says so on the face of the row. This is
          the single most likely place for a plausible-looking wrong answer to
          go unnoticed, and it is now a date somebody may already be working to.
        */}
        {step.due_note && (
          <p className="text-[0.6875rem] leading-snug text-[var(--derived)]">
            computed: {step.due_note}
          </p>
        )}

        {step.acceptance_condition && (
          <p className="text-xs leading-snug text-[var(--ink-muted)]">
            <span className="label">Accepted when </span>
            {step.acceptance_condition}
          </p>
        )}

        {step.citation?.excerpt && (
          <p className="border-l-2 border-[var(--rule-strong)] px-2.5 text-xs italic leading-snug text-[var(--ink-faint)]">
            “{step.citation.excerpt}”
          </p>
        )}
      </li>

      {step.children.map((child) => (
        <ReadStep key={child.key} step={child} depth={depth + 1} parties={parties} />
      ))}
    </>
  );
}

/** Stated or inferred, in the vocabulary the rest of the product uses for machine content. */
function BasisMark({ basis }: { basis: "stated" | "inferred" }) {
  if (basis === "stated") {
    return (
      <span
        className="rounded-[var(--r-chip)] bg-[var(--paper-inset)] px-2 py-[2px] text-[0.6875rem] font-[560] text-[var(--ink-muted)]"
        title="The document says this. Follow the citation to check it."
      >
        stated
      </span>
    );
  }

  return (
    <span
      className="rounded-[var(--r-chip)] bg-[var(--derived-soft)] px-2 py-[2px] text-[0.6875rem] font-[560] text-[var(--derived)]"
      title="The agent concluded this from what the document says. Read it carefully."
    >
      inferred
    </span>
  );
}

function StepRow({
  step,
  depth,
  parties,
  dropped,
  titles,
  onToggle,
  onRename,
}: {
  step: PlanStep;
  depth: number;
  parties: Array<{ key: string; display_name: string }>;
  dropped: Set<string>;
  titles: Record<string, string>;
  onToggle: (key: string) => void;
  onRename: (key: string, value: string) => void;
}) {
  const out = dropped.has(step.key);
  const party = parties.find((p) => p.key === step.responsible_party_key);

  return (
    <>
      <li className="py-2.5" style={{ paddingLeft: depth * 18 }}>
        <div className="flex items-start gap-3">
          <input
            type="checkbox"
            checked={!out}
            onChange={() => onToggle(step.key)}
            className="mt-[7px] shrink-0"
            aria-label={`Keep “${step.title}”`}
          />

          <div className={`min-w-0 flex-1 space-y-1.5 ${out ? "opacity-45" : ""}`}>
            <input
              value={titles[step.key] ?? step.title}
              onChange={(e) => onRename(step.key, e.target.value)}
              disabled={out}
              className="w-full rounded-[var(--r-control)] bg-transparent px-1.5 py-1 text-sm font-[560] text-[var(--ink)] hover:bg-[var(--paper-inset)] focus:bg-[var(--paper-inset)]"
            />

            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 px-1.5">
              <BasisMark basis={step.basis} />

              {party && (
                <span className="text-xs text-[var(--ink-muted)]">{party.display_name}</span>
              )}

              {!party && step.responsible_as_written && (
                <span
                  className="text-xs text-[var(--ink-faint)]"
                  title="The document names this, but it is not one of the parties, so the work is unassigned."
                >
                  {step.responsible_as_written} — unmatched
                </span>
              )}

              {step.due_on && (
                <span
                  className="text-xs text-[var(--ink-muted)]"
                  // A computed date says so on hover and in the line below.
                  title={step.due_note ?? "Stated in the document."}
                >
                  due {step.due_on}
                </span>
              )}

              {step.clause && (
                <span className="mono text-[0.6875rem] text-[var(--ink-faint)]">
                  {step.clause}
                </span>
              )}

              {step.citation?.page && (
                <span className="text-[0.6875rem] text-[var(--ink-faint)]">
                  p{step.citation.page}
                </span>
              )}
            </div>

            {/*
              A date the software worked out says so on the face of the row, not
              only in a tooltip. This is the single most likely place for a
              plausible-looking wrong answer to survive a review.
            */}
            {step.due_note && (
              <p className="px-1.5 text-[0.6875rem] leading-snug text-[var(--derived)]">
                computed: {step.due_note}
              </p>
            )}

            {step.acceptance_condition && (
              <p className="px-1.5 text-xs leading-snug text-[var(--ink-muted)]">
                <span className="label">Accepted when </span>
                {step.acceptance_condition}
              </p>
            )}

            {step.citation?.excerpt && (
              <p className="border-l-2 border-[var(--rule-strong)] px-2.5 text-xs italic leading-snug text-[var(--ink-faint)]">
                “{step.citation.excerpt}”
              </p>
            )}
          </div>
        </div>
      </li>

      {step.children.map((child) => (
        <StepRow
          key={child.key}
          step={child}
          depth={depth + 1}
          parties={parties}
          dropped={dropped}
          titles={titles}
          onToggle={onToggle}
          onRename={onRename}
        />
      ))}
    </>
  );
}
