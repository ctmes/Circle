import { useState } from "react";
import {
  api,
  describeLocator,
  formatDate,
  type Claim,
  type EvidenceItem,
} from "../lib/api";
import { CircleFrame } from "./CircleFrame";
import { DerivedStamp, StatusChip } from "./Trust";
import {
  Button,
  Empty,
  ErrorNote,
  Field,
  Loading,
  Panel,
  inputClass,
  useAsync,
} from "./ui";

/**
 * The Claims view (spec §12).
 *
 * A claim is never shown without its citations. Agent-authored claims get the
 * derived treatment so the difference between "a person asserted this" and
 * "a model inferred this" is visible before the sentence is read.
 */
export function ClaimsView({ circleId }: { circleId: string }) {
  return (
    <CircleFrame circleId={circleId} tab="claims">
      {(circle) => (
        <Body
          circleId={circleId}
          canCreate={
            circle.my_access?.permissions.includes("claim.create") === true && !circle.is_closed
          }
          canReview={
            circle.my_access?.permissions.includes("claim.review") === true && !circle.is_closed
          }
        />
      )}
    </CircleFrame>
  );
}

function Body({
  circleId,
  canCreate,
  canReview,
}: {
  circleId: string;
  canCreate: boolean;
  canReview: boolean;
}) {
  const [composing, setComposing] = useState(false);
  const { data, error, loading, reload } = useAsync<Claim[]>(
    () => api.get<{ data: Claim[] }>(`/circles/${circleId}/claims`).then((r) => r.data),
    [circleId],
  );

  if (loading) return <Loading what="claims" />;
  if (error) return <ErrorNote error={error} />;

  const claims = data ?? [];
  const byHuman = claims.filter((c) => !c.derived);
  const byAgent = claims.filter((c) => c.derived);

  return (
    <div className="space-y-5">
      {canCreate && (
        <div className="flex justify-end">
          <Button variant={composing ? "quiet" : "primary"} onClick={() => setComposing((v) => !v)}>
            {composing ? "Cancel" : "Make a claim"}
          </Button>
        </div>
      )}

      {composing && (
        <ClaimComposer
          circleId={circleId}
          onDone={() => {
            setComposing(false);
            reload();
          }}
        />
      )}

      <Panel
        title="Claims on the record"
        meta={
          <span className="mono text-xs text-[var(--ink-faint)]">
            {byHuman.length} attested · {byAgent.length} derived
          </span>
        }
      >
        {claims.length === 0 ? (
          <Empty>
            No claims yet. A claim is an assertion someone is willing to put their
            name to, backed by exact evidence.
          </Empty>
        ) : (
          <ul>
            {claims.map((claim, i) => (
              <ClaimRow
                key={claim.id}
                claim={claim}
                circleId={circleId}
                canReview={canReview}
                onChanged={reload}
                index={i}
              />
            ))}
          </ul>
        )}
      </Panel>
    </div>
  );
}

