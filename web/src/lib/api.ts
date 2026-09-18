/**
 * Typed client for the Circle API.
 *
 * The token lives in localStorage and is sent as a bearer token. Every call
 * goes through `request`, so a 401 has exactly one place to send the user back
 * to the sign-in page and a 403 surfaces the gate's own message rather than a
 * generic "forbidden".
 */

const BASE = import.meta.env.PUBLIC_API_URL ?? "http://localhost:8000/api";
const TOKEN_KEY = "circle.token";

export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    readonly payload?: unknown,
  ) {
    super(message);
  }
}

export function getToken(): string | null {
  if (typeof localStorage === "undefined") return null;
  return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token);
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY);
}

async function request<T>(
  method: string,
  path: string,
  body?: unknown,
): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json" };
  const token = getToken();

  if (token) headers.Authorization = `Bearer ${token}`;
  if (body !== undefined) headers["Content-Type"] = "application/json";

  const res = await fetch(BASE + path, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  if (res.status === 401) {
    clearToken();
    if (typeof window !== "undefined" && !location.pathname.startsWith("/login")) {
      location.href = "/login";
    }
    throw new ApiError(401, "Your session has ended. Please sign in again.");
  }

  const payload = res.status === 204 ? null : await res.json().catch(() => null);

  if (!res.ok) {
    // The API's 403 message is the AccessGate's own explanation of *why*.
    const message =
      (payload as { message?: string } | null)?.message ??
      `Request failed (${res.status})`;
    throw new ApiError(res.status, message, payload);
  }

  return payload as T;
}

export const api = {
  get: <T>(path: string) => request<T>("GET", path),
  post: <T>(path: string, body?: unknown) => request<T>("POST", path, body ?? {}),
  patch: <T>(path: string, body?: unknown) => request<T>("PATCH", path, body ?? {}),
  del: <T>(path: string) => request<T>("DELETE", path),
};

// ------------------------------------------------------------------- types

export type OriginStatus =
  | "verified_source" | "authenticated_upload" | "unverified_upload" | "attested";
export type IntegrityStatus = "intact" | "superseded" | "changed" | "unknown";
export type ReviewStatus =
  | "unreviewed" | "reviewed" | "approved" | "derived" | "contested" | "stale";
export type ProcessingStatus = "pending" | "processing" | "ready" | "failed" | "skipped";

export interface Circle {
  id: string;
  name: string;
  purpose: string;
  status: "draft" | "active" | "closing" | "archived";
  /** Whole percent, stated by the Circle's owner — not derived. */
  progress: number;
  progress_set_at: string | null;
  owner: { id: string; name: string | null };
  starts_at: string | null;
  expires_at: string | null;
  closed_at: string | null;
  is_closed: boolean;
  is_expired: boolean;
  created_at: string;
  my_role: string | null;
  my_access: {
    is_external: boolean;
    /** The company the viewer sits in, where the Circle uses parties. */
    party: { id: string; label: string } | null;
    /** Role defaults plus any per-user grants, minus any explicit denials. */
    permissions: string[];
  } | null;
}

export interface EvidenceVersion {
  id: string;
  version_number: number;
  original_filename: string;
  mime_type: string | null;
  byte_size: number | null;
  sha256: string | null;
  lane: string;
  integrity_status: IntegrityStatus;
  processing_status: ProcessingStatus;
  processing_error: string | null;
  extracted_text_status: ProcessingStatus;
  preview_status: ProcessingStatus;
  transcript_status: ProcessingStatus;
  metadata: Record<string, any> | null;
  created_by: { id: string | null; name: string | null };
  supersedes_version_id: string | null;
  created_at: string;
}

export interface EvidenceItem {
  id: string;
  resource_id: string;
  name: string;
  origin_status: OriginStatus;
  integrity_status: IntegrityStatus;
  review_status: ReviewStatus;
  classification: string;
  /**
   * The party this item is confined to, or null for the whole Circle.
   * Null is the default: evidence is what a Circle exists to pool.
   */
  restricted_to_party: { id: string; label: string | null } | null;
  agent_read: boolean;
  downloadable: boolean;
  source_label: string | null;
  source_url: string | null;
  uploader: { id: string | null; name: string | null };
  expires_at: string | null;
  stale_at: string | null;
  created_at: string;
  version_count: number;
  current_version: EvidenceVersion | null;
  versions?: EvidenceVersion[];
  used_by?: {
    claims: Array<{
      claim_id: string; statement: string | null; status: string;
      evidence_version_id: string; locator: Record<string, any> | null;
    }>;
    decisions: Array<{
      decision_id: string; title: string; status: string; subject_version: string | null;
    }>;
  };
}

