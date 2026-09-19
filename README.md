# Circle

A trusted, temporary operating environment for organisations collaborating on a
consequential outcome.

A Circle groups only the people, agents, media, claims, decisions, approvals,
commitments and access rights required for one mission — and ends when the
mission does. It is not a chat app, CRM, project-management suite, file drive or
agent builder.

This repository implements the MVP specified in [`mvp.md`](mvp.md), built around
the pilot scenario: **Rail Access Package — Bid Review**.

---

## Running it

Requires Docker. Everything else — PHP 8.4, Postgres 16, Redis, MinIO, ffmpeg,
poppler, tesseract, Node — is inside the containers.

One command does the whole thing — starts Docker if it isn't running, installs
dependencies, migrates, seeds, provisions demo accounts, waits for the API and
the web server to actually answer, then opens the browser:

```powershell
.\start.ps1        # Windows
```

```bash
./start.sh         # macOS / Linux
```

Both are idempotent, so re-running them against a live stack is safe. Useful
flags: `-Fresh` / `--fresh` (wipe the volumes and start clean), `-Rebuild` /
`--rebuild`, `-Demo` / `--demo` (run `demo.py` afterwards), `-NoBrowser` /
`--no-browser`.

The steps by hand, if you'd rather:

```bash
docker compose up -d                                   # brings up the whole stack
docker compose run --rm api php artisan migrate --seed # schema + agent blueprint
```

Everything above is the development stack: `artisan serve`, the Astro dev
server, no TLS, and ports bound on localhost. Running it somewhere other people
can reach is a different shape — php-fpm behind Caddy, a built front end,
nothing exposed but 80 and 443 — and lives in
[`docker-compose.prod.yml`](docker-compose.prod.yml), with the order to do it in
at [`DEPLOY.md`](DEPLOY.md).

| Service | URL | What it is |
|---|---|---|
| Web | http://localhost:4321 | Astro + React front end |
| API | http://localhost:8000/api | Laravel JSON API |
| MinIO console | http://localhost:19001 | Evidence vault (`circle` / `circlecircle`) |
| Horizon | http://localhost:8000/horizon | Queue workers, throughput, failed jobs |

Sign up at http://localhost:4321, create your organisation, and open a Circle —
`POST /organisations` puts the creator in it as its owner. Organisation
membership conveys no Circle access and the gate never reads it, so standing up
a company reaches nothing that already exists; all it confers is the ability to
convene.

Seeding several accounts at once is still quicker from the command line, and
that is what the demo uses:

```bash
docker compose exec api php artisan circle:provision-org "JWA Mats" \
  --user="gm@jwamats.test:Dana Okafor:a-long-password-here"
```

### Seeing the whole thing work

```bash
python demo.py              # runs the First Demo Script (spec §18) end to end
KEEP_OPEN=1 python demo.py  # same, but leaves the Circle open to click around
```

`demo.py` drives the real system over HTTP exactly as a browser would: signed
direct-to-storage uploads of PDF/DOCX/XLSX/JPG/MP4/WAV, the extraction pipeline,
citations against exact evidence versions, approval bound to a version, version
supersession, agent policy enforcement, tamper detection, closure and the
exported mission packet — originals included, each carrying both the digest of
the bytes as shipped and the digest the Circle recorded at upload. **81 checks.**

### Tests

```bash
docker compose run --rm api php artisan test    # 320 feature tests
cd web && npx astro check                       # typecheck
cd web && node verify-contract.mjs              # 128 API/UI contract checks
cd web && node screenshot.mjs                   # drives every view in a browser
```

The feature tests cover what the end-to-end run cannot: the Steward's handling
of *scripted* model output, including output that cites evidence it was never
shown.

---

## The five ideas this is built around

**1. Trust is three facts, never one badge.**
Origin, integrity and review are independent axes with independent vocabularies
(spec §6). A document is routinely `authenticated upload · intact · unreviewed`,
and the UI can say precisely that. There is no green tick anywhere, because
"verified" is not a thing this system can honestly claim about a file's
contents.

**2. Originals are immutable; interpretations are separate.**
An uploaded binary is never overwritten. A replacement is a new
`evidence_version` pointing back at what it supersedes, so a citation made last
month still resolves to the exact bytes it cited. Every OCR pass, transcript,
thumbnail and agent summary is a `derived_artifact` recording which model,
prompt version and source produced it.

