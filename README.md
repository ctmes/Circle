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
| MinIO console | http://localhost:59001 | Evidence vault (`circle` / `circlecircle`) |
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
docker compose run --rm api php artisan test    # 217 feature tests
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

Set `ANTHROPIC_API_KEY` in `api/.env` to enable it (default model
`claude-opus-5`). Without a key, runs fail loudly and are recorded as failed —
they never return an empty brief that looks like a real one.

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
    Work/                openings, applications, engagements, records, packages
    Ai/                  provider interface + Anthropic implementation
    Exports/             the mission packet
  app/Jobs/              the media pipeline, one job per lane
  tests/                 feature tests + fixture generators
web/                     Astro 7 · React 19 islands · Tailwind 4
  src/components/Trust.tsx   the trust vocabulary, rendered
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

Three of those have since been reversed deliberately and are recorded as such in
the spec rather than quietly dropped: §20.4 reverses "no general agent builder",
§21 reverses the implicit assumption that both parties already know each other,
and §22 reverses §20.3's refusal of a Circle-wide room. What §21 does *not*
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
- **The live agent path is unverified against a real model.** No API key was
  available in this environment, so agent model calls have only been exercised
  against a scripted provider. The retrieval guard, persistence, citation
  validation, tool proposal, execution and audit behaviour are covered by tests;
  the shape of a real Claude response is not.
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