/**
 * An evidence item as a job lists it.
 *
 * The vault row, plus who filed it against this piece of work. The second fact
 * is not the first: "Sam attached this on the 14th" and "Sam uploaded it in
 * March" are different acts, and on a job that has pulled in a document from
 * elsewhere in the Circle the filing is what explains why it is on the screen.
 */
export interface FiledEvidence extends EvidenceItem {
  filed: { by: string | null; name: string | null; at: string | null };
}

export interface Citation {
  id: string;
  evidence_version_id: string;
  citation_type: string;
  locator: Record<string, any> | null;
  excerpt: string | null;
  evidence: {
    evidence_item_id: string;
    name: string | null;
    filename: string;
    version_number: number;
    integrity_status: IntegrityStatus;
  } | null;
}

/**
 * The node of the plan a record is filed against.
 *
 * Null is a real answer, not a missing one: a claim, decision or commitment may
 * be about the mission at large rather than one piece of it.
 */
export interface GoalRef {
  id: string;
  title: string | null;
}

export interface Claim {
  id: string;
  statement: string;
  goal: GoalRef | null;
  claim_type: string;
  status: string;
  confidence: number | null;
  author_type: "user" | "agent";
  author: Record<string, any>;
  derived: boolean;
  derived_label: string | null;
  created_at: string;
  citations: Citation[];
  reviews: Array<{ reviewer: string | null; outcome: string; comment: string | null; at: string }>;
}

export interface Decision {
  id: string;
  title: string;
  goal: GoalRef | null;
  description: string | null;
  status: string;
  created_by: { id: string | null; name: string | null };
  approver: { id: string | null; name: string | null };
  subject: { type: string | null; id: string | null; version: string | null };
  expires_at: string | null;
  resolved_at: string | null;
  resolution_comment: string | null;
  is_expired: boolean;
  agent_run_id: string | null;
  derived: boolean;
  created_at: string;
  approval_history: Array<{
    actor: string | null; outcome: string; subject_version: string | null;
    comment: string | null; occurred_at: string;
  }>;
}

export interface Commitment {
  id: string;
  title: string;
  goal: GoalRef | null;
  description: string | null;
  acceptance_condition: string | null;
  status: string;
  owner: { id: string | null; name: string | null };
  due_at: string | null;
  completed_at: string | null;
  is_overdue: boolean;
  created_by_type: string;
  derived: boolean;
  updates: Array<{ from: string | null; to: string; note: string | null; at: string }>;
}

export interface AuditEventRow {
  sequence: number;
  id: string;
  event_type: string;
  actor_type: "user" | "agent" | "system";
  actor_id: string | null;
  resource_type: string | null;
  resource_id: string | null;
  resource_version: string | null;
  occurred_at: string;
  summary: string;
  metadata: Record<string, any> | null;
  previous_hash: string | null;
  event_hash: string;
}

export interface ChainStatus {
  valid: boolean;
  events_checked: number;
  broken_at_event_id: string | null;
  reason: string | null;
}

export interface AgentRun {
  id: string;
  run_type: string;
  status: string;
  agent_instance_id: string;
  blueprint: string | null;
  blueprint_version: string | null;
  model_provider: string | null;
  model_name: string | null;
  prompt_version: string | null;
  created_at: string;
  started_at: string | null;
  finished_at: string | null;
  tokens: { input: number | null; output: number | null };
  error: string | null;
  label: string;
  derived: boolean;
  output: {
    summary?: string;
    status?: string;
    uncertainty?: string;
    missing_evidence?: Array<{ description: string; why_it_matters?: string }>;
    potentially_stale?: Array<{ evidence_item_id: string; reason: string }>;
  } | null;
  retrieval_manifest: {
    considered: number; retrieved: number; denied: number;
    items?: Array<Record<string, any>>;
  } | null;
  resource_accesses: Array<{
    resource_id: string | null; evidence_version_id: string | null;
    permitted: boolean; reason: string | null; occurred_at: string;
  }>;
}