**3. A claim is not true because something said it.**
Claims carry citations to precise locations — `{"page": 4}`,
`{"sheet": "Load Schedule", "range": "C2:D2"}`, `{"start_seconds": 133}` — and
the locator is validated against the media type, so a citation that cannot
resolve is refused rather than stored. Human claims enter as `attested`; agent
claims enter as `derived`. Neither is ever silently promoted.

**4. Access is an intersection, never an inheritance.**
`AccessGate` evaluates the spec's six checks in order and grants only what all
of them permit. Belonging to the same organisation grants nothing. External
collaborators are denied download and sharing by default. Agent read is opt-in
per item. Every denial is written to the audit log with the reason.

**5. The record is tamper-evident and it tells you so.**
`event_hash = SHA256(canonical_json(event) + previous_hash)`, chained per Circle
so an exported packet verifies on its own. The History view verifies the chain
live and says which event broke it. This is tamper *evidence* for the
application's own stream — not an independently anchored ledger, and the UI says
that too.

---

## Where conversation happens

A thread hangs off a goal, claim, decision, commitment or evidence item — and,
since §22, off the Circle itself.

§20.3 refused that last one, on the grounds that once a general room exists the
substance migrates into it and the structured record decays into something
somebody updates afterwards out of duty. That is right about what a general room
does and wrong about what refusing one achieves. Work begins before there is an
object to attach a question to — *are we bidding this*, *can you send last
year's scope* — and a product with nowhere to ask that does not stop the asking.
It sends the question to email, where the answer follows, and the record ends up
somewhere with no visibility rules, no export semantics and no chain at all.

So the room exists and the objection is answered instead of overruled.

**A general thread must be named.** `title` is required when the subject is the
Circle and null everywhere else, because an object-scoped thread is named by
what it hangs on. The room §20.3 feared is one undifferentiated log; a list of
named topics is a table of contents.

**Drift is recoverable rather than prevented.** `POST /threads/{id}/attach`
moves a general thread onto the goal, claim, decision, commitment or evidence
item it turned out to be about. The whole conversation goes — every comment,
every mention, every on-record marking — because none of that ever lived on the
subject. Substance still migrates; what changes is that somebody can put it back
in one action, and the packet records that the thread arrived that way.

One direction only, the target is checked to be in the same Circle, and filing
needs `comment.moderate` rather than `comment.create`: re-filing somebody else's
conversation changes where it appears for everyone who can read it, which is a
different act from taking part in it.

Nothing about the room loosens visibility. Threads still default to `party`
scope, invisible to everyone outside that company including the convener, and
export inclusion is still decided per comment.

---

## Being told

A record nobody is told about is a record nobody opens. Five things leave the
browser, and the list is meant to stay short: an invitation and its token, a
decision that named you as its approver, a date that moved and waits on your
party, a mention, and — the one that was missing entirely — a version you
relied on being superseded.

**A notification is a disclosure**, so `Notifier` is the only thing that sends
one. Each kind declares the permission its recipient must hold, and every
message is put through `AccessGate` immediately before it goes. Somebody whose
access was narrowed since they cited a document is silently not told, because
an inbox sits outside the system and no gate will ever run on it again. The
check uses `allows()` rather than `authorise()` on purpose: declining to speak
is not an attempted access, and writing `access.denied` for everyone considered
would fill the record with events no person caused.

**Superseding answers "what did this affect".** `SupersessionImpact` reports the
claims that cite a version and the approvals bound to it, and
`GET /evidence-versions/{version}/impact` asks the same question *before*
replacing something — which is when it is useful, because the answer belongs in
the covering note. Nothing is marked stale and no decision is reopened: a
revision reissued for another package invalidates nobody's judgement, and the
software is in no position to decide that it does. Where nothing relied on a
version, nobody is told at all.

Mail is queued and `afterCommit`, and every failure is swallowed and logged. The
state change is already committed and in the chain by the time anyone is told
about it; a mailer having a bad afternoon must not undo an approval.

### Getting evidence in

