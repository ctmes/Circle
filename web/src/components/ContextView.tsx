import { useState } from "react";
import {
  api,
  describeLocator,
  formatBytes,
  formatDate,
  type EvidenceItem,
  type EvidenceVersion,
} from "../lib/api";
import { CircleFrame } from "./CircleFrame";
import { PipelineMark, TrustStamp } from "./Trust";
import {
  Button,
  Copyable,
  Empty,
  ErrorNote,
  Fact,
  Loading,
  Panel,
  useAsync,
  inputClass,
  filterClass,
} from "./ui";
import { UploadDrop } from "./UploadDrop";

const LANES = ["document", "spreadsheet", "image", "video", "audio", "url", "other"];

/**
 * The Context view (spec §12): the evidence vault.
 *
 * The register on the left is the drawing schedule — one ruled row per item,
 * scannable down a column. Selecting a row opens the full record: provenance,
 * every version, and what depends on it.
 */
export function ContextView({ circleId }: { circleId: string }) {
  return (
    <CircleFrame circleId={circleId} tab="context">
      {(circle) => (
        <Body
          circleId={circleId}
          canUpload={
            circle?.my_access?.permissions.includes("resource.upload") === true &&
            !circle?.is_closed &&
            !circle?.is_expired
          }
        />
      )}
    </CircleFrame>
  );
}