function ClaimRow({
  claim,
  circleId,
  canReview,
  onChanged,
  index,
}: {
  claim: Claim;
  circleId: string;
  canReview: boolean;
  onChanged: () => void;
  index: number;
}) {
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  async function review(outcome: string) {
    setBusy(true);
    setError(null);
    try {
      await api.post(`/claims/${claim.id}/review`, { outcome });
      onChanged();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <li
      id={claim.id}
      className={`lay-in border-b border-[var(--rule)] last:border-0 ${
        claim.derived ? "derived-panel" : ""
      }`}
      style={{ animationDelay: `${index * 25}ms` }}
    >
      <div className="px-4 py-3.5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <p className="max-w-3xl flex-1 text-[0.9375rem] leading-snug">{claim.statement}</p>
          <div className="flex shrink-0 items-center gap-2">
            <StatusChip status={claim.status} />
          </div>
        </div>

        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
          {claim.derived && <DerivedStamp compact />}
          <span className="mono text-[0.6875rem] text-[var(--ink-faint)]">
            {claim.claim_type.replace(/_/g, " ")}
          </span>
          <span className="mono text-[0.6875rem] text-[var(--ink-faint)]">
            {claim.derived
              ? "Circle Steward"
              : (claim.author as any)?.name ?? "unknown"}
          </span>
          <span className="mono text-[0.6875rem] text-[var(--ink-faint)]">
            {formatDate(claim.created_at)}
          </span>
          {claim.confidence !== null && (
            <span
              className="mono text-[0.6875rem] text-[var(--ink-muted)]"
              title="How well the author says the evidence supports this — not a measure of correctness."
            >
              confidence {Math.round(claim.confidence * 100)}%
            </span>
          )}
        </div>

        {/* Citations are the load-bearing part: a claim without them is opinion. */}
        <ul className="mt-3 space-y-1">
          {claim.citations.length === 0 ? (
            <li className="text-xs italic text-[var(--signal)]">
              No evidence cited.
            </li>
          ) : (
            claim.citations.map((c) => (
              <li key={c.id} className="flex flex-wrap items-baseline gap-2">
                <span className="label !text-[0.5625rem]">cites</span>
                <a
                  href={`/circles/${circleId}/context`}
                  className="mono text-xs text-[var(--ink)] underline decoration-dotted underline-offset-2"
                >
                  {c.evidence?.name ?? c.evidence?.filename ?? "evidence"}
                </a>
                <span className="mono text-[0.6875rem] text-[var(--ink-muted)]">
                  v{c.evidence?.version_number} · {describeLocator(c.citation_type, c.locator)}
                </span>
                {c.evidence?.integrity_status === "superseded" && (
                  <span
                    className="mono text-[0.6875rem] text-[var(--ink-faint)]"
                    title="A newer version of the cited evidence exists. This citation still resolves to the version that was cited."
                  >
                    (superseded version)
                  </span>
                )}
              </li>
            ))
          )}
        </ul>

        {claim.reviews.length > 0 && (
          <ul className="mt-3 space-y-1 border-l-2 border-[var(--rule)] pl-3">
            {claim.reviews.map((r, i) => (
              <li key={i} className="text-xs text-[var(--ink-muted)]">
                <span className="mono">{r.reviewer ?? "—"}</span> {r.outcome.replace(/_/g, " ")}
                {r.comment && <> — “{r.comment}”</>}
                <span className="mono text-[var(--ink-faint)]"> · {formatDate(r.at)}</span>
              </li>
            ))}
          </ul>
        )}

        {canReview && (
          <div className="mt-3 flex flex-wrap gap-2">
            <Button variant="quiet" disabled={busy} onClick={() => review("reviewed")}>
              Mark reviewed
            </Button>
            <Button variant="quiet" disabled={busy} onClick={() => review("changes_requested")}>
              Request changes
            </Button>
            <Button variant="quiet" disabled={busy} onClick={() => review("contested")}>
              Contest
            </Button>
          </div>
        )}

        {!!error && <div className="mt-3"><ErrorNote error={error} /></div>}
      </div>
    </li>
  );
}

/**
 * Composing a claim forces a citation: the evidence picker is part of the form,
 * not an afterthought, and the locator fields change with the media type.
 */
function ClaimComposer({ circleId, onDone }: { circleId: string; onDone: () => void }) {
  const { data: evidence } = useAsync<EvidenceItem[]>(
    () => api.get<{ data: EvidenceItem[] }>(`/circles/${circleId}/evidence`).then((r) => r.data),
    [circleId],
  );

  const [statement, setStatement] = useState("");
  const [type, setType] = useState("factual");
  const [versionId, setVersionId] = useState("");
  const [locator, setLocator] = useState<Record<string, string>>({});
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const chosen = evidence?.find((e) => e.current_version?.id === versionId);
  const lane = chosen?.current_version?.lane;

  function locatorPayload(): Record<string, unknown> | undefined {
    if (lane === "document" && locator.page) return { page: Number(locator.page) };
    if (lane === "spreadsheet" && (locator.sheet || locator.range)) {
      return {
        ...(locator.sheet ? { sheet: locator.sheet } : {}),
        ...(locator.range ? { range: locator.range } : {}),
      };
    }
    if ((lane === "video" || lane === "audio") && locator.start) {
      return {
        start_seconds: Number(locator.start),
        ...(locator.end ? { end_seconds: Number(locator.end) } : {}),
      };
    }
    return undefined;
  }

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await api.post(`/circles/${circleId}/claims`, {
        statement,
        claim_type: type,
        citations: versionId
          ? [{ evidence_version_id: versionId, locator: locatorPayload() }]
          : [],
      });
      onDone();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Panel title="New claim" className="lay-in">
      <div className="space-y-3 px-4 py-4">
        <Field
          label="Statement"
          hint="Say one thing that the evidence can support. Interpretations belong in an assessment type, not a factual claim."
        >
          <textarea
            value={statement}
            onChange={(e) => setStatement(e.target.value)}
            rows={3}
            className={inputClass}
            placeholder="The load schedule specifies a 95 t crane while drawing Rev B assumes 70 t."
          />
        </Field>

        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Kind of claim">
            <select value={type} onChange={(e) => setType(e.target.value)} className={inputClass}>
              <option value="factual">factual — the document literally says this</option>
              <option value="technical_assessment">technical assessment</option>
              <option value="commercial_assessment">commercial assessment</option>
              <option value="risk">risk</option>
              <option value="recommendation">recommendation</option>
            </select>
          </Field>

          <Field label="Evidence cited">
            <select
              value={versionId}
              onChange={(e) => {
                setVersionId(e.target.value);
                setLocator({});
              }}
              className={inputClass}
            >
              <option value="">choose an item…</option>
              {(evidence ?? [])
                .filter((e) => e.current_version)
                .map((e) => (
                  <option key={e.id} value={e.current_version!.id}>
                    {e.name} (v{e.current_version!.version_number})
                  </option>
                ))}
            </select>
          </Field>
        </div>

        {/* The locator fields follow the media, so the citation can be precise. */}
        {lane === "document" && (
          <Field label="Page" hint="Which page of the document supports this?">
            <input
              value={locator.page ?? ""}
              onChange={(e) => setLocator({ page: e.target.value })}
              type="number"
              min={1}
              className={`${inputClass} max-w-32`}
            />
          </Field>
        )}

        {lane === "spreadsheet" && (
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Sheet">
              <input
                value={locator.sheet ?? ""}
                onChange={(e) => setLocator((l) => ({ ...l, sheet: e.target.value }))}
                className={inputClass}
                placeholder="Load Schedule"
              />
            </Field>
            <Field label="Cell or range" hint="A1 style, e.g. F12 or F12:H12">
              <input
                value={locator.range ?? ""}
                onChange={(e) => setLocator((l) => ({ ...l, range: e.target.value }))}
                className={inputClass}
                placeholder="C2:D2"
              />
            </Field>
          </div>
        )}

        {(lane === "video" || lane === "audio") && (
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="From (seconds)">
              <input
                value={locator.start ?? ""}
                onChange={(e) => setLocator((l) => ({ ...l, start: e.target.value }))}
                type="number"
                min={0}
                className={inputClass}
              />
            </Field>
            <Field label="To (seconds)">
              <input
                value={locator.end ?? ""}
                onChange={(e) => setLocator((l) => ({ ...l, end: e.target.value }))}
                type="number"
                min={0}
                className={inputClass}
              />
            </Field>
          </div>
        )}

        {!!error && <ErrorNote error={error} />}

        <div className="flex items-center gap-3">
          <Button variant="primary" onClick={submit} disabled={busy || !statement.trim()}>
            {busy ? "Recording…" : "Put on the record"}
          </Button>
          {!versionId && (
            <span className="text-xs italic text-[var(--ink-muted)]">
              A claim with no citation can be recorded, but it carries no weight.
            </span>
          )}
        </div>
      </div>
    </Panel>
  );
}