`uploads/sign-batch` and `evidence/batch` take a whole folder in two requests,
preserving the path as the item's name — `01 Tender / Addendum 3` rather than
140 files in a flat list. Unsupported entries are reported rather than fatal,
because every real folder has a `.DS_Store` in it and failing the drop over one
teaches people to go back to uploading one at a time. A batch that half works
keeps the half that did, and names the rest with the reason.

---

## Agents

Agents come in two kinds and run through one code path, which is the reason the
second kind is safe to offer at all.

**The Circle Steward** ships with the product. Its mandate is part of Circle's
guarantees rather than a customer setting: `propose` mode, no tools, and a
prompt nobody outside this repository can edit.

**Authored agents** are written by customers in the studio. A blueprint carries
a mandate, an execution mode (`read_only` · `propose` · `execute`) and a list of
declared tools. `AgentRunner` runs both kinds — the retrieval guard, the
citation validator and the audit chain do not ask whose agent they are serving.

An authored mandate is *quoted* rather than concatenated into the prompt: it
sits inside a sentinel it cannot terminate, the platform's rules are restated
after it so the last word is ours, and evidence is named as data rather than
instruction. That last part is not decoration — in a cross-company Circle the
agent reads uploads from the counterparty, and instructions and evidence must
never be able to trade places.

An agent acts for the organisation that authored it. The party is resolved from
the blueprint and is not expressible in the model's output schema, so an agent
cannot move liability onto a company that never agreed to carry it.

### Execution

`execute` mode agents propose tool calls. A proposal is written to
`agent_actions` before anything is attempted, and anything with a side effect
waits for a human holding the authority of the party that bears it. Approving
runs it in the same request; the ledger records the result, or the failure, on
the same row.

Five tools have implementations, all `circle_write` — reversible, confined to
one Circle, and landing in the same register a person's edits land in: post a
comment, create a goal, report progress, create a commitment, flag evidence as
stale. `external_write` and `financial` are classifications the approval rules
already honour with nothing registered under them; a blueprint may declare such
a tool and the registry refuses at execution rather than pretending it ran. The
studio says which tools are real before anyone approves one.

`ToolRegistry` also refuses a handler more consequential than what the approver
was shown, so a payment cannot be registered against a tool declared
`circle_write` and collect a reviewer's signature for something needing an
owner's.

`circle:sweep-agent-actions` runs every five minutes: it expires proposals
nobody answered, and drains approvals given while the worker was down.

### The Steward's guarantees

All enforced rather than described:

- It is a **first-class principal**, not a user acting in disguise. It has its
  own identity and its own row in the policy gate; it never borrows credentials.
- It sees **only extracted text from items explicitly marked agent-readable**.
  Every candidate is put through `AccessGate` individually, and refusals are
  logged alongside retrievals — so "what did it read, and what was it refused"
  has a complete answer.
- The model returns **structured JSON against a schema**; it cannot call
  anything. `CircleSteward` then validates every citation against the evidence
  that run actually retrieved and **discards any claim whose citations were
  fabricated**, recording how many were rejected.
- Everything it produces is a **draft**: claims at `derived`, decisions at
  `draft` with no approver assigned. It cannot approve, delete, invite, change
  permissions or reach anything outside its Circle.
- Closing a Circle disables it outright.

Set `ANTHROPIC_API_KEY` in `api/.env` to enable it. Without a key, runs fail
loudly and are recorded as failed — they never return an empty brief that looks
like a real one.

### One model per job

Everything ran on `claude-opus-5`, which is the right default for open-ended
reasoning and the wrong one for what this application actually asks of a model.
Three jobs, priced separately in `config/circle.php`:

| Task | Default | Effort | What it is |
|---|---|---|---|
| `convening` | `claude-sonnet-5` | `low` | Read one document into a fixed schema whose every field PlanResolver recomputes afterwards |
| `brief` | `claude-sonnet-5` | `medium` | What the evidence shows, where it contradicts itself, what is missing |
| `authored` | `claude-sonnet-5` | `medium` | Mandates customers wrote, which we cannot predict |

Sonnet 5 is $2/$10 per million tokens against Opus 5's $5/$25 — 60% off both
sides of the meter for work that was never reasoning-bound. Each is one env var
(`AGENT_MODEL_CONVENING`, `AGENT_MODEL_BRIEF`, `AGENT_MODEL_AUTHORED`), and
`AiProvider::forTask()` returns a configured copy so one task's model can never
leak onto the next caller. An unknown task name falls back to the default rather
than throwing: a typo must not take down a run.