function Body({ circleId, canUpload }: { circleId: string; canUpload: boolean }) {
  const [selected, setSelected] = useState<string | null>(null);
  const [lane, setLane] = useState("");
  const [review, setReview] = useState("");
  const [query, setQuery] = useState("");

  const { data, error, loading, reload } = useAsync<EvidenceItem[]>(
    () =>
      api
        .get<{ data: EvidenceItem[] }>(
          `/circles/${circleId}/evidence` +
            (review ? `?review_status=${review}` : ""),
        )
        .then((r) => r.data),
    [circleId, review],
  );

  if (loading) return <Panel><Loading what="evidence register" /></Panel>;
  if (error) return <ErrorNote error={error} />;

  const items = (data ?? []).filter((item) => {
    if (lane && item.current_version?.lane !== lane) return false;
    if (query) {
      const haystack = `${item.name} ${item.current_version?.original_filename ?? ""} ${item.uploader.name ?? ""}`;
      if (!haystack.toLowerCase().includes(query.toLowerCase())) return false;
    }
    return true;
  });

  return (
    <div className="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
      <div className="space-y-4">
        {canUpload && <UploadDrop circleId={circleId} onUploaded={reload} />}

        <Panel
          title="Evidence register"
          meta={
            <span className="text-xs text-[var(--ink-faint)]">
              {items.length} of {data?.length ?? 0}
            </span>
          }
        >
          <div className="flex flex-wrap gap-2 px-5 pb-4">
            <input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Filter by name, file or contributor"
              className={`${filterClass} min-w-52 flex-1`}
            />
            <select value={lane} onChange={(e) => setLane(e.target.value)} className={filterClass}>
              <option value="">any media</option>
              {LANES.map((l) => (
                <option key={l} value={l}>{l}</option>
              ))}
            </select>
            <select value={review} onChange={(e) => setReview(e.target.value)} className={filterClass}>
              <option value="">any review state</option>
              {["unreviewed", "reviewed", "contested", "stale", "approved"].map((r) => (
                <option key={r} value={r}>{r}</option>
              ))}
            </select>
          </div>

          {items.length === 0 ? (
            <Empty>No evidence matches. Anything uploaded here is preserved unchanged.</Empty>
          ) : (
            <ul>
              {items.map((item, i) => (
                <li key={item.id} className="lay-in" style={{ animationDelay: `${i * 22}ms` }}>
                  <button
                    onClick={() => setSelected(item.id)}
                    aria-current={selected === item.id}
                    className={`block w-full border-t border-[var(--rule)] px-5 py-3.5 text-left transition-colors ${
                      selected === item.id
                        ? "bg-[var(--accent-soft)] shadow-[inset_3px_0_0_var(--accent)]"
                        : "hover:bg-[var(--paper-sunk)]"
                    }`}
                  >
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                      <span className="display text-[0.9375rem] font-[600]">{item.name}</span>
                      <span className="text-xs text-[var(--ink-faint)]">
                        {item.current_version?.lane ?? "—"} ·{" "}
                        {formatBytes(item.current_version?.byte_size ?? null)}
                        {item.version_count > 1 && (
                          <span className="text-[var(--ink)]"> · v{item.current_version?.version_number}</span>
                        )}
                      </span>
                    </div>

                    <div className="mt-1.5">
                      <TrustStamp
                        origin={item.origin_status}
                        integrity={item.current_version?.integrity_status ?? item.integrity_status}
                        review={item.review_status}
                      />
                    </div>

                    <p className="mt-1.5 text-xs text-[var(--ink-faint)]">
                      {item.uploader.name ?? "unknown"} · {formatDate(item.created_at)}
                      {item.agent_read && (
                        <span className="ml-2 text-[var(--derived)]">agent-readable</span>
                      )}
                      {!item.downloadable && (
                        <span className="ml-2 text-[var(--signal)]">no download</span>
                      )}
                    </p>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>

      <div className="xl:sticky xl:top-[4.25rem] xl:self-start">
        {selected ? (
          <Detail key={selected} itemId={selected} circleId={circleId} onChanged={reload} />
        ) : (
          <Panel title="Record">
            <Empty>Select an item to see its provenance, versions and dependents.</Empty>
          </Panel>
        )}
      </div>
    </div>
  );
}

function Detail({
  itemId,
  circleId,
  onChanged,
}: {
  itemId: string;
  circleId: string;
  onChanged: () => void;
}) {
  const { data: item, error, loading, reload } = useAsync<EvidenceItem>(
    () => api.get<{ data: EvidenceItem }>(`/evidence/${itemId}`).then((r) => r.data),
    [itemId],
  );
  const [actionError, setActionError] = useState<unknown>(null);

  if (loading) return <Panel title="Record"><Loading what="item" /></Panel>;
  if (error) return <ErrorNote error={error} />;
  if (!item) return null;

  const current = item.current_version;

  async function download(version: EvidenceVersion) {
    setActionError(null);
    try {
      const res = await api.get<{ data: { url: string } }>(
        `/evidence-versions/${version.id}/download-url`,
      );
      window.open(res.data.url, "_blank", "noopener");
    } catch (e) {
      setActionError(e);
    }
  }

  async function act(path: string, body?: unknown) {
    setActionError(null);
    try {
      await api.post(path, body);
      reload();
      onChanged();
    } catch (e) {
      setActionError(e);
    }
  }

  return (
    <div className="space-y-4">
      <Panel title="Record" meta={<span className="text-xs text-[var(--ink-faint)]">{item.current_version?.lane}</span>}>
        <div className="px-5 py-3.5">
          <h3 className="display text-base font-[650] leading-snug">{item.name}</h3>
          <div className="mt-2">
            <TrustStamp
              origin={item.origin_status}
              integrity={current?.integrity_status ?? item.integrity_status}
              review={item.review_status}
            />
          </div>
        </div>

        <div className="border-t border-[var(--rule)] px-5 py-3.5">
          <Fact label="Supplied by">{item.uploader.name ?? "unknown"}</Fact>
          <Fact label="Received">{formatDate(item.created_at, true)}</Fact>
          <Fact label="Classification">{item.classification}</Fact>
          <Fact label="Original file" mono>{current?.original_filename}</Fact>
          <Fact label="Size" mono>{formatBytes(current?.byte_size ?? null)}</Fact>
          <Fact label="SHA-256">
            {current?.sha256 ? <Copyable value={current.sha256} truncate={20} /> : "—"}
          </Fact>
          {item.source_label && <Fact label="Source">{item.source_label}</Fact>}
          {item.stale_at && (
            <Fact label="Flagged stale">
              <span className="text-[var(--signal)]">{formatDate(item.stale_at)}</span>
            </Fact>
          )}
        </div>

        {/* Per-lane extraction state, so "nothing here" is never ambiguous. */}
        {current && (
          <div className="flex flex-wrap gap-x-5 gap-y-1 border-t border-[var(--rule)] px-5 py-3">
            <PipelineMark label="original" status={current.processing_status} />
            <PipelineMark label="text" status={current.extracted_text_status} />
            <PipelineMark label="preview" status={current.preview_status} />
            <PipelineMark label="transcript" status={current.transcript_status} />
          </div>
        )}

        {current?.processing_error && (
          <p className="border-t border-[var(--rule)] px-5 py-2.5 text-xs text-[var(--signal)]">
            {current.processing_error}
          </p>
        )}

        {/* EXIF is displayed but never presented as proof (spec §7). */}
        {current?.metadata?.exif && (
          <div className="border-t border-[var(--rule)] px-5 py-3.5">
            <p className="label">Device metadata</p>
            <dl className="mt-1 grid grid-cols-2 gap-x-4">
              {Object.entries(current.metadata.exif).map(([k, v]) => (
                <div key={k} className="flex gap-2">
                  <dt className="text-xs text-[var(--ink-faint)]">{k}</dt>
                  <dd className="mono text-[0.6875rem]">{String(v)}</dd>
                </div>
              ))}
            </dl>
            <p className="mt-2 text-xs leading-snug text-[var(--ink-muted)]">
              {current.metadata.exif_caveat}
            </p>
          </div>
        )}

        <div className="flex flex-wrap gap-2 border-t border-[var(--rule)] px-5 py-3.5">
          {current && (
            <Button onClick={() => download(current)} disabled={!item.downloadable}>
              Download original
            </Button>
          )}
          <Button onClick={() => act(`/evidence/${item.id}/review`, { review_status: "reviewed" })}>
            Mark reviewed
          </Button>
          <Button variant="danger" onClick={() => act(`/evidence/${item.id}/review`, { review_status: "contested" })}>
            Contest
          </Button>
          <Button variant="danger" onClick={() => act(`/evidence/${item.id}/mark-stale`)}>
            Flag stale
          </Button>
        </div>

        {!!actionError && <div className="px-5 pb-4"><ErrorNote error={actionError} /></div>}
      </Panel>

      <Panel title="Version history" meta={<span className="text-xs text-[var(--ink-faint)]">{item.versions?.length ?? 0}</span>}>
        {!item.versions?.length ? (
          <Empty>No versions.</Empty>
        ) : (
          <ul>
            {[...item.versions].reverse().map((v) => (
              <li key={v.id} className="border-t border-[var(--rule)] px-5 py-3">
                <div className="flex items-baseline justify-between gap-2">
                  <span className="display text-sm font-[600]">v{v.version_number}</span>
                  <button
                    onClick={() => download(v)}
                    className="rounded-md px-1.5 py-0.5 text-xs font-[560] text-[var(--accent)] transition-colors hover:bg-[var(--accent-soft)] disabled:opacity-40"
                    disabled={!item.downloadable}
                  >
                    Download
                  </button>
                </div>
                <p className="text-xs text-[var(--ink-faint)]">
                  {v.original_filename} · {formatBytes(v.byte_size)} ·{" "}
                  {v.created_by.name ?? "unknown"} · {formatDate(v.created_at, true)}
                </p>
                <p className="mt-0.5 text-xs text-[var(--ink-faint)]">
                  {v.sha256 ? <Copyable value={v.sha256} truncate={16} /> : "not hashed"}
                  {v.supersedes_version_id && <span className="ml-2">supersedes v{v.version_number - 1}</span>}
                </p>
              </li>
            ))}
          </ul>
        )}
      </Panel>

      {/* "Used by" — what would be affected if this evidence changed. */}
      <Panel title="Used by">
        {!item.used_by?.claims.length && !item.used_by?.decisions.length ? (
          <Empty>Nothing cites this item yet.</Empty>
        ) : (
          <div>
            {item.used_by.claims.map((c) => (
              <a
                key={c.claim_id + c.evidence_version_id}
                href={`/circles/${circleId}/claims#${c.claim_id}`}
                className="block border-b border-[var(--rule)] px-5 py-3 no-underline hover:bg-[var(--paper-sunk)]"
              >
                <p className="label">claim</p>
                <p className="text-sm leading-snug">{c.statement}</p>
                <p className="text-xs text-[var(--ink-faint)]">
                  cites {describeLocator("", c.locator)} · {c.status}
                </p>
              </a>
            ))}
            {item.used_by.decisions.map((d) => (
              <a
                key={d.decision_id}
                href={`/circles/${circleId}/decisions#${d.decision_id}`}
                className="block border-t border-[var(--rule)] px-5 py-3 no-underline hover:bg-[var(--paper-sunk)]"
              >
                <p className="label">decision</p>
                <p className="text-sm leading-snug">{d.title}</p>
                <p className="text-xs text-[var(--ink-faint)]">
                  {d.status} · bound to v{d.subject_version ?? "?"}
                </p>
              </a>
            ))}
          </div>
        )}
      </Panel>
    </div>
  );
}