export interface Overview {
  circle: Circle;
  countdown: { expires_at: string | null; days_left: number | null; expired: boolean };
  next_decision: { id: string; title: string; approver: string | null; expires_at: string | null } | null;
  pending_decisions: Array<{ id: string; title: string; approver: string | null; subject_version: string | null }>;
  commitments: Array<{
    id: string; title: string; owner: string | null; status: string;
    due_at: string | null; overdue: boolean;
  }>;
  overdue_count: number;
  recent_approvals: Array<{
    id: string; title: string; status: string; approver: string | null; resolved_at: string | null;
  }>;
  latest_brief: {
    id: string; created_at: string; model: string | null; agent_run_id: string | null;
    content: Record<string, any> | null; derived: boolean;
  } | null;
}

export interface Member {
  membership_id: string;
  user: { id: string; name: string | null; email: string | null };
  circle_role: string;
  /** The company this person sits in, where the Circle uses parties. */
  party: { id: string; label: string } | null;
  is_external: boolean;
  invite_status: string;
  is_active: boolean;
  expires_at: string | null;
  revoked_at: string | null;
  permissions: string[];
}

export interface AgentSummaryRow {
  agent_instance_id: string;
  name: string;
  blueprint: string;
  version: string;
  status: string;
  mandate: string;
  allowed_actions: string[];
  prohibited_actions: string[];
  can_access: Array<{ evidence_item_id: string; name: string }>;
}

// ------------------------------------------------- workflow, talk, agents

export type GoalStatus =
  | "draft" | "active" | "blocked" | "in_review" | "met" | "abandoned";

export interface Party {
  id: string;
  label: string;
  display_name: string;
  organisation_id: string | null;
  /** False while the party is named but its organisation is not on the platform. */
  is_bound: boolean;
  party_role:
    | "convener" | "principal" | "contractor"
    | "subcontractor" | "advisor" | "observer";
  status: "invited" | "active" | "suspended" | "withdrawn";
  is_convener: boolean;
  is_active: boolean;
  external_reference: string | null;
  member_count: number;
  joined_at: string | null;
}

export interface ScheduleChange {
  id: string;
  from_due_at: string | null;
  to_due_at: string | null;
  days_moved: number | null;
  reason: string | null;
  changed_by: string | null;
  requires_party: string | null;
  awaiting_agreement: boolean;
  agreed_at: string | null;
  created_at: string | null;
}

export interface Goal {
  /**
   * Null only for a node a branch proposes adding: it does not exist yet, so
   * there is nothing to link to or comment on. Typed honestly rather than as a
   * placeholder id, which would invite the UI to route somewhere that 404s.
   */
  id: string | null;
  parent_goal_id: string | null;
  title: string;
  description: string | null;
  status: GoalStatus;
  owner: { id: string; name: string | null } | null;
  responsible_party: { id: string; label: string; role: string } | null;
  acceptance_condition: string | null;
  accepted_by: string | null;
  accepted_at: string | null;
  starts_at: string | null;
  due_at: string | null;
  /** What to show. Averaged from children when this node has any. */
  progress: number;
  progress_reported: number;
  progress_is_derived: boolean;
  is_overdue: boolean;
  position: number;
  /** How deep this node sits. Roots are 0. Supplied so the tree indents without recomputing it. */
  depth: number;
  counts: { commitments: number; decisions: number; claims: number };
  /**
   * Present only when the tree was fetched with `?branch=`. The node keeps its
   * real values; this says what the branch would do to it, so a row can show a
   * before and an after rather than quietly claiming to be current.
   */
  branch?: {
    state: "added" | "changed" | "removed";
    change_id: string;
    from?: Record<string, unknown> | null;
    to?: Record<string, unknown> | null;
    reason?: string | null;
  };
  children?: Goal[];
  schedule_changes?: ScheduleChange[];
}

export type BranchStatus = "draft" | "open" | "merged" | "withdrawn";

export interface BranchConflict {
  change_id: string;
  goal_id?: string;
  kind: "moved_underneath" | "goal_gone" | "parent_gone";
  field?: string;
  message: string;
}

