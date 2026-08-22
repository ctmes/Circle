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
          isClosed={circle.is_closed}
          canExport={circle.my_access?.permissions.includes("export.create") === true}
          canClose={circle.my_access?.permissions.includes("circle.close") === true}
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
    <div className="mx-auto max-w-3xl space-y-5">
      <Panel title="Mission packet">
        <div className="space-y-3 px-4 py-4">
          <p className="text-sm leading-snug">
            The packet is the Circle's record in one archive: mission metadata,
            every participant and when their access ended, an evidence manifest
            with a SHA-256 for every version, all claims and their citations,
            every decision with the exact version approved and by whom, all
            commitments, every agent run and what it read, and the complete
            audit chain with its verification result.
          </p>

          <p className="text-sm leading-snug text-[var(--ink-muted)]">
            It does not contain the original files. It contains their digests, so
            anyone holding the originals can prove they are the same bytes this
            Circle recorded — without the packet becoming a copy of the vault.
          </p>

          {!!error && <ErrorNote error={error} />}

          {canExport ? (
            <Button variant="primary" onClick={build} disabled={busy}>
              {busy ? "Assembling…" : "Build packet"}
            </Button>
          ) : (
            <p className="text-xs italic text-[var(--ink-muted)]">
              Your role does not permit exporting this Circle.
            </p>
          )}
        </div>

        {packet && (
          <div className="border-t border-[var(--rule)] px-4 py-4">
            <div className="flex flex-wrap items-baseline gap-3">
              <span
                className={`stamp ${
                  packet.audit_chain_valid ? "text-[var(--settled)]" : "text-[var(--signal)]"
                }`}
              >
                {packet.audit_chain_valid ? "chain verified" : "chain broken"}
              </span>
              <span className="mono text-xs text-[var(--ink-muted)]">
                {formatBytes(packet.byte_size)} · {formatDate(packet.completed_at, true)}
              </span>
            </div>

            <div className="mt-3">
              <Fact label="Packet SHA-256">
                {packet.sha256 ? <Copyable value={packet.sha256} truncate={28} /> : "—"}
              </Fact>
              <Fact label="Contains originals">
                {packet.manifest?.contains_originals ? "yes" : "no — digests only"}
              </Fact>
            </div>

            {packet.manifest?.files && (
              <table className="mt-3 w-full text-sm">
                <thead>
                  <tr className="border-b border-[var(--rule)]">
                    <th className="label py-1 text-left font-600">Document</th>
                    <th className="label py-1 text-right font-600">Size</th>
                    <th className="label py-1 text-left font-600">Digest</th>
                  </tr>
                </thead>
                <tbody>
                  {Object.entries(packet.manifest.files).map(([name, meta]) => (
                    <tr key={name} className="border-b border-[var(--rule)] last:border-0">
                      <td className="py-1 mono text-xs">{name}</td>
                      <td className="py-1 text-right mono text-xs text-[var(--ink-faint)]">
                        {formatBytes(meta.bytes)}
                      </td>
                      <td className="py-1">
                        <Copyable value={meta.sha256} truncate={14} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}

            {packet.download_url && (
              <a
                href={packet.download_url}
                className="display mt-4 inline-block border border-[var(--ink)] bg-[var(--ink)] px-3 py-1.5 text-xs font-600 uppercase tracking-[0.1em] text-[var(--paper)] no-underline"
              >
                Download packet
              </a>
            )}
          </div>
        )}
      </Panel>

      {!isClosed && canClose && (
        <Panel title="Close this Circle" tone="signal">
          <div className="space-y-3 px-4 py-4">
            <p className="text-sm leading-snug">
              Closing ends the mission. It takes effect immediately and cannot be
              undone from here.
            </p>

            <ul className="space-y-1 text-sm text-[var(--ink-muted)]">
              <li>— External collaborators lose access to this Circle entirely.</li>
              <li>— The Circle Steward is disabled and can no longer read anything.</li>
              <li>— No further evidence, claims, decisions or commitments can be added.</li>
              <li>— Internal members keep a read-only record, and can still export it.</li>
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
                <span className="text-xs italic text-[var(--ink-muted)]">
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
