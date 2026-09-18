import { useMemo, useRef, useState } from "react";
import {
  api,
  formatBytes,
  formatDate,
  type EvidenceItem,
  type FiledEvidence,
} from "../lib/api";
import { fromFileList, uploadEvidence } from "../lib/upload";
import { useJobDrop } from "./JobDrop";
import { TrustStamp } from "./Trust";
import { Button, Empty, ErrorNote, Loading, Meta, Panel, filterClass, useAsync } from "./ui";

/**
 * The documents filed against one piece of work.
 *
 * Two ways in, because there are two situations. Most of the time the file is
 * on somebody's machine and the gesture is a drop — it uploads to the vault and
 * lands on this job in one move, which is the whole point of the job having a
 * screen. Sometimes the document is already in the Circle, filed by someone
 * else against something else, and then the act is filing rather than
 * uploading: the picker below is for that, and it is deliberately the second
 * option rather than the first.
 *
 * What this never does is copy anything. A document belongs to the Circle; this
 * is an index onto it. Removing it here unfiles it and leaves the vault
 * untouched, which is said out loud on the button because "remove" next to a
 * file reads as deletion to everybody who has ever used a computer.
 */
export function JobFiles({
  circleId,
  goalId,
  canFile,
  canUpload,
  closed,
}: {
  circleId: string;
  goalId: string;
  /**
   * May file and unfile documents here — `goal.update`, which is what the
   * attach endpoint itself asks for. Filing changes this job's record; it does
   * not put anything new in the vault.
   */
  canFile: boolean;
  /**
   * May also put new files in. Uploading is a second right (`resource.upload`),
   * and the two come apart: an agent mandate can be allowed to reorganise the
   * plan without being allowed to add evidence to it.
   */
  canUpload: boolean;
  closed: boolean;
}) {
  const [picking, setPicking] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);
  const [uploadError, setUploadError] = useState<unknown>(null);

  const { data, error, loading, reload } = useAsync<FiledEvidence[]>(
    () => api.get<{ data: FiledEvidence[] }>(`/goals/${goalId}/evidence`).then((r) => r.data),
    [goalId],
  );

  const drop = useJobDrop({
    circleId,
    goalId,
    enabled: canUpload,
    onFiled: () => reload(),
  });

  const files = data ?? [];

  async function chooseFiles(list: FileList | null) {
    const picked = fromFileList(list);
    if (picked.length === 0) return;

    setUploadError(null);
    try {
      await uploadEvidence({ circleId, picked, goalId });
      reload();
    } catch (e) {
      setUploadError(e);
    }
  }

  return (
    <Panel
      title="Files"
      meta={
        <div className="flex items-center gap-2">
          <Meta>
            {files.length} filed here
          </Meta>
          {canFile && (
            <Button variant="quiet" onClick={() => setPicking((v) => !v)}>
              {picking ? "Cancel" : "Attach from the Circle"}
            </Button>
          )}
        </div>
      }
    >
      <div className="space-y-3 px-5 pb-5">
        {!!error && <ErrorNote error={error} />}
        {!!drop.error && <ErrorNote error={drop.error} />}
        {!!uploadError && <ErrorNote error={uploadError} />}

        {canUpload ? (
          <div
            {...drop.dropProps}
            onClick={() => fileInput.current?.click()}
            role="button"
            tabIndex={0}
            onKeyDown={(e) => {
              if (e.key === "Enter" || e.key === " ") fileInput.current?.click();
            }}
            className={`cursor-pointer rounded-[var(--r-card)] px-5 py-6 text-center outline-2 outline-dashed -outline-offset-2 transition-all duration-200 ${
              drop.dragging
                ? "scale-[1.01] bg-[var(--accent-soft)] outline-[var(--accent)]"
                : "bg-[var(--paper-inset)] outline-[var(--rule-strong)] hover:outline-[var(--ink-faint)]"
            }`}
          >
            <p className="display text-[0.875rem] font-[600]">
              {drop.busy
                ? drop.progress
                  ? `Uploading ${drop.progress.done} of ${drop.progress.total}…`
                  : "Uploading…"
                : drop.dragging
                  ? "Release to file it here"
                  : "Drop files or a folder onto this job"}
            </p>
            <p className="mx-auto mt-1.5 max-w-md text-xs leading-relaxed text-[var(--ink-muted)]">
              They go into the Circle's evidence vault, hashed on the way in, and
              are filed against this job so anyone reading it finds them.
            </p>
          </div>
        ) : (
          <p className="text-xs text-[var(--ink-faint)]">
            {closed
              ? "This Circle is closed, so nothing more can be filed against it."
              : canFile
                ? "You can file documents the Circle already holds, but not add new ones."
                : "You can read what is filed here, but not add to it."}
          </p>
        )}

        <input
          ref={fileInput}
          type="file"
          multiple
          hidden
          onChange={(e) => {
            void chooseFiles(e.target.files);
            e.target.value = "";
          }}
        />

        {picking && (
          <VaultPicker
            circleId={circleId}
            goalId={goalId}
            already={files.map((f) => f.id)}
            onFiled={() => {
              setPicking(false);
              reload();
            }}
          />
        )}

        {loading ? (
          <Loading what="files" />
        ) : files.length === 0 ? (
          <Empty>
            Nothing filed against this job yet. The drawing it was built to, the
            photograph of it done, the note that settles an argument about it —
            all of it belongs here rather than loose in the vault.
          </Empty>
        ) : (
          <ul className="divide-y divide-[var(--rule)]">
            {files.map((file) => (
              <FileRow
                key={file.id}
                file={file}
                goalId={goalId}
                canFile={canFile}
                onChanged={reload}
              />
            ))}
          </ul>
        )}
      </div>
    </Panel>
  );
}

