Circle MVP — Technical Specification

> **Amended, 2026-08-24.** Sections 1-19 describe the single-organisation,
> read-only-agent MVP as built. The direction has since changed on three points
> those sections explicitly rule out: a Circle now spans several companies as
> *parties*, conversation is a first-class object, and agents are authored by
> customers and can execute. Section 20 records what changed and what still
> holds. Where 1-19 and 20 disagree, 20 wins. The earlier numbering is left
> alone because the code refers to those sections by number throughout.

1. Product Summary
Circle is a trusted, temporary operating environment for organisations collaborating on a consequential outcome.

A Circle groups only the people, agents, media, connected-system records, claims, decisions, approvals, commitments, and access rights required for one mission. It is not a chat app, CRM, project-management suite, file drive, or agent builder.

MVP mission:

JWA Mats creates a Circle for an opportunity, quote, bid, or mobilisation package. Internal staff and approved external collaborators contribute and review evidence. A read-only agent turns approved evidence into sourced summaries, highlights missing inputs, and prepares decision requests. Rocket.Chat remains the conversation layer and is out of scope for the first build.

Primary pilot:

Rail Access Package — Bid Review

JWA staff assemble an RFQ, drawings, geotechnical documents, site photos, videos, load schedules, commercial assumptions, and delivery constraints. Commercial, technical, and logistics users review only their relevant responsibilities. The system produces an attributable record of the evidence, decisions, approvals, and final package.

2. Goals
Functional goals
Create a Circle with purpose, owner, status, and closure/expiry date.

Invite internal and external users with explicit Circle roles.

Ingest and preserve multiple media types as immutable evidence.

Record source, uploader, timestamp, content hash, version lineage, review state, access rights, and expiry for each evidence item.

Extract searchable representations from documents, images, audio, and video.

Allow users and agents to create claims that cite exact evidence locations.

Allow reviewers/approvers to review and approve exact versions of claims, deliverables, and decisions.

Run one strictly read-only agent: Circle Steward.

Create an append-only, tamper-evident audit log.

Export a Circle evidence/decision packet at closure.

Product principles
A Circle contains only what enables its mission.

Original media is preserved; AI interpretations are separate derived artifacts.

A claim is not true merely because an agent states it.

Every material claim must show source evidence, issuer, confidence/review state, and version.

No agent can access a resource merely because it belongs to the same organisation.

No agent receives raw connector credentials.

High-impact actions remain outside the MVP.

3. Explicit Non-Goals
Do not build these in the MVP:

Native chat, channels, calls, or a Rocket.Chat integration.

Full freeform canvas/graph editor.

General agent builder, multi-agent orchestration, or marketplace.

Autonomous external communication or source-system writes.

Full Drive, SharePoint, email, Zoho, GitHub, or Linear integrations.

Cryptographic verifiable credentials, DID infrastructure, blockchain, or a public agent network.

OCR/forensic/deepfake output presented as truth verification.

Enterprise SSO, SCIM, billing, data residency selection, or dedicated tenant deployment.

Complex RBAC inheritance across nested organisations.

The MVP must support manually uploaded evidence and manually entered/signed links first.

4. Personas and Roles
Personas
Circle owner / GM: creates the mission, sets expiry, controls membership, receives final decision requests.

Commercial contributor: adds quote assumptions, pricing inputs, customer correspondence, and commercial risks.

Technical reviewer: reviews specifications, site conditions, technical configuration, and technical claims.

Logistics contributor: supplies stock, transport, installation, delivery, and retrieval information.

External collaborator: only sees explicitly shared Circle material; contributes evidence or reviews assigned items.

Approver: approves/rejects exact versions of defined decisions or deliverables.

Circle Steward agent: reads only allowed Circle context; creates sourced summaries, flags missing evidence and decision blockers; cannot act externally.

MVP Circle roles
Role	Permissions
owner	Full Circle management, membership, resource sharing, close/archive, create decisions, approve when assigned
approver	Read, comment, contribute, create claims/decisions, approve assigned requests
reviewer	Read, comment, contribute, review/reject claims and evidence, no final approval
contributor	Read permitted material, upload evidence, create claims/comments, update assigned commitments
viewer	Read permitted material only
agent	System-managed; access only through explicit policy checks; no user-facing administration
Use role defaults plus per-resource overrides. The effective permission is the intersection of Circle membership, resource policy, and action policy.