export interface BranchChange {
  id: string;
  change_type: "add" | "update" | "remove";
  goal_id: string | null;
  goal_title: string | null;
  temp_key: string | null;
  parent_temp_key: string | null;
  parent_goal_id: string | null;
  attributes: Record<string, any> | null;
  /** What main held when this was drafted. The other half of a before → after. */
  base: Record<string, any> | null;
  reason: string | null;
  summary: string;
  author: string | null;
  conflicts: BranchConflict[];
  created_at: string | null;
}

/** A proposed revision of the plan. Holds changes, never goals. */
export interface Branch {
  id: string;
  circle_id: string;
  name: string;
  intent: string | null;
  status: BranchStatus;
  author: string | null;
  party: string | null;
  change_count: number;
  conflicts: BranchConflict[];
  has_conflicts: boolean;
  affected_parties: Array<{ id: string; label: string }>;
  /** Parties whose agreement this still needs. Empty means it can merge. */
  awaiting: Array<{ id: string; label: string }>;
  signatures: Array<{
    party: string | null;
    person: string | null;
    outcome: string;
    comment: string | null;
    at: string | null;
  }>;
  decision_id: string | null;
  proposed_at: string | null;
  merged_at: string | null;
  merged_by: string | null;
  created_at: string | null;
  changes?: BranchChange[];
}

export type ThreadSubject =
  | "circle" | "goal" | "claim" | "decision" | "commitment" | "evidence_item";

/** Where a general discussion can be moved to. Never back to "circle". */
export type AttachableSubject = Exclude<ThreadSubject, "circle">;

export interface CommentRow {
  id: string;
  thread_id: string;
  author_type: "user" | "agent";
  author: string | null;
  author_party: string | null;
  body: string;
  for_the_record: boolean;
  /** In the export packet: either it carried an action or someone marked it. */
  on_record: boolean;
  action_type: string | null;
  mentions: string[];
  created_at: string | null;
}

export interface Thread {
  id: string;
  subject: { type: ThreadSubject; id: string };
  /** Set only on a general discussion, which has no subject object to name it. */
  title: string | null;
  is_general: boolean;
  visibility: "circle" | "party";
  party: string | null;
  status: string;
  is_resolved: boolean;
  last_activity_at: string | null;
  created_at: string | null;
  comment_count: number;
  /** Present once a general discussion has been moved onto an object. */
  attached: { at: string | null; by: string | null } | null;
  comments?: CommentRow[];
  latest?: CommentRow | null;
}

/** Someone an @mention in a given thread would actually reach. */
export interface MentionCandidate {
  id: string;
  name: string | null;
  /** What gets typed after the @ — email local parts do not collide, first names do. */
  handle: string;
  party: string | null;
}

export interface MentionRow {
  id: string;
  comment_id: string;
  thread_id: string;
  subject: { type: ThreadSubject; id: string };
  excerpt: string;
  created_at: string | null;
}

export type SideEffect =
  | "none" | "circle_write" | "external_read" | "external_write" | "financial";

export interface AgentToolRow {
  id: string;
  key: string;
  name: string;
  description: string;
  side_effect: SideEffect;
  needs_approval: boolean;
  approval_role: string | null;
  owning_party_only: boolean;
  enabled: boolean;
  /**
   * Whether a handler exists behind this key. A blueprint can declare a tool
   * nobody wrote; saying so here keeps an author from discovering it from a
   * failed action after an approver has already signed for one.
   */
  implemented: boolean;
}

/** A tool with an implementation, offered by the studio's picker. */
export interface ToolCatalogueRow {
  key: string;
  name: string;
  description: string;
  side_effect: SideEffect;
  input_schema_json: Record<string, unknown> | null;
}

export interface AgentBlueprintRow {
  id: string;
  key: string;
  name: string;
  mandate: string;
  instructions: string | null;
  is_system: boolean;
  status: string;
  execution_mode: "read_only" | "propose" | "execute";
  provider: string;
  circle_scoped: boolean;
  /** What it can do after the execution mode's ceiling is applied. */
  permissions: string[];
  declared: string[];
  prohibited: string[];
  tools: AgentToolRow[];
  instance_id: string | null;
  is_running_here: boolean;
  created_at: string | null;
}

