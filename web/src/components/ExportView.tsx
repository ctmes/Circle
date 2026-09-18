import { useState } from "react";
import { api, formatBytes, formatDate } from "../lib/api";
import { CircleFrame } from "./CircleFrame";
import { Button, Copyable, ErrorNote, Fact, Panel } from "./ui";

interface ExportRow {
  id: string;
  status: string;
  sha256: string | null;
  byte_size: number | null;
  audit_chain_valid: boolean | null;
  completed_at: string | null;
  error: string | null;
  manifest: {
    files?: Record<string, { sha256: string; bytes: number }>;
    contains_originals?: boolean;
    originals?: {
      included_count?: number;
      omitted_count?: number;
      bytes_included?: number;
      byte_cap_human?: string;
    };
    note?: string;
  } | null;
  download_url: string | null;
}

/**
 * Closure and export (spec §12, §16).
 *
 * Two irreversible-ish actions live here, so the screen states plainly what each
 * one does before it is taken — particularly closure, which revokes external
 * access and stops the agent.
 */
export function ExportView({ circleId }: { circleId: string }) {
  return (
    <CircleFrame circleId={circleId} tab="export">
      {(circle) => (
        <Body
          circleId={circleId}
          isClosed={circle?.is_closed === true}
          canExport={circle?.my_access?.permissions.includes("export.create") === true}
          canClose={circle?.my_access?.permissions.includes("circle.close") === true}
        />
      )}
    </CircleFrame>
  );
}

