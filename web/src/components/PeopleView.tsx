import { useState } from "react";
import { api, formatDate, type AgentSummaryRow, type Member } from "../lib/api";
import { CircleFrame } from "./CircleFrame";
import {
  Button,
  Copyable,
  ErrorNote,
  Field,
  Loading,
  Panel,
  filterClass,
  inputClass,
  useAsync,
} from "./ui";

interface PeoplePayload {
  members: Member[];
  pending_invitations: Array<{
    id: string; email: string; circle_role: string; is_external: boolean; expires_at: string | null;
  }>;
  agents: AgentSummaryRow[];
}

/**
 * People and agents (spec §12).
 *
 * The agent is listed alongside the people on purpose, with its mandate and the
 * exact list of what it can reach. "What can this thing see?" should be
 * answerable by looking, not by trusting a description.
 */
export function PeopleView({ circleId }: { circleId: string }) {
  return (
    <CircleFrame circleId={circleId} tab="people">
      {(circle) => (
        <Body
          circleId={circleId}
          canManage={
            circle.my_access?.permissions.includes("circle.manage_members") === true &&
            !circle.is_closed
          }
        />
      )}
    </CircleFrame>
  );
}

function Body({ circleId, canManage }: { circleId: string; canManage: boolean }) {
  const { data, error, loading, reload } = useAsync<PeoplePayload>(
    () => api.get<{ data: PeoplePayload }>(`/circles/${circleId}/members`).then((r) => r.data),
    [circleId],
  );
  const [actionError, setActionError] = useState<unknown>(null);
  const [issued, setIssued] = useState<{ email: string; token: string } | null>(null);

  if (loading) return <Loading what="participants" />;
  if (error) return <ErrorNote error={error} />;
  if (!data) return null;

  async function revoke(membershipId: string) {
    setActionError(null);
    try {
      await api.del(`/circles/${circleId}/members/${membershipId}`);
      reload();
    } catch (e) {
      setActionError(e);
    }
  }

  async function changeRole(membershipId: string, role: string) {
    setActionError(null);
    try {
      await api.patch(`/circles/${circleId}/members/${membershipId}`, { circle_role: role });
      reload();
    } catch (e) {
      setActionError(e);
    }
  }

  return (
    <div className="space-y-5">
      {!!actionError && <ErrorNote error={actionError} />}

      <Panel
        title="Participants"
        meta={<span className="mono text-xs text-[var(--ink-faint)]">{data.members.length}</span>}
      >
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-[var(--rule)]">
                {["Person", "Role", "Access", "Joined", ""].map((h) => (
                  <th key={h} className="label px-4 py-2 text-left font-600">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.members.map((m) => (
                <tr key={m.membership_id} className="border-b border-[var(--rule)] last:border-0">
                  <td className="px-4 py-2.5">
                    <span className="display block font-600">{m.user.name}</span>
                    <span className="mono text-[0.6875rem] text-[var(--ink-faint)]">{m.user.email}</span>
                  </td>
                  <td className="px-4 py-2.5">
                    {canManage && m.is_active ? (
                      <select
                        value={m.circle_role}
                        onChange={(e) => changeRole(m.membership_id, e.target.value)}
                        className={`${filterClass} py-1 mono !text-xs`}
                      >
                        {["owner", "approver", "reviewer", "contributor", "viewer"].map((r) => (
                          <option key={r} value={r}>{r}</option>
                        ))}
                      </select>
                    ) : (
                      <span className="mono text-xs">{m.circle_role}</span>
                    )}
                  </td>
                  <td className="px-4 py-2.5">
                    <span className={`mono text-xs ${m.is_active ? "" : "text-[var(--signal)]"}`}>
                      {m.is_active ? "active" : m.revoked_at ? "revoked" : m.invite_status}
                    </span>
                    {m.is_external && (
                      <span className="ml-2 mono text-[0.6875rem] text-[var(--signal)]" title="External collaborators cannot download or share by default, and lose access entirely when the Circle closes.">
                        external
                      </span>
                    )}
                    <details className="mt-0.5">
                      <summary className="label cursor-pointer">
                        {m.permissions.length} permissions
                      </summary>
                      <ul className="mt-1 space-y-0.5">
                        {m.permissions.map((p) => (
                          <li key={p} className="mono text-[0.625rem] text-[var(--ink-faint)]">{p}</li>
                        ))}
                      </ul>
                    </details>
                  </td>
                  <td className="px-4 py-2.5 mono text-xs text-[var(--ink-faint)]">
                    {m.expires_at ? `until ${formatDate(m.expires_at)}` : "no expiry"}
                  </td>
                  <td className="px-4 py-2.5 text-right">
                    {canManage && m.is_active && (
                      <Button variant="quiet" onClick={() => revoke(m.membership_id)}>
                        Revoke
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>

      {canManage && (
        <InviteForm
          circleId={circleId}
          onInvited={(email, token) => {
            setIssued({ email, token });
            reload();
          }}
        />
      )}

      {issued && (
        <Panel title="Invitation issued" tone="signal">
          <div className="px-4 py-3">
            <p className="text-sm">
              Send this link to <span className="mono text-xs">{issued.email}</span>. It can only
              be redeemed by that address, and it expires in 14 days.
            </p>
            <p className="mt-2 break-all mono text-xs">
              <Copyable value={`${location.origin}/invitations/${issued.token}`} />
            </p>
            <p className="mt-2 text-xs italic text-[var(--ink-muted)]">
              Shown once. The MVP has no mailer wired up, so deliver it yourself.
            </p>
          </div>
        </Panel>
      )}

      {data.pending_invitations.length > 0 && (
        <Panel title="Invited, not yet accepted">
          <ul>
            {data.pending_invitations.map((i) => (
              <li key={i.id} className="flex flex-wrap items-baseline justify-between gap-3 border-b border-[var(--rule)] px-4 py-2.5 last:border-0">
                <span className="mono text-xs">{i.email}</span>
                <span className="mono text-[0.6875rem] text-[var(--ink-faint)]">
                  {i.circle_role}{i.is_external && " · external"} · expires {formatDate(i.expires_at)}
                </span>
              </li>
            ))}
          </ul>
        </Panel>
      )}

      {data.agents.map((agent) => (
        <Panel
          key={agent.agent_instance_id}
          title="Agent"
          tone="derived"
          meta={<span className="mono text-xs text-[var(--ink-faint)]">{agent.status}</span>}
        >
          <div className="hatch h-1 opacity-40" />
          <div className="px-4 py-4">
            <h3 className="display text-base font-700">{agent.name}</h3>
            <p className="mono text-[0.6875rem] text-[var(--ink-faint)]">
              {agent.blueprint} · v{agent.version}
            </p>
            <p className="mt-2 max-w-3xl text-sm leading-snug text-[var(--ink-muted)]">
              {agent.mandate}
            </p>

            <div className="mt-4 grid gap-5 md:grid-cols-2">
              <div>
                <p className="label">It may</p>
                <ul className="mt-1 space-y-0.5">
                  {agent.allowed_actions.map((a) => (
                    <li key={a} className="mono text-xs text-[var(--ink)]">{a}</li>
                  ))}
                </ul>
              </div>
              <div>
                <p className="label !text-[var(--signal)]">It may not</p>
                <ul className="mt-1 space-y-0.5">
                  {agent.prohibited_actions.map((a) => (
                    <li key={a} className="mono text-xs text-[var(--ink-muted)]">
                      {a.replace(/_/g, " ")}
                    </li>
                  ))}
                </ul>
              </div>
            </div>

            <div className="mt-4">
              <p className="label">
                What this agent can access ({agent.can_access.length} item
                {agent.can_access.length === 1 ? "" : "s"})
              </p>
              {agent.can_access.length === 0 ? (
                <p className="mt-1 text-sm italic text-[var(--ink-muted)]">
                  Nothing. No evidence in this Circle is marked agent-readable.
                </p>
              ) : (
                <ul className="mt-1 space-y-0.5">
                  {agent.can_access.map((r) => (
                    <li key={r.evidence_item_id} className="text-sm">
                      — {r.name}
                    </li>
                  ))}
                </ul>
              )}
              <p className="mt-2 text-xs italic leading-snug text-[var(--ink-muted)]">
                Access is granted per item, never inherited. Everything the agent
                produces is a draft that a person must confirm.
              </p>
            </div>
          </div>
        </Panel>
      ))}
    </div>
  );
}

function InviteForm({
  circleId,
  onInvited,
}: {
  circleId: string;
  onInvited: (email: string, token: string) => void;
}) {
  const [email, setEmail] = useState("");
  const [role, setRole] = useState("contributor");
  const [external, setExternal] = useState(true);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      const res = await api.post<{ data: { accept_token: string } }>(
        `/circles/${circleId}/invitations`,
        { email, circle_role: role, is_external: external },
      );
      onInvited(email, res.data.accept_token);
      setEmail("");
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Panel title="Invite someone">
      <div className="space-y-3 px-4 py-4">
        <div className="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]">
          <Field label="Email">
            <input
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              type="email"
              className={inputClass}
              placeholder="name@example.com"
            />
          </Field>
          <Field label="Role">
            <select value={role} onChange={(e) => setRole(e.target.value)} className={inputClass}>
              {["viewer", "contributor", "reviewer", "approver", "owner"].map((r) => (
                <option key={r} value={r}>{r}</option>
              ))}
            </select>
          </Field>
          <Field label="Outside JWA?">
            <label className="flex h-[34px] items-center gap-2">
              <input
                type="checkbox"
                checked={external}
                onChange={(e) => setExternal(e.target.checked)}
                className="accent-[var(--signal)]"
              />
              <span className="mono text-xs">external</span>
            </label>
          </Field>
        </div>

        <p className="text-xs italic leading-snug text-[var(--ink-muted)]">
          External collaborators see only this Circle. Download and sharing are
          denied to them by default and must be granted per item, and closing the
          Circle revokes their access entirely.
        </p>

        {!!error && <ErrorNote error={error} />}

        <Button variant="primary" onClick={submit} disabled={busy || !email.includes("@")}>
          {busy ? "Issuing…" : "Issue invitation"}
        </Button>
      </div>
    </Panel>
  );
}