5. Core Concepts
Circle
A bounded mission context.

Required fields:

text
id
organisation_id
name
purpose
status: draft | active | closing | archived
owner_user_id
starts_at
expires_at
closed_at
created_at
updated_at
Evidence item
A media item, source record, or external URL preserved as evidence.

Examples:

PDF RFQ

DOCX scope of work

XLSX load schedule

Image/site photo

Video/site walkthrough

Audio/voice note

URL capture

Plain text note

Manually created statement

An evidence item always has an immutable original/version record. AI extraction and analysis are separate derived artifacts.

Claim
A human or agent assertion backed by zero or more evidence citations.

Examples:

“The requested crane load exceeds the configuration in the initial proposal.”

“Site conditions appear inconsistent with the original ground-bearing assumption.”

“Mobilisation by Monday is at risk because freight confirmation is pending.”

Claims have explicit status and are never silently promoted to facts.

Decision
A request for a named person to choose/approve an exact object/version.

Examples:

Go/no-go on a bid.

Approval of a mat configuration.

Approval of a quote revision.

Approval of a scope change.

Commitment
A time-bound responsibility with an owner and acceptance condition.

Examples:

“Logistics lead to confirm stock availability by Friday 2pm.”

“Technical lead to review geotechnical report before configuration approval.”

Derived artifact
An agent or system-generated output.

Examples:

OCR text.

Transcript.

Image observation.

Requirements matrix.

Weekly brief.

Agent summary.

A derived artifact records the source artifacts, model/provider metadata, prompt/template version, run ID, and confidence where applicable.

6. Trust and Status Model
Do not use a generic green “verified” badge.

Origin status
text
verified_source       - retrieved from an authenticated connected source (future)
authenticated_upload  - uploaded by an authenticated Circle participant
unverified_upload     - uploader/source cannot be reliably identified
attested              - verified identity made a statement about an item
MVP support: authenticated_upload, unverified_upload, and attested.

Integrity status
text
intact       - current binary hash matches recorded hash
superseded   - a newer version exists
changed      - file/version mismatch or integrity check failure
unknown      - integrity not yet computed
Meaning/review status
text
unreviewed
reviewed
approved
derived
contested
stale
A document can be authenticated_upload + intact + unreviewed. A claim can be attested, derived, reviewed, or contested.

7. Evidence and Media Requirements
Supported MVP uploads
Type	Formats	MVP processing
Documents	PDF, DOCX, TXT, MD	Store original, hash, extract text, page references for PDF
Spreadsheets	CSV, XLSX	Store original, hash, extract sheets/cells to structured text
Images	JPG, JPEG, PNG, WEBP, HEIC	Store original, hash, thumbnail, EXIF extraction, OCR if available
Video	MP4, MOV, WEBM	Store original, hash, duration/metadata, audio extraction, transcript, timestamp segments, thumbnails/frames
Audio	MP3, WAV, M4A, OGG	Store original, hash, duration, transcript with timestamps
Other	ZIP, PPTX, EML	Preserve original; extraction optional/deferred
URLs	HTTP/HTTPS	Save URL, fetch timestamp, HTML/text snapshot if allowed, content hash
Ingest pipeline
Browser requests a signed direct-upload URL.

Browser uploads original binary directly to private object storage.

Backend creates evidence_item and evidence_version in processing state.

Background job calculates/validates SHA-256 hash and MIME type.

Background jobs create previews and extraction artifacts.

Backend marks version ready or failed.

Audit events record upload and processing outcomes.

Media handling rules
Never overwrite an uploaded original.

Every replacement creates a new evidence_version with supersedes_version_id.

Originals remain private in object storage.

Preview/transcript/OCR output has its own derived artifact record.

EXIF metadata can be displayed but must not be treated as proof of location/date without an explicit caveat.

Video/image AI observations are derived, not verified facts.

Download/export rights are distinct from view rights.

8. Claims, Citations, Reviews, and Decisions
Claim schema
text
id
circle_id
author_type: user | agent
author_id
statement
claim_type: factual | technical_assessment | commercial_assessment | risk | recommendation
status: draft | attested | derived | under_review | reviewed | approved | contested | rejected
confidence: decimal nullable
created_at
updated_at
Evidence citation schema
A citation must point to a precise evidence location where possible.

