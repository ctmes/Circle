import { useEffect, useState } from "react";
import {
  api,
  citeHref,
  splitHighlights,
  type SearchHit,
} from "../lib/api";
import { Button, Empty, ErrorNote, Panel, filterClass, useAsync } from "./ui";

const LANES = ["document", "spreadsheet", "image", "video", "audio"];

/**
 * Search inside the evidence, not across its filenames.
 *
 * The locator is the headline of every row — page 4, Load Schedule!C2:D2,
 * 00:02:13 — because a result that only names the file leaves the reader to do
 * the finding again by hand. Each row ends at the claim composer with the
 * citation already attached: find, then assert, without retyping anything.
 */
export function EvidenceSearchPanel({
  circleId,
  onOpen,
  canCite,
}: {
  circleId: string;
  onOpen: (evidenceItemId: string) => void;
  canCite: boolean;
}) {
  const [typed, setTyped] = useState("");
  const [query, setQuery] = useState("");
  const [lane, setLane] = useState("");

  // Typing is not a search. Settling for a beat keeps a five-word query from
  // being five round trips.
  useEffect(() => {
    const timer = setTimeout(() => setQuery(typed.trim()), 250);
    return () => clearTimeout(timer);
  }, [typed]);

  const asked = query.length >= 2;

  const { data, error, loading, refreshing } = useAsync<SearchHit[]>(
    () =>
      asked
        ? api
            .get<{ data: SearchHit[] }>(
              `/circles/${circleId}/search?q=${encodeURIComponent(query)}` +
                (lane ? `&lane=${lane}` : ""),
            )
            .then((r) => r.data)
        : Promise.resolve([]),
    [circleId, query, lane],
  );

  const hits = data ?? [];

  return (
    <Panel
      title="Search inside the evidence"
      meta={
        asked && !loading ? (
          <span className="text-xs text-[var(--ink-faint)]">
            {refreshing ? "searching…" : `${hits.length} passage${hits.length === 1 ? "" : "s"}`}
          </span>
        ) : undefined
      }
    >
      <div className="flex flex-wrap gap-2 px-5 pb-4">
        <input
          value={typed}
          onChange={(e) => setTyped(e.target.value)}
          placeholder="A phrase from the documents — “95 tonne crawler crane”"
          aria-label="Search inside the evidence"
          className={`${filterClass} min-w-64 flex-1`}
        />
        <select
          value={lane}
          onChange={(e) => setLane(e.target.value)}
          aria-label="Restrict to one kind of media"
          className={filterClass}
        >
          <option value="">any media</option>
          {LANES.map((l) => (
            <option key={l} value={l}>
              {l}
            </option>
          ))}
        </select>
      </div>

      {!!error && <ErrorNote error={error} />}

      {!error && !asked && (
        <Empty>
          Every page, cell and transcript segment in this Circle is searchable.
          Results come back with the exact spot they were found, ready to cite.
        </Empty>
      )}

      {!error && asked && !loading && hits.length === 0 && (
        <Empty>
          No matches in the extracted text. Items still being processed, and
          anything you can't view, aren't searched.
        </Empty>
      )}

      {hits.length > 0 && (
        <ul>
          {hits.map((hit, i) => (
            <Hit
              key={`${hit.evidence_version_id}-${hit.locator_label}-${i}`}
              hit={hit}
              circleId={circleId}
              canCite={canCite}
              onOpen={onOpen}
              index={i}
            />
          ))}
        </ul>
      )}
    </Panel>
  );
}

function Hit({
  hit,
  circleId,
  canCite,
  onOpen,
  index,
}: {
  hit: SearchHit;
  circleId: string;
  canCite: boolean;
  onOpen: (evidenceItemId: string) => void;
  index: number;
}) {
  return (
    <li
      className="lay-in border-t border-[var(--rule)] px-5 py-3.5"
      style={{ animationDelay: `${index * 22}ms` }}
    >
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        {/* The locator leads. It is the answer; the filename is context. */}
        <span className="mono text-[0.875rem] font-[600] text-[var(--ink)]">
          {hit.locator_label}
        </span>
        <span className="text-xs text-[var(--ink-faint)]">
          {hit.lane}
          {hit.version_number > 1 && <span className="text-[var(--ink)]"> · v{hit.version_number}</span>}
          {hit.integrity_status === "superseded" && (
            <span
              className="ml-2 text-[var(--signal)]"
              title="There is a newer version. A citation made here still points at this one."
            >
              superseded
            </span>
          )}
        </span>
      </div>

      <p className="mt-0.5 text-[0.8125rem] text-[var(--ink-muted)]">{hit.name}</p>

      <p className="mt-2 text-[0.875rem] leading-relaxed text-[var(--ink)]">
        {splitHighlights(hit.snippet).map((run, i) =>
          run.hit ? (
            <mark
              key={i}
              className="rounded-[3px] bg-[var(--accent-soft)] px-0.5 text-[var(--ink)]"
            >
              {run.text}
            </mark>
          ) : (
            <span key={i}>{run.text}</span>
          ),
        )}
      </p>

      {/* A transcript or an OCR reading is a machine's account of the original.
          Saying so beside the words keeps it from reading as the record — and
          it is not an agent's finding either, so it does not get that stamp. */}
      {hit.machine_read && (
        <p className="mt-2 text-[0.75rem] text-[var(--derived)]">
          {hit.artifact_type === "transcript"
            ? "Machine transcription. Check the wording against the recording before citing it."
            : "OCR reading of an image. Check it against the original before citing it."}
        </p>
      )}

      <div className="mt-2.5 flex flex-wrap items-center gap-2">
        <Button variant="quiet" onClick={() => onOpen(hit.evidence_item_id)}>
          Open the record
        </Button>
        {canCite && (
          <a
            href={citeHref(circleId, hit)}
            className="inline-flex items-center justify-center gap-1.5 rounded-[var(--r-control)] bg-[var(--paper-sunk)] px-3.5 py-2 text-[0.8125rem] font-[590] leading-none text-[var(--ink)] no-underline transition-all duration-150 hover:bg-[var(--rule-strong)] active:scale-[0.98]"
          >
            Cite this →
          </a>
        )}
      </div>
    </li>
  );
}
