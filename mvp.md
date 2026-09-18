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

Originally: no Circle-wide channel, on the grounds that once a general room exists the substance migrates into it and the structured record decays into something someone updates afterwards out of duty. Section 22 reverses that, and the reversal is recorded there rather than edited into this paragraph, because the reasoning above was right about what a general room does and wrong only about what refusing one achieves.

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

No notification transport. `comment_mentions` records who was mentioned and when they were told; mentions are surfaced in-app on Now and in the inbox, but nothing leaves the browser — no email, no push. Until it does, the goal tree's deadlines are still state nobody is pushed toward.

No handoff. Reassigning everything one departing person owns is still a hunt rather than one action.

Closure and export still end at the MVP's objects: the packet does not yet read the goal tree, the threads, or the action ledger. The packet is the product's endpoint and it stops short of the things the amendment added.

No transport for a remotely-hosted party agent. `agent_connections` records the endpoint, the auth mode and the key fingerprint, and admission binds a blueprint and gives it an instance — but nothing signs a webhook or speaks MCP to an agent running on the other party's infrastructure. A party's agent runs today because its *mandate* is authored here and executed by Circle's own model calls under that party's authority, which is the useful half; reaching out to software they host is not built.

Only `circle_write` tools have implementations. `ToolRegistry` carries five — comment, create goal, report progress, create commitment, flag evidence stale — all reversible and confined to one Circle. `external_read`, `external_write` and `financial` are classifications the approval rules already honour, with nothing registered under them: a blueprint may declare such a tool, and the registry refuses at execution rather than pretending it ran. Each external tool is a real integration and there is no honest way to ship a generic one.

20.7 Branches of the plan

The goal tree had one state, and anyone holding `goal.update` changed it in place. Inside one company that is fine. Across companies it is the whole problem: a contractor who believes three dates should move has no way to say so except to move them, and the client finds out afterwards. The negotiation left for email and the tree became a record of who edited last rather than of what was agreed.

A branch is a named set of *proposed* edits, and deliberately not a copy of the tree.

Copying gives you literal git — a parallel world you can look at whole — and it forks every id in it. Comments, claims, commitments and the audit chain all point at goal ids; a duplicated subtree means each of those must answer "which copy?", and merge has to decide whether a comment left on the branch belongs to the surviving node. There is no answer to that which is right more than half the time. `goal_changes` instead points at the real goal, names the field, and carries both the value it wants and the value it was looking at.

That last part is the conflict story. If main moved under an open branch, the change is reported as a conflict and both approval and merge refuse — because the alternative is that somebody signed off on a diff that no longer describes what will happen. `rebase` re-points the recorded base at the plan as it stands and clears every signature, since they were given to a different diff.

**A merge needs agreement from every party the branch touches.** Not the convener's, and not the author's: the companies who will have to do the changed thing. This is the rule `goal_schedule_changes` already encodes for a single date, generalised to a set of edits — a date that can move without the party it lands on agreeing carries no weight, and neither does a plan that can be rewritten the same way. In a Circle the client convened, the client's owner holds every permission there is and still cannot sign for the contractor. Approval reuses the decision machinery, so a merged branch lands in the packet as what it is: a decision several companies signed. `decision_approvals` gained `circle_party_id`, because "Rae approved" does not answer whether Beam Rail did.

Four things that follow, each of which would otherwise be a hole:

Editing a branch after somebody has signed clears the signatures. Carrying them across an edit would let an author add a clause after the counterparty agreed.

A refusal leaves the branch open. A refusal answers a proposal rather than ending one; closing it would teach people not to refuse.

A date moved by a branch still goes through `reschedule()` and lands in `goal_schedule_changes` with its reason. Otherwise a branch is the one route by which a deadline moves with no record of why.

A removal marks the goal abandoned rather than deleting it. Everything that cited it still resolves, and the packet can still say the work existed and was dropped — usually the interesting part.

`goal.branch` and `goal.merge` are separate permissions. A contributor may draft and propose, which is how somebody without authority still gets heard; agreeing binds a company, so it sits with the roles that can bind one — and can be delegated to a named person by §20.6's grants without moving them to that role.

