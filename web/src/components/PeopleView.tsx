import { useState } from "react";
import { api, formatDate, type AgentSummaryRow, type Member } from "../lib/api";
import { CircleFrame } from "./CircleFrame";
import { GrantsPanel } from "./Grants";
import { StatusChip } from "./Trust";
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
            circle?.my_access?.permissions.includes("circle.manage_members") === true &&
            !circle?.is_closed
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

  if (loading) return <Panel><Loading what="participants" /></Panel>;
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
        meta={<span className="text-xs text-[var(--ink-faint)]">{data.members.length}</span>}
      >
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-y border-[var(--rule)] bg-[var(--paper-inset)]">
                {["Person", "Role", "Access", "Expires", ""].map((h) => (
                  <th key={h} className="label px-5 py-2 text-left font-[600]">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.members.map((m) => (
                <tr key={m.membership_id} className="border-t border-[var(--rule)]">
                  <td className="px-5 py-3">
                    <span className="display block font-[600]">{m.user.name}</span>
                    <span className="text-xs text-[var(--ink-faint)]">{m.user.email}</span>
                  </td>
                  <td className="px-5 py-3">
                    {canManage && m.is_active ? (
                      <select
                        value={m.circle_role}
                        onChange={(e) => changeRole(m.membership_id, e.target.value)}
                        className={`${filterClass} capitalize`}
                      >
                        {["owner", "approver", "reviewer", "contributor", "viewer"].map((r) => (
                          <option key={r} value={r}>{r}</option>
                        ))}
                      </select>
                    ) : (
                      <span className="text-[0.8125rem] capitalize">{m.circle_role}</span>
                    )}
                  </td>
                  <td className="px-5 py-3">
                    <div className="flex flex-wrap items-center gap-1.5">
                      <StatusChip
                        status={m.is_active ? "active" : m.revoked_at ? "revoked" : m.invite_status}
                        tone={m.is_active ? "settled" : "signal"}
                      />
                      {m.is_external && (
                        <StatusChip status="external" tone="signal" />
                      )}
                    </div>

                    {/*
                      The permission list is the honest answer to "what can this
                      person actually do", but it is twelve lines long — so it
                      collapses, and the count stays visible either way.
                    */}
                    <details className="group mt-1.5">
                      <summary className="label inline-flex cursor-pointer list-none items-center gap-1 rounded-md py-0.5 hover:text-[var(--ink)] [&::-webkit-details-marker]:hidden">
                        <span
                          className="transition-transform duration-200 group-open:rotate-90"
                          aria-hidden="true"
                        >
                          ›
                        </span>
                        {m.permissions.length} permissions
                      </summary>
                      <ul className="mt-1.5 space-y-1 rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3 py-2">
                        {m.permissions.map((p) => (
                          <li key={p} className="mono text-[0.6875rem] text-[var(--ink-muted)]">
                            {p}
                          </li>
                        ))}
                      </ul>
                    </details>
                  </td>
                  <td className="px-5 py-3 text-[0.8125rem] text-[var(--ink-faint)]">
                    {m.expires_at ? formatDate(m.expires_at) : "No expiry"}
                  </td>
                  <td className="px-5 py-3 text-right">
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

      <GrantsPanel circleId={circleId} canManage={canManage} members={data.members} />

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
        <Panel title="Invitation issued">
          <div className="px-5 py-3.5">
            <p className="text-sm">
              Send this link to <span className="font-[560]">{issued.email}</span>. It can only
              be redeemed by that address, and it expires in 14 days.
            </p>
            <p className="mt-2 break-all">
              <Copyable value={`${location.origin}/invitations/${issued.token}`} />
            </p>
            <p className="mt-2 text-xs text-[var(--ink-muted)]">
              Shown once. The MVP has no mailer wired up, so deliver it yourself.
            </p>
          </div>
        </Panel>
      )}

      {data.pending_invitations.length > 0 && (
        <Panel title="Invited, not yet accepted">
          <ul>
            {data.pending_invitations.map((i) => (
              <li key={i.id} className="flex flex-wrap items-baseline justify-between gap-3 border-t border-[var(--rule)] px-5 py-3">
                <span className="text-[0.875rem]">{i.email}</span>
                <span className="text-xs text-[var(--ink-faint)]">
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
          meta={<span className="text-xs text-[var(--ink-faint)]">{agent.status}</span>}
        >
          <div className="px-5 pb-5">
            <h3 className="display text-[1.0625rem] font-[620]">{agent.name}</h3>
            <p className="mt-0.5 text-xs text-[var(--ink-faint)]">
              {agent.blueprint} · v{agent.version}
            </p>
            <p className="mt-2.5 max-w-3xl text-sm leading-relaxed text-[var(--ink-muted)]">
              {agent.mandate}
            </p>

            {/*
              May and may-not sit side by side at equal weight. Showing only the
              permissions would answer half the question people actually ask.
            */}
            <div className="mt-4 grid gap-3 md:grid-cols-2">
              <Capability
                title="It may"
                items={agent.allowed_actions}
                tone="settled"
              />
              <Capability
                title="It may not"
                items={agent.prohibited_actions.map((a) => a.replace(/_/g, " "))}
                tone="signal"
              />
            </div>

            <div className="mt-4">
              <p className="label">
                What this agent can access ({agent.can_access.length} item
                {agent.can_access.length === 1 ? "" : "s"})
              </p>
              {agent.can_access.length === 0 ? (
                <p className="mt-1.5 text-sm text-[var(--ink-muted)]">
                  Nothing. No evidence in this Circle is marked agent-readable.
                </p>
              ) : (
                <ul className="mt-1.5 space-y-1">
                  {agent.can_access.map((r) => (
                    <li key={r.evidence_item_id} className="flex gap-2 text-sm">
                      <span className="text-[var(--derived)]" aria-hidden="true">•</span>
                      {r.name}
                    </li>
                  ))}
                </ul>
              )}
              <p className="mt-3 text-xs leading-relaxed text-[var(--ink-faint)]">
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
      <div className="space-y-4 px-5 pb-5">
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
            <select
              value={role}
              onChange={(e) => setRole(e.target.value)}
              className={`${inputClass} capitalize`}
            >
              {["viewer", "contributor", "reviewer", "approver", "owner"].map((r) => (
                <option key={r} value={r}>{r}</option>
              ))}
            </select>
          </Field>
          <Field label="Outside the organisation?">
            <label className="flex h-[34px] items-center gap-2">
              <input
                type="checkbox"
                checked={external}
                onChange={(e) => setExternal(e.target.checked)}
                className="accent-[var(--signal)]"
              />
              <span className="text-sm">External</span>
            </label>
          </Field>
        </div>

        <p className="text-xs leading-snug text-[var(--ink-muted)]">
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

/**
 * One half of the agent's capability pair. Rendered as a tinted block rather
 * than a bare list so that "may" and "may not" are told apart before either
 * one is read.
 */
function Capability({
  title,
  items,
  tone,
}: {
  title: string;
  items: string[];
  tone: "settled" | "signal";
}) {
  const colour = tone === "settled" ? "var(--settled)" : "var(--signal)";

  return (
    <div className="rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3.5 py-3">
      <p className="label" style={{ color: colour }}>
        {title}
      </p>
      <ul className="mt-1.5 space-y-1">
        {items.map((a) => (
          <li key={a} className="text-[0.8125rem] leading-snug text-[var(--ink-muted)]">
            {a}
          </li>
        ))}
      </ul>
    </div>
  );
}
