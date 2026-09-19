import { api, type Circle, type EvidenceItem } from "./api";
import { uploadEvidence, type Picked, type UploadState } from "./upload";

/**
 * Convening a Circle from an engagement of terms (spec §23).
 *
 * The shape to notice here is that nothing in this file is a new kind of thing.
 * A Circle is opened the way a Circle has always been opened, the document goes
 * into the vault through the same signed-upload pipeline as every other file,
 * and the only genuinely new call is the last one, which reads the document and
 * builds the Circle in the same request. There is no staging area where a
 * contract sits outside the gate and outside the chain while we decide what to
 * do with it — which is the whole reason the Circle is created first and named
 * after the file for the few seconds before its mission statement arrives.
 *
 * That provisional name is not a placeholder to be embarrassed about. If the
 * upload works and the reading fails, the person is left with a real Circle
 * containing their contract, which is recoverable; a staged upload that never
 * became anything is not.
 */

export type ConveningBasis = "stated" | "inferred";

export interface PlanCitation {
  evidence_version_id?: string;
  page?: number;
  excerpt?: string;
}

export interface PlanParty {
  key: string;
  display_name: string;
  defined_term: string | null;
  party_role: "principal" | "contractor" | "subcontractor" | "advisor" | "observer";
  basis: ConveningBasis;
  citation: PlanCitation | null;
}

export interface PlanStep {
  key: string;
  title: string;
  description: string | null;
  acceptance_condition: string | null;
  responsible_party_key: string | null;
  /** What the document called them, kept even when nothing matched. */
  responsible_as_written: string | null;
  starts_on: string | null;
  starts_note: string | null;
  due_on: string | null;
  /** Present when the date was computed from a period rather than stated. */
  due_note: string | null;
  clause: string | null;
  basis: ConveningBasis;
  citation: PlanCitation | null;
  level: number;
  children: PlanStep[];
}

export interface ConvenedPlan {
  mission: {
    name: string;
    purpose: string;
    commences_on: string;
    concludes_on: string | null;
    concludes_note: string | null;
    basis: ConveningBasis;
    citation: PlanCitation | null;
  };
  anchor_date: string;
  anchor_source: "supplied" | "document" | "today";
  parties: PlanParty[];
  plan: PlanStep[];
  open_questions: Array<{ question: string; why_it_matters: string | null }>;
  uncertainty: string | null;
  /** Everything the resolver had to repair or drop, in words. */
  notes: string[];
  counts: {
    parties: number;
    steps: number;
    steps_inferred: number;
    steps_dated: number;
    steps_with_acceptance: number;
    steps_assigned: number;
    citations: number;
    citations_rejected: number;
  };
}

/** What convening wrote into the Circle. */
export interface ConvenedCounts {
  parties: number;
  goals: number;
  commitments: number;
  decisions: number;
  filings: number;
}

export interface ConvenedProposal {
  id: string;
  status: string;
  /** False when the reading happened but nothing was written — see blocked_by. */
  applied?: boolean;
  /**
   * Why nothing was written: a permission the caller lacks, or
   * `circle_already_has_a_plan` where a second reading would have doubled it.
   */
  blocked_by?: string | null;
  created?: ConvenedCounts | null;
  created_at: string;
  applied_at: string | null;
  applied_by: { id: string; name: string | null } | null;
  label: string | null;
  agent: { name: string | null; version: string | null };
  model: { provider: string | null; name: string | null };
  prompt_version: string | null;
  agent_run_id: string | null;
  sources: Array<{
    evidence_item_id: string;
    evidence_version_id: string;
    name: string;
    sha256: string;
    pages: number | null;
  }>;
  plan: ConvenedPlan | null;
}

/** Where the whole gesture has got to, for a caller that wants to say so. */
export type ConveneStage =
  | "opening"
  | UploadState
  | "extracting"
  | "reading"
  | "building"
  | "done";

/** What the Convener can read text out of, and how many it reads in one go. */
export const CONVENE_EXTENSIONS = ["pdf", "docx", "txt", "md"];
export const CONVENE_MAX_FILES = 10;

export interface ConveneOptions {
  organisationId: string;
  /** Read together as one engagement: a contract and its schedules, say. */
  files: File[];
  /** Anything the person wants the Convener to check the document for. */
  lookFor?: string[];
  onStage?: (stage: ConveneStage, detail?: string) => void;
  /** Overridable so a test or a slow machine is not held to the same clock. */
  extractionTimeoutMs?: number;
}