export interface AgentConnectionRow {
  id: string;
  name: string;
  party: string | null;
  provider: string | null;
  auth_mode: string;
  status: string;
  fingerprint: string | null;
  is_admitted: boolean;
  blueprint_id: string | null;
  admitted_by: string | null;
  admitted_at: string | null;
  revoked_at: string | null;
  created_at: string | null;
  /**
   * Admission is a record, not a live integration — Circle stores the
   * endpoint and never calls it. Always false today. Do not render an
   * admitted connection as though something is out there acting.
   */
  can_be_invoked: boolean;
  invocation_note: string;
}

export type AgentActionStatus =
  | "proposed" | "awaiting_approval" | "approved" | "rejected"
  | "executing" | "executed" | "failed" | "cancelled" | "expired";

export interface AgentActionRow {
  id: string;
  agent: string | null;
  tool_key: string;
  tool_name: string;
  side_effect: SideEffect;
  status: AgentActionStatus;
  intent: string | null;
  arguments: Record<string, unknown> | null;
  result: Record<string, unknown> | null;
  error: string | null;
  on_behalf_of: string | null;
  needs_role: string | null;
  owning_party_only: boolean;
  approved_by: string | null;
  approved_at: string | null;
  rejection_reason: string | null;
  expires_at: string | null;
  is_expired: boolean;
  /** False when no handler stands behind the tool key. Approving it will fail. */
  runnable: boolean;
  executed_at: string | null;
  created_at: string | null;
}

/**
 * A per-user exception to the role vocabulary.
 *
 * Readable by anyone who can see the Circle: a permission model that half the
 * Circle cannot read is not the one actually in force.
 */
export interface GrantRow {
  id: string;
  user_id: string;
  user_name: string | null;
  permission: string;
  allow: boolean;
  reason: string | null;
  granted_by: string | null;
  granted_at: string | null;
  expires_at: string | null;
  is_active: boolean;
}

// ----------------------------------------------------------------- helpers

export function formatBytes(bytes: number | null): string {
  if (bytes === null) return "—";
  const units = ["B", "KB", "MB", "GB"];
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit++;
  }
  return `${value < 10 && unit > 0 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`;
}

export function formatDate(iso: string | null, withTime = false): string {
  if (!iso) return "—";
  const d = new Date(iso);
  const date = d.toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
  if (!withTime) return date;
  return `${date} ${d.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" })}`;
}

export function relativeDays(iso: string | null): string {
  if (!iso) return "no deadline";
  const days = Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000);
  if (days < 0) return `${Math.abs(days)} days overdue`;
  if (days === 0) return "due today";
  if (days === 1) return "1 day left";
  return `${days} days left`;
}

/** Renders a citation locator the way a person would read it aloud. */
/**
 * One hit from searching inside a Circle's evidence. It is deliberately shaped
 * like a citation: `citation_type` and `locator` post to /claims unchanged.
 */
export interface SearchHit {
  evidence_item_id: string;
  name: string;
  evidence_version_id: string;
  version_number: number;
  filename: string;
  lane: string;
  integrity_status: IntegrityStatus;
  review_status: ReviewStatus;
  citation_type: string;
  locator: Record<string, any> | null;
  /** Already rendered: "page 4", "Load Schedule!C2:D2", "00:02:13–00:02:21". */
  locator_label: string;
  /** Matched words come wrapped in markers, not markup. */
  snippet: string;
  rank: number;
  artifact_type: string;
  /** True for a transcript or an OCR reading — a model's account, not the record. */
  machine_read: boolean;
}

export const HIGHLIGHT_START = "[[HL]]";
export const HIGHLIGHT_STOP = "[[/HL]]";

/** Splits a snippet into plain and matched runs, so nothing is rendered as HTML. */
export function splitHighlights(snippet: string): Array<{ text: string; hit: boolean }> {
  return snippet
    .split(HIGHLIGHT_START)
    .flatMap((chunk, i) => {
      if (i === 0) return chunk ? [{ text: chunk, hit: false }] : [];
      const [matched, ...rest] = chunk.split(HIGHLIGHT_STOP);
      const tail = rest.join(HIGHLIGHT_STOP);
      return [
        ...(matched ? [{ text: matched, hit: true }] : []),
        ...(tail ? [{ text: tail, hit: false }] : []),
      ];
    });
}

export function stripHighlights(snippet: string): string {
  return snippet.split(HIGHLIGHT_START).join("").split(HIGHLIGHT_STOP).join("");
}

