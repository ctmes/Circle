import { useState } from "react";
import { api, formatDate, type GrantRow, type Member } from "../lib/api";
import { Button, Empty, ErrorNote, Field, Loading, Panel, inputClass, useAsync } from "./ui";

/**
 * One right, handed to one person, without moving them to a role that carries
 * a dozen others.
 *
 * The gate has read these grants since the first migration and nothing wrote
 * them, so the only way to let a contributor author an agent was to make them
 * an owner — which also hands over membership control and the right to close
 * the Circle. That is how permission systems rot: the narrow need is
 * unreachable, so everybody gets the wide role.
 *
 * Shown to anyone who can see the Circle rather than only to whoever can write
 * them. An exception to the role vocabulary that half the Circle cannot see
 * means they are working against a permission model that is not the one in
 * force — and in cross-company work the other party is exactly who needs to
 * know that the client's contractor can now author agents here.
 */

/**
 * The rights worth handing out one at a time.
 *
 * Deliberately not the whole enum. Most permissions belong to their role and
 * exist as a set — a "contributor who can also approve decisions" is an
 * approver, and saying so is clearer than assembling one from parts. What is
 * here is the handful where the narrow grant is the honest answer: the agent
 * rights, which cut across roles because whoever runs the automation is rarely
 * whoever runs the project, and the two export-and-close rights people
 * genuinely delegate while staying in charge.
 */
const GRANTABLE: Array<{ value: string; label: string; note: string }> = [
  {
    value: "agent.author",
    label: "Author agents",
    note: "Write and edit agent mandates for their own organisation.",
  },
  {
    value: "agent.connect",
    label: "Bring in an agent",
    note: "Register an agent their company runs. Letting it in is still an owner's call.",
  },
  {
    value: "agent.run",
    label: "Run agents",
    note: "Start a run. What the agent can do is still limited by its mode.",
  },
  {
    value: "agent.approve",
    label: "Approve agent actions",
    note: "Approve or refuse what agents propose, as far as their role allows.",
  },
  {
    value: "export.create",
    label: "Export the packet",
    note: "Produce the evidence and decision packet.",
  },
  {
    value: "resource.download",
    label: "Download evidence",
    note: "External collaborators are denied this by default.",
  },
  {
    value: "party.manage",
    label: "Manage parties",
    note: "Add and edit the companies named in this Circle.",
  },
];