Depth moved from two levels to four (`circle.goals.max_depth`). Principal → package → contractor → subcontractor is four before anybody has padded anything, and a cap that refuses it pushes the real structure into titles like "Beam Rail / bogies / weld inspection". Still a cap: below four, work belongs in a commitment.

20.6 What section 20.5 used to name and no longer does
Kept rather than deleted, because a spec that quietly stops listing a gap reads as though the gap was never there.

**The executor is written.** `AgentActionService::execute()` always took an injected handler so the ledger could be tested before side effects existed; `AgentExecutor` now resolves the handler from `ToolRegistry`, and approving an action runs it in the same request. The registry refuses a tool whose handler is more consequential than what the approver was shown, so a payment cannot be registered against something declared `circle_write` and collect a reviewer's signature. `circle:sweep-agent-actions` runs every five minutes: it expires proposals nobody answered and drains approvals given while the worker was down.

**Authored agents run.** There was one run entrypoint — the Steward — so a blueprint could be authored, given tools and instantiated, and never invoked. `AgentRunner` generalises what was `CircleSteward`'s private machinery over any instance; the Steward is now a mandate and a prompt sitting on top of it. `AuthoredPrompt` fences the customer's mandate inside a sentinel it cannot terminate, states the platform rules after it, and names evidence as data rather than instruction — necessary because a shared Circle's agent reads uploads from the counterparty. The party an action runs for is resolved from the blueprint's owning organisation and is not expressible in the output schema, so an agent cannot move liability onto a company that never agreed to carry it.

**Admission is reachable.** Connections were written at `pending` and nothing ever moved them, so `isAdmitted()` could not return true. `AgentConnectionService::admit()` writes `admitted_at` behind an owner's signature, refuses the agent's own party where a counterparty exists, and records a `Decision` naming the accepted fingerprint so the packet carries who let the agent in. Withdrawal disables the instance and leaves everything it did in the record.

**Per-user grants are reachable.** `role_grants` had been read by AccessGate since the first migration and written by nothing, so the only way to let a contributor author an agent was to make them an owner. `DelegationService` writes them under two rules: nobody can grant what they do not hold, and the convening owner cannot be denied membership control or closure.

**Party-scoped resource permissions exist.** `resource_access_overrides` gained `circle_party_id`, and the gate applies the narrowest match — named user, then their party, then the resource as a whole. A resource-wide deny plus a party allow expresses "only this company sees it"; a party deny plus a user allow expresses "nobody at that company except the one person we agreed on".

**Every party's agents are visible.** The studio scoped blueprints to the convener's organisation, so a contractor's own agents did not appear in a Circle they were a party to. It now lists the agents of every party, an agent is authored under its author's organisation rather than the convener's, and only its author may edit it — the client can refuse a contractor's agent or throw it out, but cannot quietly widen its mandate and leave the contractor carrying what it does.

21. Amendment — Open Work, Engagements and the Portable Record
Why this changes

Section 20 made a Circle span several companies. It did not answer where the counterparty comes from, on what terms they are there, or what either side carries away.