function FileRow({
  file,
  goalId,
  canFile,
  onChanged,
}: {
  file: FiledEvidence;
  goalId: string;
  canFile: boolean;
  onChanged: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const version = file.current_version;

  return (
    <li className="py-2.5">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1.5">
        <span className="min-w-0 flex-1 truncate text-[0.875rem] font-[560] text-[var(--ink)]">
          {file.name}
        </span>

        <TrustStamp
          origin={file.origin_status}
          integrity={version?.integrity_status ?? file.integrity_status}
          review={file.review_status}
        />

        {canFile && (
          <Button
            variant="quiet"
            disabled={busy}
            title="Takes it off this job. The file stays in the Circle."
            onClick={async () => {
              setBusy(true);
              setError(null);
              try {
                await api.del(`/goals/${goalId}/evidence/${file.id}`);
                onChanged();
              } catch (e) {
                setError(e);
              } finally {
                setBusy(false);
              }
            }}
          >
            {busy ? "…" : "Unfile"}
          </Button>
        )}
      </div>

      <p className="mt-0.5 text-xs text-[var(--ink-faint)]">
        {version?.original_filename ?? "—"}
        {version?.byte_size ? ` · ${formatBytes(version.byte_size)}` : ""}
        {version && version.version_number > 1 ? ` · v${version.version_number}` : ""}
        {/*
          Who filed it here, which on a document pulled in from elsewhere in the
          Circle is the fact that explains why it is on this screen at all.
        */}
        {file.filed.name ? ` · filed by ${file.filed.name}` : ""}
        {file.filed.at ? ` · ${formatDate(file.filed.at)}` : ""}
      </p>

      {file.restricted_to_party && (
        <p className="mt-1 text-xs font-[560] text-[var(--signal)]">
          {file.restricted_to_party.label} only — other parties do not see this listed.
        </p>
      )}

      {!!error && (
        <div className="mt-2">
          <ErrorNote error={error} />
        </div>
      )}
    </li>
  );
}

/**
 * Filing something the Circle already holds.
 *
 * A search box rather than a long select, because the vault on a real job runs
 * to hundreds of files and the person doing this already knows the name. What
 * is filed here is excluded rather than shown ticked: this is a list of things
 * you could add, and a list that is half things you cannot is a list you have
 * to read twice.
 */
function VaultPicker({
  circleId,
  goalId,
  already,
  onFiled,
}: {
  circleId: string;
  goalId: string;
  already: string[];
  onFiled: () => void;
}) {
  const [query, setQuery] = useState("");
  const [chosen, setChosen] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  const { data, loading } = useAsync<EvidenceItem[]>(
    () => api.get<{ data: EvidenceItem[] }>(`/circles/${circleId}/evidence`).then((r) => r.data),
    [circleId],
  );

  const filed = useMemo(() => new Set(already), [already]);

  const candidates = useMemo(() => {
    const q = query.trim().toLowerCase();

    return (data ?? [])
      .filter((item) => !filed.has(item.id))
      .filter((item) =>
        q === ""
          ? true
          : `${item.name} ${item.current_version?.original_filename ?? ""}`
              .toLowerCase()
              .includes(q),
      )
      .slice(0, 40);
  }, [data, filed, query]);

  async function file() {
    if (chosen.length === 0) return;

    setBusy(true);
    setError(null);
    try {
      await api.post(`/goals/${goalId}/evidence`, { evidence_item_ids: chosen });
      setChosen([]);
      onFiled();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-2.5 rounded-[var(--r-control)] border border-[var(--rule)] bg-[var(--paper-inset)] p-3.5">
      <input
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        autoFocus
        placeholder="Search the Circle's evidence…"
        className={`${filterClass} w-full`}
        aria-label="Search evidence already in this Circle"
      />

      {!!error && <ErrorNote error={error} />}

      {loading ? (
        <Loading what="the vault" />
      ) : candidates.length === 0 ? (
        <p className="py-2 text-center text-xs text-[var(--ink-faint)]">
          {(data ?? []).length === 0
            ? "This Circle has no evidence yet."
            : "Nothing left to file against this job."}
        </p>
      ) : (
        <ul className="max-h-64 space-y-0.5 overflow-y-auto">
          {candidates.map((item) => (
            <li key={item.id}>
              <label className="flex cursor-pointer items-baseline gap-2 rounded-[var(--r-control)] px-2 py-1.5 transition-colors hover:bg-[var(--paper-sunk)]">
                <input
                  type="checkbox"
                  checked={chosen.includes(item.id)}
                  onChange={(e) =>
                    setChosen((c) =>
                      e.target.checked ? [...c, item.id] : c.filter((id) => id !== item.id),
                    )
                  }
                />
                <span className="min-w-0 flex-1 truncate text-[0.8125rem] text-[var(--ink)]">
                  {item.name}
                </span>
                <span className="shrink-0 text-xs text-[var(--ink-faint)]">
                  {item.current_version?.lane ?? "—"}
                </span>
              </label>
            </li>
          ))}
        </ul>
      )}

      <div className="flex items-center gap-2">
        <Button variant="primary" disabled={busy || chosen.length === 0} onClick={file}>
          {busy
            ? "Filing…"
            : chosen.length === 0
              ? "File against this job"
              : `File ${chosen.length} against this job`}
        </Button>
      </div>
    </div>
  );
}