export function GrantsPanel({
  circleId,
  canManage,
  members,
}: {
  circleId: string;
  canManage: boolean;
  members: Member[];
}) {
  const { data, error, loading, reload } = useAsync<GrantRow[]>(
    () => api.get<{ data: GrantRow[] }>(`/circles/${circleId}/grants`).then((r) => r.data),
    [circleId],
  );

  const [adding, setAdding] = useState(false);
  const [actionError, setActionError] = useState<unknown>(null);

  const grants = data ?? [];

  return (
    <Panel
      title="Exceptions"
      meta={
        canManage && (
          <Button variant={adding ? "quiet" : "quiet"} onClick={() => setAdding((v) => !v)}>
            {adding ? "Cancel" : "Grant a right"}
          </Button>
        )
      }
    >
      {loading && <Loading what="exceptions" />}
      {!!error && <ErrorNote error={error} />}
      {!!actionError && (
        <div className="px-5 py-3">
          <ErrorNote error={actionError} />
        </div>
      )}

      {adding && (
        <div className="px-5 pb-4">
          <GrantForm
            circleId={circleId}
            members={members.filter((m) => m.is_active)}
            onDone={() => {
              setAdding(false);
              reload();
            }}
            onCancel={() => setAdding(false)}
          />
        </div>
      )}

      {!loading && grants.length === 0 ? (
        <Empty>
          Nobody has anything beyond what their role gives them. That is the
          state to aim for — every exception is one more thing to remember.
        </Empty>
      ) : (
        <ul>
          {grants.map((g) => (
            <li
              key={g.id}
              className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-t border-[var(--rule)] px-5 py-3"
            >
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-sm font-[560] text-[var(--ink)]">
                    {g.user_name ?? "Someone"}
                  </span>
                  <span
                    className={`rounded-[var(--r-chip)] px-2 py-0.5 text-xs font-[560] ${
                      g.allow
                        ? "bg-[var(--settled-soft)] text-[var(--settled)]"
                        : "bg-[var(--signal-soft)] text-[var(--signal)]"
                    }`}
                  >
                    {g.allow ? "may" : "may not"}
                  </span>
                  <span className="mono text-xs text-[var(--ink-muted)]">{g.permission}</span>
                  {!g.is_active && (
                    <span className="rounded-[var(--r-chip)] bg-[var(--paper-sunk)] px-2 py-0.5 text-xs text-[var(--ink-faint)]">
                      lapsed
                    </span>
                  )}
                </div>

                {g.reason && (
                  <p className="mt-1 text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
                    {g.reason}
                  </p>
                )}

                <p className="mt-1 text-xs text-[var(--ink-faint)]">
                  {g.granted_by ?? "—"} · {formatDate(g.granted_at)}
                  {g.expires_at && ` · until ${formatDate(g.expires_at)}`}
                </p>
              </div>

              {canManage && (
                <Button
                  variant="danger"
                  onClick={async () => {
                    setActionError(null);
                    try {
                      await api.del(`/circles/${circleId}/grants/${g.id}`);
                      reload();
                    } catch (e) {
                      setActionError(e);
                    }
                  }}
                >
                  Withdraw
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

function GrantForm({
  circleId,
  members,
  onDone,
  onCancel,
}: {
  circleId: string;
  members: Member[];
  onDone: () => void;
  onCancel: () => void;
}) {
  const [userId, setUserId] = useState("");
  const [permission, setPermission] = useState(GRANTABLE[0].value);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  const chosen = GRANTABLE.find((g) => g.value === permission);

  return (
    <div className="space-y-3 rounded-[var(--r-control)] border border-[var(--rule)] bg-[var(--paper-raised)] p-3.5">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Who">
          <select
            value={userId}
            onChange={(e) => setUserId(e.target.value)}
            className={inputClass}
          >
            <option value="">Choose a participant…</option>
            {members.map((m) => (
              <option key={m.membership_id} value={m.user.id}>
                {m.user.name} — {m.circle_role}
                {m.is_external && " (external)"}
              </option>
            ))}
          </select>
        </Field>

        <Field label="What">
          <select
            value={permission}
            onChange={(e) => setPermission(e.target.value)}
            className={inputClass}
          >
            {GRANTABLE.map((g) => (
              <option key={g.value} value={g.value}>
                {g.label}
              </option>
            ))}
          </select>
        </Field>
      </div>

      {chosen && (
        <p className="rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3 py-2 text-xs leading-relaxed text-[var(--ink-muted)]">
          {chosen.note}
        </p>
      )}

      {/*
        Not optional in practice even though the API allows it. A grant with no
        reason is the one somebody finds in a year and cannot decide whether to
        remove.
      */}
      <Field
        label="Why"
        hint="Whoever reads this in a year wasn't there. Tell them why."
      >
        <input
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder="Runs the yard automation for the mobilisation package."
          className={inputClass}
        />
      </Field>

      {!!error && <ErrorNote error={error} />}

      <div className="flex gap-2">
        <Button
          variant="primary"
          disabled={busy || !userId || !reason.trim()}
          onClick={async () => {
            setBusy(true);
            setError(null);
            try {
              await api.post(`/circles/${circleId}/grants`, {
                user_id: userId,
                permission,
                reason,
              });
              onDone();
            } catch (e) {
              setError(e);
            } finally {
              setBusy(false);
            }
          }}
        >
          {busy ? "Granting…" : "Grant it"}
        </Button>
        <Button variant="quiet" onClick={onCancel}>
          Cancel
        </Button>
      </div>

      <p className="text-xs leading-relaxed text-[var(--ink-faint)]">
        You can only grant what you have yourself, and a grant adds this one
        right and nothing else.
      </p>
    </div>
  );
}