text
id
claim_id
evidence_version_id
citation_type: document_page | text_range | spreadsheet_cell | image | video_timestamp | audio_timestamp | url_snapshot | generic
locator_json
excerpt nullable
created_at
Examples of locator_json:

json
{"page": 18, "start_char": 55, "end_char": 210}
json
{"sheet": "Load Schedule", "range": "F12:H12"}
json
{"start_seconds": 133, "end_seconds": 188}
Review rules
A reviewer can mark a claim reviewed, add comment, request changes, or mark contested.

An approver can approve a decision or deliverable version only if assigned in the decision record.

Approval binds to exact resource_type, resource_id, and version.

Editing an approved resource creates a new version and invalidates the old approval for the new version.

Decision schema
text
id
circle_id
title
description
status: draft | pending | approved | rejected | expired | superseded
created_by_user_id
approver_user_id
subject_type
subject_id
subject_version
expires_at
resolved_at
resolution_comment
9. Agent: Circle Steward
Mandate
The Circle Steward maintains mission clarity. It reads approved Circle context, identifies gaps/contradictions, prepares sourced summaries, and drafts decision requests.

Allowed actions
Read resources where agent_read=true.

Retrieve approved extracted text/transcripts and metadata.

Create a derived summary/brief with citations.

Create draft claims with evidence citations.

Create draft decision requests.

Flag a resource as potentially stale using a configurable policy.

Create/update a commitment only as a draft for human confirmation.

Prohibited actions
No external communication.

No direct email/chat/CRM/API writes.

No user invitations.

No permission changes.

No deletion.

No approval on behalf of a person.

No reading resources outside its Circle.

No use of raw user credentials or raw connector credentials.

Agent output contract
Every output must include:

text
- Output type
- Agent instance ID
- Blueprint/version
- Model/provider identifier
- Run ID
- Created timestamp
- Source evidence citations
- Confidence or uncertainty statement
- Explicit label: “Derived by agent; requires human review”
Agent prompts
Use structured output JSON. Do not let the model directly mutate business data.

Example output:

json
{
  "summary": "The bid is at risk because the load schedule and technical drawing conflict.",
  "status": "at_risk",
  "claims": [
    {
      "statement": "The load schedule specifies a 95t crane while drawing Rev B references a 70t operating assumption.",
      "claim_type": "technical_assessment",
      "confidence": 0.87,
      "citations": [
        {"evidence_version_id": "...", "locator": {"sheet": "Loads", "range": "B7:D7"}},
        {"evidence_version_id": "...", "locator": {"page": 4}}
      ]
    }
  ],
  "decision_drafts": [
    {
      "title": "Confirm crane load basis",
      "description": "Technical lead must confirm which load assumption governs the proposed configuration.",
      "suggested_approver_role": "technical_reviewer"
    }
  ]
}
10. Authorisation Model
Permissions
Define action-level permissions:

text
circle.view
circle.manage_members
circle.close
resource.view
resource.download
resource.upload
resource.share
resource.delete
resource.agent_read
claim.create
claim.review
claim.approve
decision.create
decision.approve
commitment.create
commitment.update
agent.run
export.create
Policy evaluation
Every request must check:

text
1. Is actor an active Circle member?
2. Does Circle role permit requested action?
3. Does resource policy permit requested action?
4. Is Circle active and not expired/archived?
5. Are any explicit deny/expiry constraints present?
6. For an agent: is the resource agent-readable and within its mandate?
Pseudo-code:

php
allow($actor, $action, $resource) =
    $actor->isActiveMemberOf($resource->circle)
    && $rolePolicy->allows($actor->role, $action)
    && $resourcePolicy->allows($actor, $action)
    && !$resource->circle->isClosed()
    && !$resource->isExpiredFor($actor);
Resource sharing
Default resource policy: Circle members can view according to their role; download and agent-read must be explicit toggles.

For external users, default to:

text
view: allowed
upload: allowed if contributor
comment: allowed if contributor
share: denied
download: denied by default
agent_read: denied by default
11. Audit and Tamper Evidence
Audit events
Record at least:

text
circle.created
circle.member_invited
circle.member_role_changed
circle.closed
resource.uploaded
resource.version_created
resource.viewed
resource.downloaded
resource.shared
resource.processing_completed
resource.processing_failed
claim.created
claim.updated
claim.reviewed
claim.contested
decision.created
decision.approved
decision.rejected
commitment.created
commitment.updated
agent.run_started
agent.resource_retrieved
agent.output_created
export.created
access.denied
Audit event fields
text
id
circle_id
actor_type: user | agent | system
actor_id
event_type
resource_type nullable
resource_id nullable
resource_version nullable
metadata_json
occurred_at
previous_hash
event_hash
event_hash = SHA256(canonical_json(event_without_event_hash) + previous_hash).