export interface ConveneResult {
  circle: Circle;
  proposal: ConvenedProposal;
  /** What landed in the Circle. Null where the caller could not write. */
  created: ConvenedCounts | null;
}

/**
 * Why these files cannot be convened, or null.
 *
 * Checked before anything is created. A drag-and-drop is not filtered by the
 * picker's `accept`, and finding out about the spreadsheet in the middle of the
 * drop, after a Circle has been opened, leaves a half-built Circle behind for a
 * mistake that was visible from the filenames.
 */
export function conveneRefusal(files: File[]): string | null {
  if (files.length === 0) return "Choose at least one document.";

  if (files.length > CONVENE_MAX_FILES) {
    return `Up to ${CONVENE_MAX_FILES} documents can be read together. ${files.length} were chosen.`;
  }

  const unreadable = files.filter(
    (f) => !CONVENE_EXTENSIONS.includes(f.name.split(".").pop()?.toLowerCase() ?? ""),
  );

  if (unreadable.length > 0) {
    return (
      `${unreadable.map((f) => f.name).join(", ")} cannot be read for convening. ` +
      `Use PDF, DOCX, TXT or MD, and add anything else to the Circle's evidence afterwards.`
    );
  }

  return null;
}

/**
 * Open a Circle, put the documents in it, and read them together.
 *
 * Four round trips and a poll. The poll is the honest part of this: text
 * extraction is a queued job, and a flow that pretended otherwise would either
 * block on a spinner with nothing behind it or call the Convener before there
 * was anything to read. What the caller gets is a stage per step, so the person
 * watching knows whether they are waiting on their own network or on a
 * hundred-page PDF being turned into text.
 *
 * Several files are one engagement, not several: a contract whose schedules
 * arrived as separate PDFs is read in one call and makes one Circle.
 */
export async function conveneFromDocuments({
  organisationId,
  files,
  lookFor = [],
  onStage = () => {},
  extractionTimeoutMs = 180_000,
}: ConveneOptions): Promise<ConveneResult> {
  const refusal = conveneRefusal(files);
  if (refusal) throw new Error(refusal);

  onStage("opening");

  const circle = (
    await api.post<{ data: Circle }>("/circles", {
      organisation_id: organisationId,
      // Named after the first document until the plan lands, seconds later, at
      // which point the mission statement replaces it — through the audited
      // path, so the history shows what it was called before and why it changed.
      name: provisionalName(files[0].name),
      purpose:
        "Convening from an uploaded engagement of terms. This wording is replaced by the " +
        "one read out of the document.",
    })
  ).data;

  const picked: Picked[] = files.map((file) => ({ file, path: file.name }));
  const failed = new Map<string, string>();

  const upload = await uploadEvidence({
    circleId: circle.id,
    picked,
    // The documents are uploaded to be read by an agent. Saying so explicitly
    // is the point of the flag — the Convener is refused anything not marked,
    // and a flow that set this quietly behind the person's back would make the
    // whole opt-in meaningless.
    agentRead: true,
    onState: (path, state, error) => {
      if (state === "failed") failed.set(path, error ?? "Failed.");
      else onStage(state);
    },
  });

  // All or nothing. Reading the contract without the schedule that did not
  // upload would build a plan that looks complete and is not — the same
  // failure as a truncated document, with nothing in the plan to say so.
  if (failed.size > 0 || upload.created.length < files.length) {
    const named = [...failed].map(([path, reason]) => `${path}: ${reason}`);
    throw new Error(
      (named.length > 0 ? named.join(" ") : "Not every document could be stored.") +
        (upload.created.length > 0
          ? ` Nothing was read. “${circle.name}” was opened with the rest — add the missing ` +
            "document to its evidence, then read them together from its Convening page."
          : ""),
    );
  }

  onStage("extracting");
  await Promise.all(upload.created.map((item) => waitForText(item.id, extractionTimeoutMs)));

  onStage("reading");

  // One call reads the documents and writes the Circle. The server does both
  // in that order and reports what it wrote, so there is no window in which the
  // browser holds a plan that exists nowhere else — a tab closed mid-flow
  // leaves a finished Circle rather than a lost reading.
  const proposal = (
    await api.post<{ data: ConvenedProposal }>(`/circles/${circle.id}/convening`, {
      evidence_item_ids: upload.created.map((item) => item.id),
      look_for: lookFor.filter((q) => q.trim() !== ""),
    })
  ).data;

  onStage("building");

  if (proposal.applied === false) {
    // The reading survives and is readable at the Circle's convening screen,
    // so this says what is missing rather than throwing the run away.
    throw new Error(
      proposal.blocked_by === "circle_already_has_a_plan"
        ? "This Circle already has a plan, so the reading was kept but not written in."
        : `The document was read, but writing it in needs ${proposal.blocked_by}.`,
    );
  }

  onStage("done");

  return { circle, proposal, created: proposal.created ?? null };
}