/**
 * A citation carried between views in the URL — the "cite this result" step
 * from search to the claim composer.
 *
 * It travels as one opaque parameter rather than five readable ones because it
 * is a single indivisible thing: a locator without its version cites nothing.
 */
export interface CiteIntent {
  version_id: string;
  citation_type: string;
  locator: Record<string, any> | null;
  label: string;
  /** Item name and version, for showing what is being cited before it is saved. */
  source: string;
  excerpt: string | null;
}

export function encodeCiteIntent(intent: CiteIntent): string {
  const json = JSON.stringify(intent);
  const bytes = new TextEncoder().encode(json);
  const binary = Array.from(bytes, (b) => String.fromCharCode(b)).join("");
  return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

export function decodeCiteIntent(param: string | null): CiteIntent | null {
  if (!param) return null;

  try {
    const padded = param.replace(/-/g, "+").replace(/_/g, "/");
    const binary = atob(padded + "=".repeat((4 - (padded.length % 4)) % 4));
    const bytes = Uint8Array.from(binary, (c) => c.charCodeAt(0));
    const intent = JSON.parse(new TextDecoder().decode(bytes)) as CiteIntent;
    return typeof intent?.version_id === "string" ? intent : null;
  } catch {
    // A mangled link should open an empty composer, never a broken view.
    return null;
  }
}

export function citeHref(circleId: string, hit: SearchHit): string {
  return `/circles/${circleId}/claims?cite=${encodeCiteIntent({
    version_id: hit.evidence_version_id,
    citation_type: hit.citation_type,
    locator: hit.locator,
    label: hit.locator_label,
    source: `${hit.name} (v${hit.version_number})`,
    excerpt: stripHighlights(hit.snippet),
  })}`;
}

export function describeLocator(
  type: string,
  locator: Record<string, any> | null,
): string {
  if (!locator) return "whole document";

  switch (type) {
    case "document_page":
      return `page ${locator.page}`;
    case "spreadsheet_cell":
      return [locator.sheet, locator.range].filter(Boolean).join(" · ");
    case "video_timestamp":
    case "audio_timestamp": {
      const at = (s: number) =>
        `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, "0")}`;
      return locator.end_seconds !== undefined
        ? `${at(locator.start_seconds)}–${at(locator.end_seconds)}`
        : at(locator.start_seconds);
    }
    case "text_range":
      return `characters ${locator.start_char}–${locator.end_char}`;
    case "url_snapshot":
      return locator.url ?? "captured URL";
    default:
      return "whole document";
  }
}

/*
  Open work, engagements and the portable record (spec §21).

  Three of these are addressed without a Circle, which is the exception §21.1
  argues for at length: an applicant is not in the Circle they are applying to,
  a record outlives the Circles it came from, and somebody deciding whether to
  hire an agent has no Circle in hand. Every other type in this file hangs off
  `/circles/{id}/…` and that remains the rule.
*/

export interface WorkOpening {
  id: string;
  title: string;
  brief: string | null;
  acceptance_condition: string | null;
  /** The goal's title, and deliberately nothing else about the plan. */
  work_title: string | null;
  principal_kind: "human" | "agent" | "either";
  status: "draft" | "open" | "closed" | "filled" | "withdrawn";
  status_label?: string;
  visibility: "party" | "circle" | "network" | "public";
  fee_basis: string;
  fee_basis_label: string;
  fee_amount_minor: number | null;
  currency: string;
  estimated_units: number | null;
  term_starts_at: string | null;
  term_ends_at: string | null;
  closes_at: string | null;
  posted_at: string | null;
  posted_by: string | null;
  circle_name: string | null;
  goal_id?: string | null;
  /** Why this reached the viewer — "you have worked with them twice". */
  why_visible?: string;
  can_apply?: boolean;
  is_mine?: boolean;
  reach?: Record<string, unknown> | null;
  /** Null unless the viewer is on the posting side; bids are not public. */
  applications?: WorkApplication[] | null;
  application_count?: number | null;
}

export interface WorkApplication {
  id: string;
  opening_id: string;
  opening_title: string | null;
  organisation: string | null;
  organisation_id: string;
  applicant: string | null;
  status: "submitted" | "shortlisted" | "declined" | "withdrawn" | "awarded";
  status_label: string;
  is_agent: boolean;
  agent: string | null;
  agent_version: number | null;
  fee_basis: string | null;
  fee_amount_minor: number | null;
  currency: string | null;
  statement: string | null;
  availability: string | null;
  branch_id: string | null;
  branch_status: string | null;
  submitted_at: string | null;
  shortlisted_at: string | null;
  decided_at: string | null;
  decision_reason: string | null;
}

export interface Engagement {
  id: string;
  circle_id: string;
  circle_name: string | null;
  title: string;
  terms: string | null;
  status: "proposed" | "active" | "suspended" | "completed" | "terminated" | "expired";
  status_label: string;
  engaging_party: string | null;
  contractor_party: string | null;
  principal_type: string;
  principal_name: string;
  scope_goal_id: string | null;
  scope: string | null;
  fee_basis: string;
  fee_basis_label: string;
  fee_amount_minor: number | null;
  currency: string;
  unit_cap: number | null;
  units_used: number;
  is_over_cap: boolean;
  starts_at: string | null;
  ends_at: string | null;
  ended_at: string | null;
  end_reason: string | null;
  ended_by: string | null;
  /** What AccessGate is answering right now, and why when it is refusing. */
  permits_work: boolean;
  refusal_reason: string | null;
  meter?: Array<{
    id: string;
    unit: string;
    quantity: number;
    note: string | null;
    /** Whether this is a thing that happened or somebody's word for it. */
    derived: boolean;
    source_type: string | null;
    occurred_at: string | null;
  }>;
  deliverables?: Array<{
    id: string;
    title: string;
    status: string;
    due_at: string | null;
    completed_at: string | null;
  }>;
  record_id?: string | null;
}

export interface WorkRecord {
  id: string;
  sequence: number;
  title: string;
  summary: string | null;
  /** Stored strings, not joins: a record outlives its Circle. */
  counterparty: string;
  circle_name: string;
  party_role: string | null;
  fee_basis: string | null;
  started_at: string | null;
  ended_at: string | null;
  outcome: "completed" | "terminated" | "expired" | "abandoned";
  outcome_label: string;
  metrics: Record<string, number> | null;
  /** Never folded into `metrics`. The unflattering half is the useful half. */
  refusals: Record<string, unknown> | null;
  attested: boolean;
  attested_at: string | null;
  attestation_note: string | null;
  attested_by: string | null;
  published: boolean;
  visibility: string | null;
  record_hash: string;
  previous_hash: string | null;
}

export interface WorkRecordPage {
  data: WorkRecord[];
  meta: {
    principal_type: string;
    principal_id: string;
    chain: { ok: boolean; checked: number; broken_at: string | null; reason: string | null };
    summary: Record<string, number>;
  };
}

export interface WorkPackage {
  id: string;
  name: string;
  summary: string | null;
  organisation: string | null;
  version: number;
  visibility: string;
  published: boolean;
  node_count: number;
  forked_from: string | null;
  lineage_depth: number;
  created_at: string | null;
  nodes?: WorkPackageNode[];
}

export interface WorkPackageNode {
  id: string;
  title: string;
  description: string | null;
  acceptance_condition: string | null;
  /** Offsets, not dates. A package is a form rather than a copy. */
  starts_offset_days: number | null;
  due_offset_days: number | null;
  default_party_role: string | null;
  children: WorkPackageNode[];
}

export interface AgentProspectus {
  id: string;
  name: string;
  version: number;
  author: string | null;
  mandate: string;
  execution_mode: string;
  release_note: string | null;
  /** So "is this the mandate we approved in March" is mechanical. */
  content_hash: string;
  published_at: string | null;
  listing_status: string;
  highest_effect: string;
  tools: Array<{ key: string | null; name: string | null; side_effect: string }>;
  runs_where: string;
}

/**
 * Money, as this product handles it: recorded and never moved (spec §21.7).
 *
 * Minor units throughout, because a rate stored as a float is a rounding
 * argument waiting to happen between two companies.
 */
export function formatFee(minor: number | null, currency: string | null): string {
  if (minor === null) return "not stated";

  return new Intl.NumberFormat(undefined, {
    style: "currency",
    currency: currency ?? "AUD",
    maximumFractionDigits: 2,
  }).format(minor / 100);
}