function Body({
  circleId,
  isClosed,
  canExport,
  canClose,
}: {
  circleId: string;
  isClosed: boolean;
  canExport: boolean;
  canClose: boolean;
}) {
  const [packet, setPacket] = useState<ExportRow | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const [confirmClose, setConfirmClose] = useState(false);

  async function build() {
    setBusy(true);
    setError(null);
    try {
      const res = await api.post<{ data: ExportRow }>(`/circles/${circleId}/exports`);
      setPacket(res.data);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  async function close() {
    setBusy(true);
    setError(null);
    try {
      await api.post(`/circles/${circleId}/close`, { reason: "Mission complete." });
      location.reload();
    } catch (e) {
      setError(e);
      setBusy(false);
    }
  }

  return (
    <div className="max-w-3xl space-y-5">
      <Panel title="Mission packet">
        <div className="space-y-4 px-5 pb-5">
          <p className="text-sm leading-snug">
            The packet is the Circle's record in one archive: mission metadata,
            every party and person and when their access ended, the goal tree
            with every movement of a due date and who agreed to it, an evidence
            manifest with a SHA-256 for every version, all claims and their
            citations, every decision with the exact version approved and by
            whom, all commitments, the on-record conversation, every agent run
            and what it read, every action an agent attempted and who authorised
            it, and the complete audit chain with its verification result.
          </p>

          <p className="text-sm leading-snug text-[var(--ink-muted)]">
            The original files travel with it, under evidence/, up to a size
            limit — each carrying both the digest of the bytes as shipped and the
            digest this Circle recorded at upload, so a reader can prove they
            match. Anything too large to fit is named in the manifest with the
            reason, and its digest is still there to identify it by.
          </p>

          <p className="text-sm leading-snug text-[var(--ink-muted)]">
            Conversation is included per comment: one that carried a state change
            is always in the packet, plain discussion only if its author marked
            it for the record. What is withheld is counted, never silently
            dropped.
          </p>

          {!!error && <ErrorNote error={error} />}

          {canExport ? (
            <Button variant="primary" onClick={build} disabled={busy}>
              {busy ? "Assembling…" : "Build packet"}
            </Button>
          ) : (
            <p className="text-xs text-[var(--ink-muted)]">
              Your role does not permit exporting this Circle.
            </p>
          )}
        </div>

        {packet && (
          <div className="border-t border-[var(--rule)] px-5 py-5">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
              <span
                className="inline-flex items-center gap-2 rounded-[var(--r-chip)] px-3 py-1.5 text-[0.875rem] font-[600]"
                style={{
                  color: packet.audit_chain_valid ? "var(--settled)" : "var(--signal)",
                  background: packet.audit_chain_valid
                    ? "var(--settled-soft)"
                    : "var(--signal-soft)",
                }}
              >
                <span className="inline-block size-2 rounded-full bg-current" aria-hidden="true" />
                {packet.audit_chain_valid ? "Chain verified" : "Chain broken"}
              </span>
              <span className="text-[0.8125rem] text-[var(--ink-muted)]">
                {formatBytes(packet.byte_size)} · {formatDate(packet.completed_at, true)}
              </span>
            </div>

            <div className="mt-4">
              <Fact label="Packet SHA-256">
                {packet.sha256 ? <Copyable value={packet.sha256} truncate={28} /> : "—"}
              </Fact>
              <Fact label="Original files">
                {packet.manifest?.contains_originals
                  ? `${packet.manifest.originals?.included_count ?? 0} included` +
                    (packet.manifest.originals?.omitted_count
                      ? ` · ${packet.manifest.originals.omitted_count} omitted, reasons in manifest`
                      : "")
                  : "none — digests only"}
              </Fact>
            </div>

            {packet.manifest?.files && (
              <div className="mt-4 overflow-x-auto rounded-[var(--r-control)] bg-[var(--paper-inset)]">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-[var(--rule)]">
                      <th className="label px-3 py-2 text-left font-[600]">Document</th>
                      <th className="label px-3 py-2 text-right font-[600]">Size</th>
                      <th className="label px-3 py-2 text-left font-[600]">Digest</th>
                    </tr>
                  </thead>
                  <tbody>
                    {Object.entries(packet.manifest.files).map(([name, meta]) => (
                      <tr key={name} className="border-b border-[var(--rule)] last:border-0">
                        <td className="mono px-3 py-1.5 text-xs">{name}</td>
                        <td className="px-3 py-1.5 text-right text-xs text-[var(--ink-faint)] tabular">
                          {formatBytes(meta.bytes)}
                        </td>
                        <td className="px-3 py-1.5">
                          <Copyable value={meta.sha256} truncate={14} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {packet.download_url && (
              <a
                href={packet.download_url}
                className="mt-4 inline-flex items-center rounded-[var(--r-control)] bg-[var(--accent)] px-3.5 py-2 text-[0.8125rem] font-[590] text-white no-underline shadow-[0_1px_2px_rgb(0_0_0/0.12)] transition-colors hover:bg-[var(--accent-hover)]"
              >
                Download packet
              </a>
            )}
          </div>
        )}
      </Panel>

      {!isClosed && canClose && (
        <Panel title="Close this Circle" tone="signal">
          <div className="space-y-4 px-5 pb-5">
            <p className="text-sm leading-snug">
              Closing ends the mission. It takes effect immediately and cannot be
              undone from here.
            </p>

            <ul className="space-y-1.5 rounded-[var(--r-control)] bg-[var(--paper-inset)] px-4 py-3 text-sm leading-relaxed text-[var(--ink-muted)]">
              {[
                "External collaborators lose access to this Circle entirely.",
                "The Circle Steward is disabled and can no longer read anything.",
                "No further evidence, claims, decisions or commitments can be added.",
                "Internal members keep a read-only record, and can still export it.",
              ].map((line) => (
                <li key={line} className="flex gap-2.5">
                  <span className="text-[var(--signal)]" aria-hidden="true">•</span>
                  {line}
                </li>
              ))}
            </ul>

            {!confirmClose ? (
              <Button variant="danger" onClick={() => setConfirmClose(true)}>
                Close the Circle
              </Button>
            ) : (
              <div className="flex flex-wrap items-center gap-3">
                <Button variant="danger" onClick={close} disabled={busy}>
                  {busy ? "Closing…" : "Yes, close it now"}
                </Button>
                <Button variant="quiet" onClick={() => setConfirmClose(false)}>
                  Keep it open
                </Button>
                <span className="text-xs text-[var(--ink-muted)]">
                  Consider building the packet first.
                </span>
              </div>
            )}
          </div>
        </Panel>
      )}
    </div>
  );
}