**Convening is the one to think hardest about before going cheaper.** Haiku 4.5
is $1/$5 and the extraction is well within it — but `basis`, the stated-versus-
inferred judgement, is the trust mechanism the whole review screen rests on, and
it is the first thing a smaller model blurs. If you drop it, check the
stated/inferred split against a contract you know before you keep it.

**What the schema may say is narrower than JSON Schema.** Structured outputs
takes a subset: no numeric or length bounds, no array cardinality,
`additionalProperties` only ever false, and at most 24 optional parameters
counted across every nesting level and every place a shape is inlined. The
Python and TypeScript SDKs strip the unsupported keywords client-side; the PHP
one does not, so `StructuredSchema` does it here — the builders go on saying
`minimum: 1` because it documents the intent and because PlanResolver enforces
it after the run regardless. The optional-parameter budget is not strippable, so
`AnthropicProviderTest` holds both schemas under it.

---

## Starting from the contract

Everything above assumes somebody types the plan in. That is the right model of
where a plan comes from — a person decides it — and in the cases this product is
aimed at it is also a transcription exercise, because the plan already exists.
It is in the engagement of terms the parties have just signed, along with the
dates, the deliverables and the acceptance tests.

So drop the contract on the Circles page instead. What you land in is a working
Circle, not a form and not a review queue:

| Out of the document | Into the Circle |
|---|---|
| Recitals and scope | The mission statement — name, purpose, term dates |
| Who is engaging whom | The parties, with the position each holds |
| Phases, packages, deliverables | The goal tree, with acceptance conditions and the clause each came from |
| Dated deliverables | A commitment against the goal it belongs to, owed by a party |
| What it leaves unsettled | Draft decisions with nobody named against them |
| The document itself | Filed as evidence against every goal it produced |

and a Steward brief queued behind it, so the Circle has a summary and its gaps
flagged by the time anyone reads it.

**Two acts, by two actors, and keeping them apart is the whole design.** The
**Circle Convener** — the second agent that ships with the product — reads the
document and writes one derived artifact. It renames nothing, adds no party and
creates no goal. Everything above is then written by the *person* who convened:
the goals through `GoalService` under their name, the mission statement through
the audited path with a before and after, the parties and commitments and
decisions as though entered by hand.

The Convener holds `read_only`, whose ceiling is `circle.view` and
`resource.agent_read` and nothing else. It needs no more, because it never
writes the plan — and that is the point rather than an accident. The most
consequential object in a Circle is the one saying who owes what to whom and by
when, and an agent able to write it directly would be putting a model's reading
of a contract into the record with nobody's name against it. Convening writes
immediately, but it writes as somebody.

**The model reads; the application calculates.** `ConveningSchema` is held to
`OutputSchema`'s bar — a field exists only if a language model is the only thing
that can answer it — and four things are decided in PHP because of it. No date:
a schedule is a date the document states, or a quantity and a unit measured from
commencement, and `PlanResolver` turns "within 20 business days" into the 29th of
March against a calendar. No structure: `level` says how deeply a step nests and
the tree is assembled in PHP, where the depth cap is enforced and a level that
jumps is reported rather than dropped. No party matching: "the Supplier" is
resolved by string comparison, and a near-miss is left unassigned, because work
given to the wrong company is worse than work given to nobody. And no judgement
about which steps are deliverables — a step becomes a commitment if nothing hangs
beneath it and it has a date, which is structural, so it is answered structurally.

Which means **re-reading a plan against a different start date calls no model**.
`GET /circles/{circle}/convening?anchor=…` re-resolves stored output: same plan,
periods measured from somewhere else, free and identical every time.

**Every line says stated or inferred.** Not a confidence score — a reviewer with
twenty rows in front of them cannot calibrate 0.72. What they need is to know
which lines to check against the contract. Inferred is not a defect: a contract
naming a deliverable and no milestone still implies work, and proposing it is
the useful part. It is drawn in the derived treatment used everywhere else for
machine-made content.

