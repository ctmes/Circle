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

| Service | URL | What it is |
|---|---|---|
| Web | http://localhost:4321 | Astro + React front end |
| API | http://localhost:8000/api | Laravel JSON API |
| MinIO console | http://localhost:59001 | Evidence vault (`circle` / `circlecircle`) |

Create an organisation and some accounts — organisation setup is an
administrative act, not a self-service endpoint:

```bash
docker compose exec api php artisan circle:provision-org "JWA Mats" \
  --user="gm@jwamats.test:Dana Okafor:a-long-password-here"
```

Then sign in at http://localhost:4321.

### Seeing the whole thing work

```bash
python demo.py              # runs the First Demo Script (spec §18) end to end
KEEP_OPEN=1 python demo.py  # same, but leaves the Circle open to click around
```

`demo.py` drives the real system over HTTP exactly as a browser would: signed
direct-to-storage uploads of PDF/DOCX/XLSX/JPG/MP4/WAV, the extraction pipeline,
citations against exact evidence versions, approval bound to a version, version
supersession, agent policy enforcement, tamper detection, closure and the
exported mission packet. **80 checks.**

### Tests

```bash
docker compose run --rm api php artisan test    # 36 feature tests
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

## The Circle Steward

One read-only agent. Its guarantees, all enforced rather than described:

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

## Layout

```
api/                     Laravel 12 · PHP 8.4
  app/Enums/             the trust, role and permission vocabulary
  app/Services/
    Authorisation/       AccessGate — the single policy entry point
    Audit/               the hash chain and its verifier
    Evidence/            ingest, versioning, signed storage access
    Agent/               Circle Steward: retrieval guard, prompt, run coordinator
    Ai/                  provider interface + Anthropic implementation
    Exports/             the mission packet
  app/Jobs/              the media pipeline, one job per lane
  tests/                 feature tests + fixture generators
web/                     Astro 7 · React 19 islands · Tailwind 4
  src/components/Trust.tsx   the trust vocabulary, rendered
docker/                  PHP image with ffmpeg, poppler, tesseract
demo.py                  the First Demo Script, end to end
```

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
- **Horizon** is installed but the workers run under `queue:work`.
- **A generated OpenAPI document.** Routes are defined in `api/routes/api.php`
  and mirror spec §15.
- **The live agent path is unverified against a real model.** No API key was
  available in this environment, so the Steward's model call has only been
  exercised against a scripted provider. Its guard, persistence, citation
  validation and audit behaviour are covered by tests; the shape of a real
  Claude response is not.