/**
 * Wait for the extraction job to finish.
 *
 * `skipped` counts as finished and is left for the API to refuse: a file with
 * no text extractor is a real thing to drop by mistake, and the server's
 * message about it is better than one invented here.
 */
async function waitForText(evidenceItemId: string, timeoutMs: number): Promise<void> {
  const deadline = Date.now() + timeoutMs;

  for (;;) {
    const item = (await api.get<{ data: EvidenceItem }>(`/evidence/${evidenceItemId}`)).data;
    const status = item.current_version?.extracted_text_status;

    if (status === "ready" || status === "skipped" || status === "failed") return;

    if (Date.now() > deadline) {
      throw new Error(
        "The document is still being read after three minutes. It is safely in the Circle — " +
          "open it and convene again once the text extraction has finished.",
      );
    }

    await new Promise((r) => setTimeout(r, 1500));
  }
}

/** The filename, tidied just enough to be a Circle name somebody recognises. */
export function provisionalName(filename: string): string {
  const stem = filename.replace(/\.[^.]+$/, "").replace(/[_-]+/g, " ").trim();

  return (stem === "" ? filename : stem).slice(0, 200);
}

/** The most recent proposal for a Circle, optionally read against another start date. */
export async function loadProposal(
  circleId: string,
  anchor?: string | null,
): Promise<ConvenedProposal | null> {
  const query = anchor ? `?anchor=${encodeURIComponent(anchor)}` : "";

  return (
    await api.get<{ data: ConvenedProposal | null }>(`/circles/${circleId}/convening${query}`)
  ).data;
}

export interface AcceptedStep {
  key: string;
  parent_key: string | null;
  title: string;
  description: string | null;
  acceptance_condition: string | null;
  responsible_party_key: string | null;
  starts_on: string | null;
  due_on: string | null;
  clause: string | null;
}

export interface AcceptPayload {
  name: string;
  purpose: string;
  starts_at: string | null;
  expires_at: string | null;
  parties: Array<Pick<PlanParty, "key" | "display_name" | "party_role">>;
  steps: AcceptedStep[];
}

export async function acceptPlan(
  circleId: string,
  proposalId: string,
  payload: AcceptPayload,
): Promise<{ parties: number; goals: number }> {
  return (
    await api.post<{ data: { parties: number; goals: number } }>(
      `/circles/${circleId}/convening/${proposalId}/accept`,
      payload,
    )
  ).data;
}

/**
 * Flatten the tree into the payload, dropping whatever was deselected.
 *
 * A step whose parent was deselected is re-parented to the nearest ancestor
 * that survived, rather than dropped with it. Removing "Mobilisation" because
 * the contract does not really contain it should not silently remove the three
 * deliverables underneath — those are the part the contract does contain.
 */
export function buildSteps(plan: PlanStep[], kept: Set<string>): AcceptedStep[] {
  const steps: AcceptedStep[] = [];

  const walk = (nodes: PlanStep[], parentKey: string | null) => {
    for (const node of nodes) {
      const keep = kept.has(node.key);

      if (keep) {
        steps.push({
          key: node.key,
          parent_key: parentKey,
          title: node.title,
          description: node.description,
          acceptance_condition: node.acceptance_condition,
          responsible_party_key: node.responsible_party_key,
          starts_on: node.starts_on,
          due_on: node.due_on,
          clause: node.clause,
        });
      }

      walk(node.children, keep ? node.key : parentKey);
    }
  };

  walk(plan, null);

  return steps;
}

/** Every step in the tree, depth first. */
export function flattenPlan(nodes: PlanStep[]): PlanStep[] {
  return nodes.flatMap((node) => [node, ...flattenPlan(node.children)]);
}