This provides tamper evidence for the application’s own audit stream. It is not a legal-grade, independently anchored ledger in the MVP.

12. UI Scope
Main routes
text
/login
/organisations/:orgSlug
/circles
/circles/new
/circles/:circleId
/circles/:circleId/context
/circles/:circleId/claims
/circles/:circleId/decisions
/circles/:circleId/commitments
/circles/:circleId/people
/circles/:circleId/history
/circles/:circleId/export
Circle overview (“Now”)
Show only:

Mission/purpose and status.

Countdown to deadline/expiry.

Next decision required.

Active commitments and overdue items.

Material blockers/risks from users or Circle Steward.

Recent approvals.

Latest derived brief.

Context view
Upload/dropzone.

Grid/list grouped by media type.

Filters: source status, integrity, review status, media type, contributor, date.

Preview pane.

Version history.

Metadata and access panel.

“Used by” graph/list: claims, decisions, commitments, agent outputs.

Claim view
Claim statement and status.

Author/agent details.

Evidence citations with deep links to page/cell/timestamp.

Review/comments.

Confidence and uncertainty display.

Related decision/commitment.

Decision view
Pending decisions first.

Exact subject version.

Evidence and claims supporting it.

Assigned approver.

Approve/reject/comment actions.

Immutable resolution history.

People and agents view
Participants, organisation, role, invite status, expiry.

Agent mandate and allowed actions.

“What this agent can access” list.

No generic agent-creation UI in MVP.

History view
Chronological audit events.

Filters by actor, event type, resource.

Human-readable event cards with raw JSON expandable.

13. Technical Architecture
Recommended stack
Backend: Laravel 12, PHP 8.4.

Database: PostgreSQL 16+.

Queue/cache: Redis + Laravel Horizon.

Object storage: Cloudflare R2 or S3-compatible private bucket.

Frontend: Astro with React islands, TypeScript, Tailwind CSS.

Auth: Laravel Fortify/Sanctum; email magic-link or password authentication for MVP.

API: Laravel REST API; use JSON API-like conventions; OpenAPI spec generated/maintained.

Realtime: Laravel Reverb or Pusher-compatible events for upload/processing/approval status.

Media processing: FFmpeg for video/audio metadata, thumbnails, frame extraction; queue workers.

Extraction: Apache Tika or dedicated extraction worker; OCR provider/local OCR; transcription provider abstraction.

AI provider: Provider interface supporting Claude and OpenAI. Use structured JSON output and server-side tool mediation.

Deployment: DigitalOcean + Laravel Forge; separate web, queue, and scheduled-worker processes.

Components
text
Browser
  ├── Astro frontend
  └── direct signed upload to object storage

Laravel API
  ├── Authentication / organisation / Circle policy checks
  ├── Evidence / claims / decisions / commitments API
  ├── Signed upload/download URL generation
  ├── Audit event writer
  ├── Agent run coordinator
  └── Export generator

PostgreSQL
  ├── application entities
  ├── policy/resource metadata
  ├── extraction metadata
  └── audit event chain

Redis + Horizon
  ├── file processing jobs
  ├── OCR/transcription jobs
  ├── agent runs
  ├── stale-resource checks
  └── exports

Private object storage
  ├── immutable originals
  ├── previews
  ├── derived artifacts
  └── exports
14. Database Tables
Implement migrations for at least the following.

text
organisations
users
organisation_memberships
circles
circle_memberships
role_grants
resources
evidence_items
evidence_versions
derived_artifacts
resource_access_overrides
claims
claim_citations
claim_reviews
decisions
decision_approvals
commitments
commitment_updates
agent_blueprints
agent_instances
agent_runs
agent_resource_accesses
audit_events
exports
invitations
Key table notes
resources
Polymorphic base registry for any Circle resource.