Because the plan is written immediately, the reading it came from is kept as a
**receipt** rather than a gate — under "Convening" in the Circle's own menu —
and the receipt is worth more than the gate was. The goals are now editable in
the ordinary places, which is where somebody will actually change them; what
they cannot get anywhere else is which lines the contract stated and which the
machine worked out, what had to be repaired, and which dates were computed from
what. A computed date carries its period on the face of the row, because a
plausible-looking wrong date is the single most likely thing to survive a
review — and it is now a date somebody may already be working to.

Two refusals worth knowing about. **A contract that has already concluded does
not expire the Circle**: the expiry is an authorisation decision and the gate
would refuse every write on the next request, while the document's end date is
a fact about the document. And **a Circle that already has a plan is not written
into again** — reading the same contract twice is how a variation arrives, but
writing the second reading on top of the first would silently double every goal.
In both cases the reading still happens and the response says why nothing was
written.

### What convening does not do

- **It creates no Circle on its own.** There is no endpoint that takes a
  document and returns a Circle. Convening happens inside one, and every write
  is somebody's.
- **It reads nothing about money.** Rates, caps and liquidated damages are in
  every engagement of terms and none of them are extracted. §21 holds:
  `financial` is a classification with nothing registered under it.
- **It proposes no engagement.** An engagement bounds the gate, and a contract
  term read out of a PDF is not a basis on which to start refusing somebody's
  writes. The dates land on the Circle, visible and editable.
- **It makes no claims.** A plan is what a document says it will do, not an
  assertion about the world. Claims are the Steward's job, and the brief queued
  behind convening is where they come from — cited, at `derived`, reviewable.
- **It reconciles no second document.** Uploading a variation and asking what
  changed is the obvious next thing and is not built.
- **It tells nobody.** A Circle appearing fully formed is not yet something
  §22's transport carries.

---

## Meetings keep it current

A contract says what the work is at the start. Meetings say what happened to it
after. Send a meeting transcript in — pasted on the Meetings page, or pushed by
a note-taker — and the Circle it was about is updated with nobody approving each
change: work agreed is added, work reported done is closed, work dropped is
abandoned, dates that moved are moved.

That reverses the rule every other agent here follows, so it is reversed
narrowly.

**Autonomy stops at the Circle's walls.** `agent_blueprints.autonomous` lets an
agent's actions be approved by policy when proposed. It is on for one agent —
the **Circle Scribe** — and off for everything else, including every agent a
customer writes. It reaches `circle_write` actions only:
`SideEffect::mayRunAutonomously()` is consulted on every proposal, so an
autonomous agent proposing an email or anything financial still waits for a
person, whatever its blueprint says.

**Autonomy removed the approval, not the ledger.** Every change is an
`agent_actions` row with its intent, arguments, result and a quotation from the
meeting. The Meetings page reads that back: where each meeting went and why,
what it changed line by line, and what it heard but chose not to act on.

**Closing is not accepting, and dropping is not deleting.** A goal a meeting
closes is `met` and settled, but `accepted_by_user_id` stays empty — no person
accepted it — and `completed_by_agent_run_id` names the reading that did.
"Delete" is `abandon_goal`: the goal leaves the live plan and stays in the
record, the open work beneath it goes with it, and commitments still counting
down under it are cancelled. A moved date is a schedule change attributed to the
agent, never to whoever owns the connector.

**The model reads, the application counts** — the same rule as convening. "Push
it back a week" comes back as `shift: {value: 1, unit: weeks}`; `WorkingCalendar`
turns it into a date. Operations name goals by the ids they were shown, or by a
label an earlier operation in the same meeting created. Each operation type is
its own schema variant with its own required fields: the first version was one
object with fourteen optional properties, and a live model filled it with
`"placeholder"`.

**Discussion is not decision.** The Scribe acts on what was agreed, reported
as fact, or taken on by someone, and says in the log what it heard and left
alone. On its first live run it declined to create a second crane pad that one
person floated and another said not to decide.

**Where a meeting goes.** A transcript is addressed to a company. The router
picks an existing Circle, convenes a new one, or sets it aside — the third
answer is what stops a Circle being opened for every one-to-one. It runs on
Haiku 4.5 because it is a classification over a short list, and not at all when
there is nothing to choose between. A kickoff is convened with the meeting's
date as the anchor, so "within three weeks" means three weeks from the meeting.

