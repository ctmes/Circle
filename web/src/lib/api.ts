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
  my_access: { is_external: boolean; permissions: string[] } | null;
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

export interface Claim {
  id: string;
  statement: string;
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
  id: string;
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
  counts: { commitments: number; decisions: number; claims: number };
  children?: Goal[];
  schedule_changes?: ScheduleChange[];
}

export type ThreadSubject =
  | "goal" | "claim" | "decision" | "commitment" | "evidence_item";

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
  visibility: "circle" | "party";
  party: string | null;
  status: string;
  is_resolved: boolean;
  last_activity_at: string | null;
  created_at: string | null;
  comment_count: number;
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
  created_at: string | null;
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
  executed_at: string | null;
  created_at: string | null;
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
