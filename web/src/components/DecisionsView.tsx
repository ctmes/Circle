import { useState } from "react";
import {
  api,
  formatDate,
  relativeDays,
  type Decision,
  type EvidenceItem,
  type Member,
} from "../lib/api";
import { CircleFrame } from "./CircleFrame";
import { Discussion } from "./Thread";
import { DECISION_TONE, DerivedStamp, StatusChip } from "./Trust";
import {
  Button,
  Empty,
  ErrorNote,
  Fact,
  Field,
  Loading,
  Panel,
  inputClass,
  useAsync,
} from "./ui";

/**
 * The Decisions view (spec §12).
 *
 * The rule this screen exists to make legible: an approval binds to an exact
 * version. Every decision states the version it is bound to, and a superseded
 * approval says so plainly rather than quietly continuing to look approved.
 */
export function DecisionsView({ circleId }: { circleId: string }) {
  return (
    <CircleFrame circleId={circleId} tab="decisions">
      {(circle) => (
        <Body
          circleId={circleId}
          canCreate={
            circle?.my_access?.permissions.includes("decision.create") === true && !circle?.is_closed
          }
          canApprove={
            circle?.my_access?.permissions.includes("decision.approve") === true && !circle?.is_closed
          }
        />
      )}
    </CircleFrame>
  );
}

function Body({
  circleId,
  canCreate,
  canApprove,
}: {
  circleId: string;
  canCreate: boolean;
  canApprove: boolean;
}) {
  const [composing, setComposing] = useState(false);
  const { data, error, loading, reload } = useAsync<Decision[]>(
    () => api.get<{ data: Decision[] }>(`/circles/${circleId}/decisions`).then((r) => r.data),
    [circleId],
  );

  if (loading) return <Panel><Loading what="decisions" /></Panel>;
  if (error) return <ErrorNote error={error} />;

  const decisions = data ?? [];
  const pending = decisions.filter((d) => d.status === "pending");
  const drafts = decisions.filter((d) => d.status === "draft");
  const settled = decisions.filter((d) => !["pending", "draft"].includes(d.status));

  return (
    <div className="space-y-5">
      {canCreate && (
        <div className="flex justify-end">
          <Button variant={composing ? "quiet" : "primary"} onClick={() => setComposing((v) => !v)}>
            {composing ? "Cancel" : "Request a decision"}
          </Button>
        </div>
      )}

      {composing && (
        <Composer
          circleId={circleId}
          onDone={() => {
            setComposing(false);
            reload();
          }}
        />
      )}

      <Panel title="Awaiting a decision" tone={pending.length ? "signal" : "default"}>
        {pending.length === 0 ? (
          <Empty>Nothing is waiting on anyone.</Empty>
        ) : (
          pending.map((d, i) => (
            <Row key={d.id} decision={d} circleId={circleId} canApprove={canApprove} onChanged={reload} index={i} />
          ))
        )}
      </Panel>

      {drafts.length > 0 && (
        <Panel
          title="Drafts"
          tone="derived"
          meta={<span className="text-xs text-[var(--ink-faint)]">need an approver</span>}
        >
          {drafts.map((d, i) => (
            <Row key={d.id} decision={d} circleId={circleId} canApprove={false} onChanged={reload} index={i} />
          ))}
        </Panel>
      )}

      <Panel title="Resolved">
        {settled.length === 0 ? (
          <Empty>Nothing resolved yet.</Empty>
        ) : (
          settled.map((d, i) => (
            <Row key={d.id} decision={d} circleId={circleId} canApprove={false} onChanged={reload} index={i} />
          ))
        )}
      </Panel>
    </div>
  );
}