text
id
circle_id
resource_type
name
created_by_type
created_by_id
status
created_at
updated_at
evidence_items
text
id
resource_id
origin_status
integrity_status
review_status
classification: public | internal | confidential | restricted
source_label nullable
source_url nullable
uploader_user_id nullable
expires_at nullable
superseded_by_id nullable
evidence_versions
text
id
evidence_item_id
version_number
storage_key
original_filename
mime_type
byte_size
sha256
metadata_json
extracted_text_status
preview_status
transcript_status
created_by_user_id
supersedes_version_id nullable
created_at
derived_artifacts
text
id
circle_id
parent_resource_type
parent_resource_id
artifact_type: ocr | transcript | thumbnail | frame | extraction | agent_summary | requirements_matrix
content_json nullable
storage_key nullable
model_provider nullable
model_name nullable
prompt_version nullable
agent_run_id nullable
source_manifest_json
status
created_at
15. API Endpoints
Use authenticated JSON endpoints.

Circles
text
POST   /api/circles
GET    /api/circles
GET    /api/circles/{circle}
PATCH  /api/circles/{circle}
POST   /api/circles/{circle}/close
POST   /api/circles/{circle}/archive
Membership
text
GET    /api/circles/{circle}/members
POST   /api/circles/{circle}/invitations
POST   /api/invitations/{token}/accept
PATCH  /api/circles/{circle}/members/{membership}
DELETE /api/circles/{circle}/members/{membership}
Evidence/media
text
POST   /api/circles/{circle}/uploads/sign
POST   /api/circles/{circle}/evidence
GET    /api/circles/{circle}/evidence
GET    /api/evidence/{evidenceItem}
GET    /api/evidence/{evidenceItem}/versions
POST   /api/evidence/{evidenceItem}/versions
PATCH  /api/evidence/{evidenceItem}/access
POST   /api/evidence/{evidenceItem}/review
POST   /api/evidence/{evidenceItem}/mark-stale
GET    /api/evidence-versions/{version}/download-url
Claims
text
POST   /api/circles/{circle}/claims
GET    /api/circles/{circle}/claims
GET    /api/claims/{claim}
PATCH  /api/claims/{claim}
POST   /api/claims/{claim}/citations
POST   /api/claims/{claim}/review
POST   /api/claims/{claim}/contest
Decisions and commitments
text
POST   /api/circles/{circle}/decisions
GET    /api/circles/{circle}/decisions
POST   /api/decisions/{decision}/approve
POST   /api/decisions/{decision}/reject
POST   /api/circles/{circle}/commitments
PATCH  /api/commitments/{commitment}
Agent and history
text
POST   /api/circles/{circle}/agent-runs/steward-brief
GET    /api/circles/{circle}/agent-runs
GET    /api/circles/{circle}/history
POST   /api/circles/{circle}/exports
16. Acceptance Criteria
Circle
Owner can create an active Circle with purpose and expiry date.

Owner can invite an external contributor by email.

External contributor sees only the Circle they were invited to.

Owner can remove the external contributor immediately.

Closing a Circle blocks new access and agent runs.

Evidence
Contributor can upload PDF, DOCX, XLSX, JPG/PNG, MP4/MOV, and MP3/WAV.

System stores original file, MIME type, byte size, SHA-256, uploader, timestamps, and version number.

PDF text can be searched and cited by page.

Image gets preview and metadata display.

Video/audio gets transcript with timestamps when extraction succeeds.

Re-uploading a replacement creates a new version and marks previous version superseded.

Users can see who uploaded each version and whether it is intact/reviewed/stale.

Claims and approvals
Contributor can create a claim citing a document page, spreadsheet range, image, or video/audio timestamp.

Reviewer can mark claim reviewed or contested with a comment.

Approver can approve/reject a decision tied to an exact version.

A new version requires a new approval.

Agent
Circle Steward can only retrieve resources marked agent_read=true in its own Circle.

Agent produces a sourced brief with citations and a visible derived label.

Every retrieved resource and generated output is audit logged.

Agent cannot invoke a write-capable integration or alter permissions.

Audit/closure
All required event types appear in Circle History.

Audit event chain hash validates sequentially.

Owner can generate an export containing Circle metadata, participants, decisions, commitments, evidence manifest, claims, citations, and audit events.

Closing Circle revokes access to external participants and agents.

17. Build Order
Milestone 1 — Foundation
Laravel project, auth, organisations, Circle CRUD.

Circle roles, invite flow, policy middleware.

Audit-event service and hash chain.

Milestone 2 — Evidence vault
Object storage + signed uploads.

Evidence items, immutable versions, hashing, previews.

Context list/detail UI.