**The connector is write-only.** A connector token carries `transcripts:ingest`
and one company's id, and `ConfineScopedTokens` refuses it on every route but
the one that takes a transcript. It cannot read a Circle, the log, or what its
own transcripts changed, because it lives in somebody's Zapier account from
then on. Its transcripts run under the name of the person who created it.

Wiring Granola, Otter or Fireflies is a Zapier or Make step — "when a note is
ready, send a webhook" — posting to `/api/transcripts`:

```json
{
  "transcript":  "<the transcript text>",
  "title":       "<the meeting title>",
  "occurred_at": "<the meeting date>",
  "external_id": "<the note's id>",
  "source":      "granola"
}
```

`external_id` matters: automations retry, and it is how a meeting sent twice is
applied once.

A weekly meeting costs about two cents (Haiku routing, Sonnet reading); a
kickoff that opens a Circle about five, including the Steward's brief.

### What meetings do not do

- **No note-taker is integrated directly.** None share a webhook format, so the
  endpoint takes a transcript and an automation service sits in between.
- **No decisions are drafted.** A meeting's loose ends go in the log, not into
  draft decisions nobody owns.
- **Nothing is notified.** A goal closed by a meeting tells nobody yet.
- **No undo.** Every change is on the ledger and reversible by hand; there is no
  "revert this meeting", because later meetings build on earlier ones.
- **Speakers are not verified.** "Northline signed it off" is taken as said.

---

## Finding the counterparty

Everything above assumes you already know who you are working with. Section 21
of the spec covers what happens when you do not — and what either side carries
away afterwards.

**Open work is a posting; applying is a branch.** A goal with no responsible
party is not a gap in the plan, it is work somebody has to be found for, and it
is the only object in this product a person outside the Circle may see. That
exception is bounded in the schema rather than in a controller: `work_openings`
carries its own copy of everything an applicant is shown, so there is no join a
future endpoint could follow into the tree, the evidence or the threads.

Applying grants nothing. **Shortlisting** is the separate act that lets somebody
in, and it admits their company as a party, proposes a contract, and issues a
seat bounded by it — all in one transaction, because a seat granted before the
contract exists is a window in which a stranger is an ordinary contributor. A
company that receives forty applications exposes its Circle to none of them.

Awarding is a **merge**. The assignment lands through §20.7's unchanged rule, so
there is no separate "accept" that could assign work nobody agreed to.

**An engagement bounds the gate.** `circle_memberships.engagement_id` is read by
AccessGate as check (7): a contract that has ended, been suspended, or not yet
started refuses every write on the very next request, and one scoped to a
package refuses everything outside that subtree. The term is checked against the
clock rather than against a status somebody was supposed to change — *temp* is a
property of the authorisation decision, or it is marketing. The check only ever
narrows, so a Circle with no engagements behaves exactly as it did before.

The scope check **fails closed**: a permission whose subject is a goal, asked
without one, is refused rather than passed. A check that quietly succeeds when
nobody said which work is worse than no check, because the audit log reads as
though it ran.

**The record is portable, and the counterparty signs it.** `work_records` is the
one table not scoped to a Circle. It is compiled from the ledgers when an
engagement ends — deliverables accepted, met late, never done; branches
proposed and merged; agent actions proposed, executed and *refused* — attested
by the engaging party, and published by its subject. Three parties, three jobs,
and none of them can do another's: the contractor cannot write their own record,
and cannot edit a published one, only hide it.

It has its own hash chain, keyed per principal rather than per Circle, so a
record verifies after the Circle it came from is closed and gone. Publication is
deliberately outside the digest — if hiding a record disturbed its signature,
the one thing the chain exists to detect would drown in noise.

Agents carry records on the same terms. An engagement names an agent *instance*,
because that is the identity the gate authorises; a record names the *blueprint*,
because an instance dies with its Circle. `refusals_json` is kept out of the
metrics on purpose: an agent that keeps proposing actions its counterparty
refuses is the thing a prospective hirer most needs to see, and a renderer
should not be able to drop it by accident.

**A work package is the durable artifact.** Not the Circle — that is ephemeral
by design. `work_packages` is a goal tree with dates replaced by offsets and
everything identifying removed: no evidence, no parties, no ids, and a party
*role* rather than a party. `capture()` takes the shape from a Circle,
`instantiate()` writes it into one against an anchor date, and `fork()` copies it
to another company and records where it came from.