Today a party is written into a plan by someone who already knows who to write. The commercial relationship is a `party_role` string — "contractor" — with no rate, no term, no scope and no end. And everything of value a party produces inside a Circle (a met commitment, a merged branch, a counterparty's signature) is exported to a packet at closure and then ceases to exist as far as the platform is concerned.

That last one is the load-bearing gap. The MVP's founding premise is that a Circle **ends when the mission does**. For an operating environment that is right. For a place where companies engage temporary people and agents it is fatal: nothing compounds, every engagement starts from zero, and the platform holds no reason for either side to come back.

Section 21 adds one durable layer *above* the Circle and three objects inside it. It does not reverse §20; it is what §20 implies once the counterparty is not already known.

The shape being borrowed is deliberate. A code host is four separable things: a durable artifact you return to and fork, a proposal-and-review protocol over it, a work history that follows the worker rather than the employer, and automation as a principal acting inside the same review protocol. §20.7 built the second of those and built it stronger than the original — a merge needs the signature of every party the diff lands on, not a maintainer's. §20.4 built the fourth. §21 builds the first and the third, and connects them to money and time.

21.1 Open work is a posting; applying is a branch

`goals.responsible_party_id` names the company answerable for a node. A node with nobody named is not a defect in the plan — it is work somebody has to be found for, and it is the only thing in the product that a person outside the Circle has any business seeing.

`work_openings` is that posting. It points at a goal where one exists, and stands alone where the plan has not been drawn yet — a Circle still being scoped can post before it has a tree. The opening carries what the goal cannot: `principal_kind` (`human`, `agent`, `either`), a fee basis and amount, a term, a closing date, and a visibility.

Applying is a `goal_branch`. This is the point of the design rather than a convenience: a proposal to do the work *is* a proposal to edit the plan — assign this goal to my company, and while we are here, these two dates need to move. §20.7's machinery then does the rest without modification. The base snapshot means an applicant who drafted against a plan that has since changed is told so rather than silently overwriting it. Editing after a signature clears the signature, which is exactly the right rule for a negotiated rate. And a refusal leaves the branch open, so a counter-offer is a narrowing rather than a resurrection.

`work_applications` carries the half a branch cannot: the offer. Fee, availability, a statement, and the principal — a user, or an agent blueprint version. The branch says what would change; the application says on what terms.

**The exception this creates, stated plainly.** §15's rule is that nothing is reachable except through a Circle the caller belongs to. An opening breaks it, and is the only thing that does. The break is bounded in the schema rather than in a controller: an opening exposes its own columns and the *title* of its goal, and nothing else — no tree, no evidence, no parties beyond the posting one, no threads. An applicant sees a job, not a project.

**Admission is a separate act from application.** Applying grants nothing. `shortlist()` admits the applicant's organisation as a party and issues a membership scoped to the opening's subtree — an owner's decision, audited, and refusable. Only then can the applicant open the branch that would assign them the work. The sequence is: apply outside, be shortlisted in, propose, both parties sign, engaged. A company that receives forty applications exposes its Circle to none of them.

**Awarding is a merge.** `award()` merges the assignment branch under §20.7's unchanged rule, which means the posting party and the applicant's party both signed. It then writes the engagement. There is no separate "accept" that could land an assignment nobody agreed to.

21.2 An engagement is the temp contract, and it bounds the gate

`engagements` sits between a party and the work: an engaging party, a contractor party, a principal (a user or an agent instance), a scope, a fee basis, a term, and a status.

Fee basis is `fixed`, `hourly`, `daily`, `per_deliverable` or `per_action`. The last exists because an agent's unit of work is an approved action and `agent_actions` is already the meter — §20.4 wrote the ledger before anybody thought of billing from it, and it turns out to be the right shape.

Two things make this more than a record.

**Scope and term are enforced in AccessGate, not described in a contract.** A membership issued by an engagement carries `engagement_id`, and the gate intersects that member's permissions with the engagement's state: an engagement that has not started, has ended, or has been suspended or terminated denies every write; and where the engagement names a scope goal, writes are confined to that subtree. "Temp" is a property of the authorisation decision or it is marketing. The check runs as (7), after everything in §10, because an engagement can only ever narrow — never widen — what the role already permits.

**Ending is a state with a reason, not a deletion.** `completed`, `terminated` and `expired` are different facts about the same person leaving, and the record must be able to tell them apart in a year. Termination carries a reason and the party that terminated. Nothing the principal did is removed; §20.7's rule that a removal abandons rather than deletes applies to people too.

Agreement reuses the decision machinery. An engagement is a thing two companies signed, so it lands in the packet as one.

Deliverables are `commitments` with an `engagement_id`. No new object: a commitment already has an owner party, an acceptance condition, a due date and an update trail, which is a deliverable in every respect that matters. Accepting the commitment is what closes the fee obligation, and is the hook a payment tool would hang from.

**Money is not moved.** `financial` remains a `side_effect` classification with nothing registered under it (§20.5). An engagement records what is owed and what was accepted; escrow, invoicing and settlement are not built, and the schema says so by holding an amount and a currency and no transaction anywhere.

21.3 The record is portable, counterparty-attested, and hash-chained on its own

Everything a principal does inside a Circle is currently scoped to `circle_id` and ends at the packet. `work_records` is the durable layer: one row per principal per engagement, written when the engagement ends and when the Circle closes.

It is compiled from the audit chain rather than reported: commitments owned and how many were met, met late, or never; branches proposed, merged and refused; agent actions proposed, approved and refused; deliverables accepted; the outcome. The interesting numbers are the unflattering ones — an agent that keeps proposing actions its counterparty refuses is the thing a hirer most needs to see, and it is already in the ledger.

Three properties, each of which is the reason the record is worth anything:

**It is attested by the counterparty, not by its subject.** The engaging party signs; the contractor cannot write their own. A signature from a company that was paying for the work and is not the worker's employer is a materially different claim from a self-declared skill list, and it is the one thing a cold-start marketplace cannot manufacture.

**It has its own hash chain.** `record_hash = SHA256(canonical_json(record) + previous_hash)`, chained per principal rather than per Circle — the same construction as §11 and deliberately a *separate* chain, so a record verifies without the Circle it came from, which may be closed, exported and gone. The same honesty applies as in §11: this is tamper evidence for the application's own stream, not an independently anchored ledger.

**Publication is per record and the numbers are not editable.** A principal chooses which records are visible to whom; they cannot choose what a visible record says. Hiding a bad engagement is allowed, and a gap in a chain of otherwise-published records is itself legible — which is the correct trade against the alternative, where nobody ever agrees to be measured.

Agents get records on the same terms as people. `principal_type` is `user`, `organisation` or `agent_blueprint`. An agent's verified execution history across companies that are not its author's is the primitive nothing else in the market currently has, and it falls out of the ledger §20.4 already writes.

21.4 A work package is the durable artifact

The Circle is ephemeral by design and must stay that way; making it forkable would fight §1. The thing worth keeping is the *plan* — the shape of a bid review, a mobilisation, a rail access package — and that shape is currently redrawn by hand every time, or copied by whoever remembers the last one.

`work_packages` is a goal tree with the Circle removed: nodes with titles, descriptions, acceptance conditions and *offsets* rather than dates, so instantiating one against a start date produces a schedule. `capture()` takes it from a Circle; `instantiate()` writes it into one; `fork()` copies it to another organisation and records `forked_from_id`, so where a shape came from is answerable.

Deliberately not carried across: evidence, claims, decisions, threads, parties and every id. A package is a form, not a copy of somebody's project — and a template that dragged the last client's structure into the next one is a confidentiality incident, not a feature.

21.5 Discovery is a network before it is a market

A public board fights the gate that the rest of the product is built on, and a marketplace with a public board and nothing else is a cold-start problem with a UI.

Visibility on an opening is `party`, `circle`, `network` or `public`, and defaults to **network** — the organisations you have already completed an engagement with. `organisation_relationships` records that, derived from engagements rather than declared, so the network is a fact about work done rather than a list somebody curated.

This is the point at which the product becomes two-sided, and it is sequenced last among the visibilities for that reason: `network` is worth something only once §21.3 has given the principals on the other side a record worth reading. A public tier exists and is opt-in per opening.

21.6 Blueprint versions, and hiring an agent

§20.4 made agents authorable and executable within one organisation's Circles. Hiring one across companies needs two things it does not have.

**A version you can bind to.** `agent_blueprint_versions` is an immutable snapshot of a mandate, its declared tools and its prompt version. An engagement names a version, not a blueprint, so an author cannot widen the mandate of an agent a counterparty is already paying for — which is §20.6's "only its author may edit it" carried into a commercial relationship where editing it silently would be worth money.

**A meter.** `engagement_meter_entries` records one row per billable unit against an engagement, written when an action is *approved and executed* rather than when it is proposed — an agent does not get paid for asking. For `per_action` engagements this is written from the existing ledger; for the others the meter is empty and the fee is the fee.

The liability rule from §20.4 is untouched and is what makes this coherent: an agent acts for the organisation that authored it. Hiring one does not move that. The engagement names the author's organisation as the contractor party, so an agent's mistakes land on the company that wrote it and are paid for by the company that hired it — which is the same arrangement as hiring any other contractor, and the reason it needs no new liability story.

21.7 What this amendment does not build

Named for the same reason §20.5 named its own gaps.

**No money movement.** Amounts, currencies, bases and accepted deliverables — no invoice, no escrow, no settlement, no payment tool registered under the `financial` classification. Every fee in this section is a number two parties agreed on and a record of what was delivered against it.

**No identity verification.** An organisation is whatever `circle:provision-org` was told it is. A record attested by a counterparty is only as good as the counterparty being who they say, and nothing here checks that.

**No search or matching.** Discovery answers "which openings may this person see". It does not rank them, match them to a record, or notify anybody — and §20.5's missing notification transport bites hardest here, because an opening nobody is told about is an opening nobody applies to.

**No cross-instance federation.** A record is portable within one deployment. Carrying it to another Circle installation would need the chain to verify against a key the receiving side trusts, and no key is published.

**The remote agent transport is still absent.** §20.5 said nothing signs a webhook or speaks MCP to a party-hosted agent, and that is still true. A blueprint version can be hired and metered; it runs under Circle's own model calls with its author's authority. Renting a mandate is real; renting software somebody else operates is not built.

22. Amendment — The general room, and filing what lands in it

§20.3 refused a Circle-wide channel. The reasoning was that once a general room exists the substance migrates into it and the structured record decays into something somebody updates afterwards out of duty, and that object-scoped threads have no "general" to drift into.

That is right about what a general room does. It is wrong about what refusing one achieves, and the error is visible the first time somebody actually uses the product rather than demonstrates it.

Work does not begin with an object. It begins with a question — are we bidding this, can you send last year's scope, who is covering the rail possession — asked before there is a goal, a claim or a decision for it to hang on. A product with nowhere to ask that does not prevent the question being asked. It sends the question to email, and the answer follows it there, and so does everything the two of them then agree. The structured record §20.3 was protecting is lost anyway, to a place with no visibility rules, no export semantics and no audit chain at all. The non-goal did not buy the discipline it was purchased for; it bought the appearance of it.

So the room exists, and the original objection is answered rather than overruled.

22.1 A thread may hang off the Circle

`comment_threads.subject_type` accepts `circle`, with the Circle as its own `subject_id`. That is not a schema concession — it means every query, every visibility rule and every export path that already reads those two columns keeps working untouched, and a general thread is not a second kind of object with a second set of rules.

Everything §20.3 made load-bearing is unchanged. Visibility still defaults to `party`, so the general room is not a place where a contractor accidentally works out its position in front of the client. Export inclusion is still per comment. Mentions still resolve against the thread's actual readership.

22.2 A general thread must be named

`comment_threads.title`, required for a circle-scoped thread and null for every other kind, because an object-scoped thread is named by the object it hangs on and two sources of truth for what a conversation is called is how they come to disagree.

This is the first half of the answer. The room §20.3 feared is one undifferentiated log; a list of named topics is a table of contents. The cost of requiring a subject line is one field, and it is the difference between a record somebody can scan and a transcript nobody reads.

22.3 Drift is made recoverable rather than prevented

`attachTo()` moves a general thread onto a goal, claim, decision, commitment or evidence item, recording `attached_at` and `attached_by_user_id`.

This is the load-bearing half, and it is the actual answer to §20.3. Substance *will* migrate into the general room — that prediction was correct and no interface can stop it. What an interface can do is make the migration reversible. The whole conversation moves, with every comment, every mention and every on-record marking intact, because none of that ever lived on the subject. "The record decays" stops being a property of the design and becomes a chore somebody can do in one action.

Three constraints, each for a reason:

One direction. A thread already about a decision is not general, and shuffling settled conversation between objects is the decay this exists to undo.

The target must be in the same Circle, checked rather than trusted, or a thread would name an object nobody who can read it is able to see.

Filing needs `CommentModerate`, not `CommentCreate`. Re-filing somebody else's conversation changes where it appears for everyone who can read it, and that is a different act from taking part in it.

The move is audited as `comment.thread_attached`, and the packet carries `attached_from_general` on any thread that arrived this way — because a decision whose conversation began as a question nobody had filed yet is a true and useful thing to know about how the decision was reached.

22.4 What this does not build

No Circle-wide channel in the chat sense: no presence, no typing indicators, no unread counts beyond the existing mention inbox, no direct messages between two people. A thread is still a thread — it is opened, replied to, resolved, and it sits in a list.

No automatic filing. Nothing guesses which goal a conversation is about. A suggestion that is wrong half the time would be worse than the control, because the control is one click and a wrong guess is a conversation filed somewhere nobody looks.