Milestone 3 — Interpretation
Document extraction and PDF page indexing.

Image metadata/thumbnails/OCR.

Audio/video metadata and transcription pipeline.

Derived artifact model and UI.

Milestone 4 — Decisions
Claims, citations, reviews, decisions, approvals, commitments.

Circle overview showing blockers and pending decisions.

Milestone 5 — Circle Steward
Strict agent policy guard.

Retrieval manifest and structured summarisation.

Sourced weekly brief and draft decision requests.

Milestone 6 — Closure/export
Closure workflow, access revocation, exportable mission packet.

Pilot readiness hardening, error handling, observability, backups.

18. First Demo Script
GM creates Rail Access Package — Bid Review Circle.

GM invites commercial, technical, and logistics leads.

Commercial lead uploads RFQ PDF and customer correspondence.

Technical lead uploads drawings, geotechnical report, photos, and site video.

Logistics lead uploads stock/transport spreadsheet.

System hashes originals, extracts text/transcripts, and displays provenance/status.

Circle Steward runs a brief and highlights a conflicting crane-load assumption with citations.

It drafts a decision: Confirm crane load basis assigned to technical lead.

Technical lead approves the decision against the cited drawing/load-schedule versions.

Commercial lead creates a quote-approval decision tied to proposal v2.

GM approves v2.

GM closes the Circle and exports the evidence/decision packet.

19. Definition of Done
The MVP is done when JWA can run a real bid-review Circle and answer these questions without searching email or disconnected folders:

What is this mission and when does it end?

Who is involved, and what can each person do?

Which files, photos, videos, and documents are relevant/current?

Who supplied each item, when, and has it changed?

What claims are evidence-backed versus derived/unreviewed?

What is blocked, who owns it, and what decision is pending?

Which exact version was approved, by whom, and using what evidence?

What did the agent access and why?

What happens to access and records when the mission closes?

20. Amendment — Cross-Company Workflow and Executing Agents
Why this changes
The MVP was specified around one organisation running a bid review with guests. The target is now contractor-to-company and company-to-company projects, where no party is the centre and none of them report to each other. Two consequences follow that the original sections cannot express: responsibility belongs to an organisation before it belongs to a person, and the workflow — not the evidence record — is what people arrive for.

20.1 Parties replace "external"
`circles.organisation_id` is now the convener: the party that opened the Circle, holds the closure right, and receives the packet. It no longer implies everyone else is a guest.

Each participating organisation gets a `circle_parties` row with a commercial position (convener, principal, contractor, subcontractor, advisor, observer). A party may be named before its organisation exists on the platform, because counterparties are written into a plan long before anyone from them signs in; `organisation_id` binds when the first member accepts an invitation.

`circle_memberships.is_external` survives as the stored fast path the gate reads, but where a party is set the party is authoritative: external now means "not the convener".

Permissions still come from each member's CircleRole. A party role is a commercial position, not a permission set — its job is to make the export packet read correctly. "Contractor" is worth more in a record than "external".

20.2 The goal tree is the spine
`goals` is self-referencing: a sub-goal is a goal with a parent, so it carries its own acceptance condition and its own responsible party — which is what happens when work is subcontracted a level down. Depth is capped in the service layer rather than the schema.

Claims, decisions and commitments each gained a nullable `goal_id`. All nullable: a Circle that never builds a tree behaves exactly as before.

Two things the original progress field could not do:

`responsible_party_id` names the organisation answerable for a node. People leave projects; the company still owes the deliverable, and reassigning a person must not silently move liability between parties.

`goal_schedule_changes` records every movement of a due date with a reason, who moved it, and — where the move affects another party — whether that party agreed. Its own table rather than an audit-log query, because a slipped date is the single most common thing inter-company projects end up arguing about. A date that can move silently carries no weight.

Reported progress stays distinct from derived progress. A parent ignores its own stored figure and averages its children: a parent claiming 80% over sub-goals at 20% is the exact failure a status field exists to prevent.

20.3 Conversation, attached to objects
The non-goal in section 3 ("native chat, channels") is narrowed rather than reversed. Circle already had comments, welded to levers: `ClaimReview.comment`, `DecisionApproval.comment` and `CommitmentUpdate.note` each let a person speak only while changing a state. That is why the real conversation left for email — there was no way to ask a question without committing to an action.