**Discovery is a network before it is a market.** Visibility on an opening is
`party`, `circle`, `network` or `public`, and defaults to network — the
companies you have already been engaged with, derived from contracts rather than
declared. A public tier exists and is opt-in per posting.

**A blueprint version is what you hire.** §20.6 established that only an agent's
author may edit it; once a counterparty is paying, editing it silently is worth
money. `agent_blueprint_versions` freezes the mandate, the execution mode and
the declared tools with their side-effect classification, hashed, and an
engagement names a version rather than a blueprint. `per_action` engagements
meter from the existing action ledger — when an action *executes*, never when it
is proposed, because an agent does not get paid for asking.

### What section 21 does not build

- **No money moves.** Fees, currencies, bases, caps and accepted deliverables —
  no invoice, no escrow, no settlement. `financial` remains a side-effect
  classification with nothing registered under it, and the schema says so by
  holding an amount and no transaction table anywhere.
- **No identity verification.** A record attested by a counterparty is only as
  good as that counterparty being who they say, and nothing checks that.
- **No matching, and openings are not notified.** Discovery answers "which
  openings may this person see". It does not rank them, match them against a
  record, or tell anybody. The transport added since §20.5 deliberately carries
  only things that happened to *you* inside a Circle you are already in; a
  posting is neither, and an opening mailed to a network is a mailing list.
  Which leaves the original problem standing: an opening nobody is told about is
  an opening nobody applies to.
- **No cross-instance portability.** A record is portable within one deployment.
  Carrying it elsewhere would need the chain to verify against a published key,
  and none is published.
- **Openings, engagements and records are not in the packet.** The gap §20.5
  named for the goal tree, the threads and the action ledger has since been
  closed — all three are exported — but section 21's own objects are not, so a
  packet describes the work and not the commercial relationship it was done
  under.

---

## Layout

```
api/                     Laravel 12 · PHP 8.4
  app/Enums/             the trust, role and permission vocabulary
  app/Services/
    Authorisation/       AccessGate — the single policy entry point; DelegationService
    Audit/               the hash chain and its verifier
    Evidence/            ingest, versioning, signed storage access
    Agent/               AgentRunner, retrieval guard, prompts, the action ledger
      Tools/             ToolRegistry and the handlers that actually do things
    Convening/           reading an engagement of terms into a proposed plan
    Transcripts/         meetings in: routing, the Scribe, the import log
    Work/                openings, applications, engagements, records, packages
    Ai/                  provider interface + Anthropic implementation
    Exports/             the mission packet
  app/Jobs/              the media pipeline, one job per lane
  tests/                 feature tests + fixture generators
web/                     Astro 7 · React 19 islands · Tailwind 4
  src/components/Trust.tsx   the trust vocabulary, rendered
  src/components/ConveningView.tsx  the plan a contract was read as, before anyone accepts it
  src/components/MarketView.tsx  open work, contracts and the record — no Circle
  src/components/HiringView.tsx  the other side of the same thing, inside one
  src/layouts/Marketing.astro  the public site shell
  src/pages/legal/           the seven policy documents, as Markdown
  PLACEHOLDERS.md            everything that must be filled in before publishing
docker/                  PHP image with ffmpeg, poppler, tesseract
demo.py                  the First Demo Script, end to end
```

### The public site

The same Astro app serves the marketing site and the legal documents, so the two
share one design system and the product cannot drift away from how it is
described. `/` is the landing page; `/product`, `/security` and `/contact` sit
beside it, and `/legal` indexes seven policy documents written against Australian
law — Terms, Privacy, a Data Processing Addendum with both annexes, the
sub-processor list, Acceptable Use, Cookies and Responsible Disclosure.

**None of it is publishable as it stands.** Every fact only the company knows —
the legal entity, the ABN, the hosting region, the notice periods — is a visible
placeholder rather than a plausible invention. Two guards enforce that:
`robots.txt` disallows the whole site while the origin is unset, and every legal
page carries a red banner and a `noindex` while the entity is. Both lift
themselves once `web/src/lib/site.ts` is filled in. `web/PLACEHOLDERS.md` is the
checklist.

