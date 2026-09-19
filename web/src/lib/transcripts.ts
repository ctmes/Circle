import { api } from "./api";

/**
 * Meeting transcripts in, and the log of what each one did (spec §24).
 *
 * The same endpoint serves a person pasting a transcript and a note-taker's
 * automation posting one; this file is the person's half.
 */

export type ImportStatus =
  | "pending"
  | "processing"
  | "applied"
  | "failed"
  | "duplicate"
  | "ignored";

export interface ImportCounts {
  created: number;
  updated: number;
  completed: number;
  abandoned: number;
  commitments: number;
  failed: number;
  skipped: number;
  /** Present when the meeting opened a new Circle and was convened. */
  goals?: number;
  parties?: number;
  decisions?: number;
}

export interface ImportAction {
  op: string;
  title: string | null;
  evidence: string | null;
  status: string;
  error?: string | null;
  due_note?: string;
  agent_action_id?: string;
}

export interface TranscriptImport {
  id: string;
  organisation_id: string;
  status: ImportStatus;
  source: string;
  external_id: string | null;
  title: string | null;
  occurred_at: string | null;
  participants: string[] | null;
  chars: number;
  circle: { id: string; name: string | null } | null;
  routing: "routed_to_existing" | "opened_new" | "pinned" | "none" | null;
  routing_reason: string | null;
  submitted_by: { id: string | null; name: string | null };
  summary: string | null;
  counts: Partial<ImportCounts> | null;
  mode: "scribe" | "convened" | null;
  error: string | null;
  created_at: string;
  processed_at: string | null;
  // detail only
  uncertainty?: string | null;
  actions?: ImportAction[];
}

export interface ConnectorToken {
  id: number | string;
  name: string;
  organisation_id: string | null;
  last_used_at: string | null;
  created_at: string;
  /** Present once, on creation. */
  token?: string;
}

export const listImports = (organisationId?: string) =>
  api
    .get<{ data: TranscriptImport[] }>(
      `/transcripts${organisationId ? `?organisation_id=${encodeURIComponent(organisationId)}` : ""}`,
    )
    .then((r) => r.data);

export const getImport = (id: string) =>
  api.get<{ data: TranscriptImport }>(`/transcripts/${id}`).then((r) => r.data);

export const retryImport = (id: string) =>
  api.post<{ data: TranscriptImport }>(`/transcripts/${id}/retry`).then((r) => r.data);

export const sendTranscript = (body: {
  organisation_id: string;
  text: string;
  title?: string;
  occurred_at?: string;
  circle_id?: string;
}) => api.post<{ data: TranscriptImport }>("/transcripts", body).then((r) => r.data);

export const listConnectorTokens = () =>
  api.get<{ data: ConnectorToken[] }>("/connector-tokens").then((r) => r.data);

export const createConnectorToken = (organisationId: string, name: string) =>
  api
    .post<{ data: ConnectorToken }>("/connector-tokens", { organisation_id: organisationId, name })
    .then((r) => r.data);

export const revokeConnectorToken = (id: number | string) => api.del(`/connector-tokens/${id}`);

/** Whether the log should keep polling: something is still being worked on. */
export const inFlight = (imports: TranscriptImport[]) =>
  imports.some((i) => i.status === "pending" || i.status === "processing");