`comment_threads` hang off a goal, claim, decision, commitment or evidence item. A comment may carry an action (`action_type`/`action_id`) or carry nothing. The existing lever-notes become comments of the first kind, so an object's history reads as one conversation rather than two parallel records.

Still not built: a Circle-wide channel. Once a general room exists the substance migrates into it and the structured record decays into something someone updates afterwards out of duty. Object-scoped threads have no "general" to drift into.

Two rules that are load-bearing rather than cosmetic:

Visibility defaults to `party`, the opposite default from evidence. Evidence is what the Circle exists to pool; a contractor working out its own position in front of the client is how a Circle stops being used at all. A party-scoped thread is invisible to everyone outside that party, the convener included.

Export inclusion is per comment. A comment that carried a state change is part of the decision record and is always in the packet. Plain discussion is out unless someone marks it for the record — if every aside were discoverable in a dispute, people would self-censor into uselessness and the feature would be worth nothing.

Mentions are the only push signal in the product. Everything else — a deadline, an assignment, an agent waiting on approval — is state someone has to remember to go and look at, which is the same as no signal at all.

20.4 Agents are authored, and they execute
This reverses section 2 ("one strictly read-only agent") and two non-goals in section 3 ("general agent builder", "autonomous external communication or source-system writes").

It does not discard the verification machinery — it is what makes execution sellable. Two companies who do not fully trust each other will not let the other side's software touch a shared project unless every action is attributable, bounded by a declared mandate, and refusable. Claims, decisions and the audit chain stop being paperwork at the moment agents start acting.

The rule the schema enforces: an agent proposes, and a side effect needs a human holding the authority of the party that bears it.

`agent_blueprints` gained an owning organisation, an optional Circle scope, an author, and `execution_mode`: `read_only`, `propose`, or `execute`. The mode is an outer bound checked in AccessGate before the declared action list, so an authored blueprint asking for more than its mode allows gets nothing, and dropping an agent to `read_only` is one column that stops everything it could do without editing its tools. The Steward is `propose` and stays there.

`agent_tools` declares the commands an agent may run, classified by `side_effect` — none, circle_write, external_read, external_write, financial. Classification is by consequence rather than by what the tool is called, so the approval rules hold for tools nobody has written yet. A blueprint may raise a tool's approval bar and never lower it: an author cannot mark their own payment tool as needing nobody.

`agent_actions` is the execution ledger. The row is written at `proposed`, before anything is attempted, so a refused or failed action leaves the same trail as a successful one — the same reason `agent_runs` records its retrieval manifest before the model call. `tool_key` and `side_effect` are stored flat so the ledger still reads correctly after a blueprint is edited or a tool removed. Approval reuses the decision machinery rather than inventing a second one. `on_behalf_of_party_id` carries the authority: an agent never acts for "the Circle", it acts for a party, and that party carries the consequence.

`agent_connections` lets a party bring its own agent. It runs under that party and its own credentials; Circle never holds the raw secret. `credential_ref` points at the secret store and `key_fingerprint` is what the counterparty is shown when asked to admit it — they approve a specific key, not a name anyone can type. Admission is a decision, not a setting.

What still holds from section 9: an agent has no membership row and cannot inherit one. Agent read stays opt-in per evidence item and is never inherited. Closure stops agents outright. An agent still cannot approve on behalf of a person — including its own actions.

20.5 What this amendment does not yet build
Named honestly, because the schema now implies them:

No notification transport. `comment_mentions` records who was mentioned and when they were told; nothing sends anything yet. Until it does, the goal tree's deadlines are still state nobody is pushed toward.

No executor. `agent_actions` records proposals and approvals correctly; the runner that takes an approved action and performs it, honouring retries and the idempotency key, is not written.

No party-scoped resource permissions. "Contributors see only their branch of the tree" is a real design question, not a column — today permissions remain Circle-wide plus per-resource overrides.

No handoff. Reassigning everything one departing person owns is still a hunt rather than one action.

Closure and export do not yet read the goal tree, the comment threads, or the action ledger. The packet is the product's endpoint and it currently ends at the MVP's objects.

No executor UI. The action queue approves and refuses; nothing runs an approved action yet, so `executed` is reachable only from a test.

No notification transport, still. Mentions are recorded and surfaced in-app on Now and in the inbox, but nothing leaves the browser — no email, no push.

Closure and export still end at the MVP's objects: the packet does not yet read the goal tree, the threads, or the action ledger.