function Row({
  decision,
  circleId,
  canApprove,
  onChanged,
  index,
}: {
  decision: Decision;
  circleId: string;
  canApprove: boolean;
  onChanged: () => void;
  index: number;
}) {
  const [error, setError] = useState<unknown>(null);
  const [comment, setComment] = useState("");
  const [busy, setBusy] = useState(false);
  const [talking, setTalking] = useState(false);

  async function resolve(outcome: "approve" | "reject") {
    setBusy(true);
    setError(null);
    try {
      await api.post(`/decisions/${decision.id}/${outcome}`, { comment: comment || undefined });
      onChanged();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <article
      id={decision.id}
      className={`lay-in border-t border-[var(--rule)] px-5 py-5 ${
        decision.derived ? "derived-panel" : ""
      }`}
      style={{ animationDelay: `${index * 25}ms` }}
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <h3 className="display max-w-2xl flex-1 text-[1.0625rem] font-[620] leading-snug">
          {decision.title}
        </h3>
        <StatusChip status={decision.status} tone={DECISION_TONE[decision.status]} />
      </div>

      {decision.description && (
        <p className="mt-2 max-w-3xl whitespace-pre-line text-sm leading-relaxed text-[var(--ink-muted)]">
          {decision.description}
        </p>
      )}

      {decision.derived && (
        <div className="mt-2">
          <DerivedStamp compact />
        </div>
      )}

      <div className="mt-4 grid gap-x-8 rounded-[var(--r-control)] bg-[var(--paper-inset)] px-4 py-2.5 sm:grid-cols-2">
        <div>
          <Fact label="Approver">{decision.approver.name ?? "not assigned"}</Fact>
          <Fact label="Requested by">{decision.created_by.name ?? "—"}</Fact>
        </div>
        <div>
          {/* The exact object and version under approval. */}
          <Fact label="Subject" mono>
            {decision.subject.type
              ? `${decision.subject.type.replace(/_/g, " ")} · v${decision.subject.version ?? "?"}`
              : "no bound subject"}
          </Fact>
          <Fact label="Expires">
            {decision.expires_at ? relativeDays(decision.expires_at) : "no deadline"}
          </Fact>
        </div>
      </div>

      {decision.status === "superseded" && (
        <p className="mt-3 rounded-[var(--r-control)] bg-[var(--signal-soft)] px-3.5 py-2.5 text-sm text-[var(--ink-muted)]">
          The approved subject gained a new version after this was approved. The
          approval covered version {decision.subject.version} only and does not
          carry forward — a fresh decision is required against the current version.
        </p>
      )}

      {decision.approval_history.length > 0 && (
        <ul className="mt-3 space-y-1 rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3.5 py-2.5">
          {decision.approval_history.map((a, i) => (
            <li key={i} className="text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
              <span className="font-[590] text-[var(--ink)]">{a.actor ?? "—"}</span>{" "}
              {a.outcome} version {a.subject_version ?? "—"}
              {a.comment && <> — “{a.comment}”</>}
              <span className="text-[var(--ink-faint)]"> · {formatDate(a.occurred_at, true)}</span>
            </li>
          ))}
        </ul>
      )}

      {canApprove && decision.status === "pending" && (
        <div className="mt-3 space-y-2">
          <input
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            placeholder="Reason for the record (optional but recommended)"
            className={inputClass}
          />
          <div className="flex gap-2">
            <Button variant="primary" disabled={busy} onClick={() => resolve("approve")}>
              Approve v{decision.subject.version ?? "—"}
            </Button>
            <Button variant="danger" disabled={busy} onClick={() => resolve("reject")}>
              Reject
            </Button>
          </div>
          <p className="text-xs leading-relaxed text-[var(--ink-faint)]">
            Only the named approver can resolve this, and the approval binds to
            version {decision.subject.version ?? "—"} specifically.
          </p>
        </div>
      )}

      {!!error && <div className="mt-3"><ErrorNote error={error} /></div>}

      {/*
        The conversation that produced the decision, kept next to it. An
        approver's reason was previously the only thing anyone could say here,
        and only at the moment of approving.
      */}
      <div className="mt-3 border-t border-[var(--rule)] pt-3">
        <button
          onClick={() => setTalking((v) => !v)}
          className="text-xs text-[var(--accent)] hover:underline"
        >
          {talking ? "Hide discussion" : "Discussion"}
        </button>
        {talking && (
          <div className="mt-3">
            <Discussion
              circleId={circleId}
              subject={{ type: "decision", id: decision.id }}
              canComment
              compact
            />
          </div>
        )}
      </div>
    </article>
  );
}

function Composer({ circleId, onDone }: { circleId: string; onDone: () => void }) {
  const { data: members } = useAsync<{ members: Member[] }>(
    () => api.get<{ data: { members: Member[] } }>(`/circles/${circleId}/members`).then((r) => r.data),
    [circleId],
  );
  const { data: evidence } = useAsync<EvidenceItem[]>(
    () => api.get<{ data: EvidenceItem[] }>(`/circles/${circleId}/evidence`).then((r) => r.data),
    [circleId],
  );

  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [approver, setApprover] = useState("");
  const [subjectId, setSubjectId] = useState("");
  const [expires, setExpires] = useState("");
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const eligible = (members?.members ?? []).filter(
    (m) => m.is_active && m.permissions.includes("decision.approve"),
  );

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await api.post(`/circles/${circleId}/decisions`, {
        title,
        description: description || undefined,
        approver_user_id: approver || undefined,
        subject_type: subjectId ? "evidence_item" : undefined,
        subject_id: subjectId || undefined,
        expires_at: expires ? new Date(expires).toISOString() : undefined,
      });
      onDone();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Panel title="Request a decision" className="lay-in">
      <div className="space-y-4 px-5 pb-5">
        <Field label="What must be decided">
          <input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            className={inputClass}
            placeholder="Confirm crane load basis"
          />
        </Field>

        <Field label="Context for the approver">
          <textarea
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={2}
            className={inputClass}
            placeholder="Technical lead must confirm which load assumption governs the proposed configuration."
          />
        </Field>

        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Approver" hint="Only members who hold decision.approve appear here.">
            <select value={approver} onChange={(e) => setApprover(e.target.value)} className={inputClass}>
              <option value="">leave as a draft</option>
              {eligible.map((m) => (
                <option key={m.user.id} value={m.user.id}>
                  {m.user.name} · {m.circle_role}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Decide by">
            <input
              type="date"
              value={expires}
              onChange={(e) => setExpires(e.target.value)}
              className={inputClass}
            />
          </Field>
        </div>

        <Field
          label="Subject of the approval"
          hint="The approval will bind to this item's current version. A later version invalidates it."
        >
          <select value={subjectId} onChange={(e) => setSubjectId(e.target.value)} className={inputClass}>
            <option value="">no bound subject</option>
            {(evidence ?? []).map((e) => (
              <option key={e.id} value={e.id}>
                {e.name} (currently v{e.current_version?.version_number})
              </option>
            ))}
          </select>
        </Field>

        {!!error && <ErrorNote error={error} />}

        <Button variant="primary" onClick={submit} disabled={busy || !title.trim()}>
          {busy ? "Recording…" : "Request decision"}
        </Button>
      </div>
    </Panel>
  );
}