The design language is borrowed from engineering document control — title
blocks, revision stamps, ruled registers, ink on warm paper — because this is a
controlled document, not a dashboard. Machine-derived content is hatched and
cool-toned throughout so it can never be mistaken for evidence.

---

## What is deliberately not here

Per spec §3: no chat or Rocket.Chat integration, no freeform canvas, no general
agent builder, no autonomous external communication or source-system writes, no
Drive/SharePoint/email/Zoho/GitHub/Linear connectors, no verifiable credentials
or blockchain, no enterprise SSO/SCIM/billing, no nested-organisation RBAC.

Five of those have since been reversed deliberately and are recorded as such in
the spec rather than quietly dropped: §20.4 reverses "no general agent builder",
§21 reverses the implicit assumption that both parties already know each other,
§22 reverses §20.3's refusal of a Circle-wide room, §23 reverses the
assumption running through §1-19 that a plan is always typed in by hand, and
§24 reverses "an agent proposes and a person approves" — for in-Circle writes,
for one agent, and nothing further. What §21 does *not*
reverse is the gate: an opening is the single object reachable without a Circle,
and it is bounded by its own columns.

### Delegation and party scope

A right can be handed to one person without moving them to a role carrying a
dozen others — `role_grants`, written under two rules: nobody can grant what
they do not hold, and the convening owner cannot be denied membership control or
closure. A resource can be scoped to one company: the gate applies the narrowest
override that matches, so a resource-wide deny plus a party allow means "only
this company sees it", and a party deny plus a named-user allow means "nobody at
that company except the one person we agreed on".

Another party's agent is admitted by an owner who is not from the agent's own
party, against a specific key fingerprint rather than a name, and the acceptance
is recorded as a `Decision` so the packet says who let it in.

---

Beyond those, the following are specified but **not implemented** in this build:

- **URL capture.** `source_url` is recorded on an evidence item, but there is no
  fetcher, no HTML snapshot and no content hash for a captured page.
- **Transcription** ships as a provider interface with an OpenAI-compatible
  Whisper implementation. With no `TRANSCRIPTION_API_KEY` set, transcripts are
  marked `skipped` rather than left pending. The path is untested against a live
  provider.
- **The OpenAI provider.** `AiProvider` is provider-agnostic and an adapter would
  drop in without caller changes, but only the Anthropic implementation exists.
- **Realtime.** Processing status is polled, not pushed; Reverb is not wired up.
- **A generated OpenAPI document.** Routes are defined in `api/routes/api.php`
  and mirror spec §15.
- **Tool execution is unverified against a real model.** The read paths are
  not: a Steward brief and a convening run have both been driven end to end
  against live Claude, which is what turned up three schema constraints the
  scripted provider could never have — unsupported keywords, an open `locator`
  object, and an optional-parameter budget. What has still only been exercised
  against a scripted provider is an `execute`-mode agent proposing a tool call,
  so `tool_calls.arguments` — now a JSON string the runner decodes, since there
  is no way to express "any object" — has not been seen coming back from a real
  model.
- **No transport for a remotely-hosted party agent.** `agent_connections`
  records the endpoint, auth mode and key fingerprint, and admission binds a
  blueprint and gives it an instance — but nothing signs a webhook or speaks MCP
  to software running on the other party's infrastructure. A party's agent runs
  today because its *mandate* is authored here and executed under that party's
  authority by Circle's own model calls.
- **No push beyond email.** There is a transport now — invitations, assigned
  decisions, a superseded version something relied on, a moved date awaiting a
  counterparty, and mentions all leave the browser — but it is transactional
  email and nothing else. No digest, no reminder, no in-app toast, no push, no
  webhook. `MAIL_MAILER=log` writes them to the log until a mailer is
  configured.
- **Closure and export stop at §20's objects.** The packet now carries the goal
  tree — with derived progress and every schedule change, including which party
  agreed to it — the comment threads, the agent runs and the action ledger, so
  the chronology of a mission travels with the evidence rather than staying
  behind in the application. A party-scoped thread contributes only its
  on-record comments, and each thread reports how many were withheld, so a
  reader can see that something was said without being shown it. What the packet
  still does not carry is §21: openings, engagements and the work records
  closure compiles. Everyone engaged carries something away; the exported packet
  is not yet how they carry it.
