import { useState } from "react";
import {
  api,
  formatDate,
  relativeDays,
  type AgentActionRow,
  type AgentBlueprintRow,
  type AgentConnectionRow,
  type AgentToolRow,
  type Circle,
  type Party,
  type SideEffect,
} from "../lib/api";
import { CircleFrame } from "./CircleFrame";
import {
  Button,
  Empty,
  ErrorNote,
  Field,
  Loading,
  Meta,
  Panel,
  inputClass,
  useAsync,
} from "./ui";

/**
 * Agents — building them, admitting other companies', and approving what they
 * want to do.
 *
 * The queue is first on the page rather than the roster, because an agent
 * waiting on a human is the only thing here that blocks work. Everything about
 * this screen is arranged around one claim being legible: an agent proposes,
 * and a side effect needs a person who carries it.
 */
export function AgentsView({ circleId }: { circleId: string }) {
  return (
    <CircleFrame circleId={circleId} tab="agents">
      {(circle) => <Body circleId={circleId} circle={circle} />}
    </CircleFrame>
  );
}

function Body({ circleId, circle }: { circleId: string; circle: Circle | null }) {
  const perms = circle?.my_access?.permissions ?? [];
  const canAuthor = perms.includes("agent.author") && !circle?.is_closed;
  const canConnect = perms.includes("agent.connect") && !circle?.is_closed;
  const canApprove = perms.includes("agent.approve") && !circle?.is_closed;

  const [building, setBuilding] = useState(false);
  const [importing, setImporting] = useState(false);

  const queue = useAsync<{ data: AgentActionRow[]; recent: AgentActionRow[] }>(
    () =>
      api.get<{ data: AgentActionRow[]; recent: AgentActionRow[] }>(
        `/circles/${circleId}/agent-actions`,
      ),
    [circleId],
  );
  const agents = useAsync<AgentBlueprintRow[]>(
    () => api.get<{ data: AgentBlueprintRow[] }>(`/circles/${circleId}/agents`).then((r) => r.data),
    [circleId],
  );
  const connections = useAsync<AgentConnectionRow[]>(
    () =>
      api
        .get<{ data: AgentConnectionRow[] }>(`/circles/${circleId}/agent-connections`)
        .then((r) => r.data),
    [circleId],
  );
  const { data: parties } = useAsync<Party[]>(
    () => api.get<{ data: Party[] }>(`/circles/${circleId}/parties`).then((r) => r.data),
    [circleId],
  );

  if (queue.loading || agents.loading) {
    return (
      <Panel>
        <Loading what="agents" />
      </Panel>
    );
  }
  if (queue.error) return <ErrorNote error={queue.error} />;
  if (agents.error) return <ErrorNote error={agents.error} />;

  const pending = queue.data?.data ?? [];
  const recent = queue.data?.recent ?? [];
  const roster = agents.data ?? [];

  return (
    <div className="space-y-5">
      {/* Waiting on a person — the only blocking thing on this screen. */}
      <Panel
        title="Waiting for a person"
        tone={pending.length > 0 ? "signal" : "default"}
        className="lay-in"
        meta={<Meta>{pending.length} to decide</Meta>}
      >
        {pending.length === 0 ? (
          <Empty>No agent is waiting on anyone.</Empty>
        ) : (
          <ul>
            {pending.map((a) => (
              <ActionRow
                key={a.id}
                action={a}
                canApprove={canApprove}
                onChanged={() => {
                  queue.reload();
                }}
              />
            ))}
          </ul>
        )}
      </Panel>

      <div className="grid gap-5 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <div className="space-y-5">
          <Panel
            title="Agents in this Circle"
            className="lay-in"
            meta={
              canAuthor && (
                <Button
                  variant={building ? "quiet" : "primary"}
                  onClick={() => setBuilding((v) => !v)}
                >
                  {building ? "Cancel" : "Build an agent"}
                </Button>
              )
            }
          >
            {building && (
              <div className="px-5 pb-5">
                <AgentComposer
                  circleId={circleId}
                  onDone={() => {
                    setBuilding(false);
                    agents.reload();
                  }}
                  onCancel={() => setBuilding(false)}
                />
              </div>
            )}

            {roster.length === 0 ? (
              <Empty>No agents yet.</Empty>
            ) : (
              <ul>
                {roster.map((b) => (
                  <AgentRow
                    key={b.id}
                    blueprint={b}
                    circleId={circleId}
                    canAuthor={canAuthor}
                    onChanged={agents.reload}
                  />
                ))}
              </ul>
            )}
          </Panel>

          <Panel title="Recent agent activity" className="lay-in">
            {recent.length === 0 ? (
              <Empty>Nothing yet.</Empty>
            ) : (
              <ul>
                {recent.map((a) => (
                  <li
                    key={a.id}
                    className="border-t border-[var(--rule)] px-5 py-3 first:border-t-0"
                  >
                    <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                      <span className="text-sm text-[var(--ink)]">{a.tool_name}</span>
                      <EffectChip effect={a.side_effect} />
                      <ActionStatusChip status={a.status} />
                      <span className="ml-auto text-xs text-[var(--ink-faint)]">
                        {formatDate(a.executed_at ?? a.created_at, true)}
                      </span>
                    </div>
                    <p className="mt-1 text-xs text-[var(--ink-faint)]">
                      {a.agent ?? "Agent"}
                      {a.on_behalf_of && ` · for ${a.on_behalf_of}`}
                      {a.approved_by && ` · approved by ${a.approved_by}`}
                    </p>
                    {a.rejection_reason && (
                      <p className="mt-1 text-xs text-[var(--signal)]">
                        Refused: {a.rejection_reason}
                      </p>
                    )}
                    {a.error && (
                      <p className="mt-1 text-xs text-[var(--signal)]">Failed: {a.error}</p>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </div>

        <div className="space-y-5">
          {/* Another company's agent, admitted by key rather than by name. */}
          <Panel
            title="Agents from other parties"
            className="lay-in"
            meta={
              canConnect && (
                <Button
                  variant={importing ? "quiet" : "quiet"}
                  onClick={() => setImporting((v) => !v)}
                >
                  {importing ? "Cancel" : "Bring one in"}
                </Button>
              )
            }
          >
            {importing && (
              <div className="px-5 pb-5">
                <ConnectionComposer
                  circleId={circleId}
                  parties={parties ?? []}
                  onDone={() => {
                    setImporting(false);
                    connections.reload();
                  }}
                  onCancel={() => setImporting(false)}
                />
              </div>
            )}

            {(connections.data ?? []).length === 0 ? (
              <Empty>
                None. A party can bring its own agent — it runs on their
                credentials, and Circle never holds the secret.
              </Empty>
            ) : (
              <ul>
                {(connections.data ?? []).map((c) => (
                  <li
                    key={c.id}
                    className="border-t border-[var(--rule)] px-5 py-3 first:border-t-0"
                  >
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-sm font-[560]">{c.name}</span>
                      <span
                        className={`rounded-[var(--r-chip)] px-2 py-0.5 text-xs font-[560] ${
                          c.is_admitted
                            ? "bg-[var(--settled-soft)] text-[var(--settled)]"
                            : "bg-[var(--paper-sunk)] text-[var(--ink-muted)]"
                        }`}
                      >
                        {c.is_admitted ? "Admitted" : c.status}
                      </span>
                    </div>
                    <p className="mt-1 text-xs text-[var(--ink-faint)]">
                      {c.party ?? "—"} · {c.auth_mode.replace(/_/g, " ")}
                    </p>
                    {c.fingerprint && (
                      <p
                        className="mono mt-1 text-xs text-[var(--ink-faint)]"
                        title="Approving this agent approves this specific key."
                      >
                        {c.fingerprint}…
                      </p>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          <Panel title="How this works" className="lay-in">
            <div className="space-y-3 px-5 pb-5 text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
              <p>
                An agent has a <strong className="text-[var(--ink)]">mode</strong>{" "}
                that bounds everything it can do, whatever its tools say.
              </p>
              <ul className="space-y-1.5">
                <li>
                  <ModeChip mode="read_only" /> reads, writes nothing.
                </li>
                <li>
                  <ModeChip mode="propose" /> drafts claims, goals and decision
                  requests for people to review.
                </li>
                <li>
                  <ModeChip mode="execute" /> runs commands — each one recorded,
                  and anything with a side effect held until a person with the
                  right authority agrees.
                </li>
              </ul>
              <p className="border-t border-[var(--rule)] pt-3">
                Dropping an agent to read-only stops everything it could do,
                immediately, without editing its tools.
              </p>
            </div>
          </Panel>
        </div>
      </div>
    </div>
  );
}

/**
 * One proposal, with everything a person needs to refuse it intelligently:
 * what it wants to do, what class of harm it is in, whose behalf it acts on,
 * and who is allowed to say yes.
 */
function ActionRow({
  action,
  canApprove,
  onChanged,
}: {
  action: AgentActionRow;
  canApprove: boolean;
  onChanged: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [note, setNote] = useState("");

  async function act(path: string, body: Record<string, unknown>) {
    setBusy(true);
    setError(null);
    try {
      await api.post(`/agent-actions/${action.id}/${path}`, body);
      onChanged();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <li className="border-t border-[var(--rule)] px-5 py-4 first:border-t-0">
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-[0.9375rem] font-[590] text-[var(--ink)]">
              {action.tool_name}
            </span>
            <EffectChip effect={action.side_effect} />
          </div>

          {action.intent && (
            <p className="mt-1.5 text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
              {action.intent}
            </p>
          )}

          <p className="mt-1.5 text-xs text-[var(--ink-faint)]">
            {action.agent ?? "Agent"}
            {action.on_behalf_of && (
              <>
                {" · acting for "}
                <span className="font-[560] text-[var(--ink-muted)]">
                  {action.on_behalf_of}
                </span>
              </>
            )}
          </p>
        </div>

        <span className="shrink-0 text-xs text-[var(--ink-faint)]">
          {action.expires_at && <>expires {relativeDays(action.expires_at)}</>}
        </span>
      </div>

      {/* The exact arguments. Approving a summary of an action is not approving
          the action. */}
      {action.arguments && Object.keys(action.arguments).length > 0 && (
        <dl className="mono mt-2.5 grid gap-x-4 gap-y-1 rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3 py-2.5 text-xs sm:grid-cols-[auto_1fr]">
          {Object.entries(action.arguments).map(([k, v]) => (
            <div key={k} className="contents">
              <dt className="text-[var(--ink-faint)]">{k}</dt>
              <dd className="break-all text-[var(--ink)]">{JSON.stringify(v)}</dd>
            </div>
          ))}
        </dl>
      )}

      {action.needs_role && (
        <p className="mt-2 text-xs text-[var(--ink-muted)]">
          Needs {action.needs_role === "owner" ? "an owner" : `a ${action.needs_role}`}
          {action.owning_party_only && action.on_behalf_of && (
            <> from {action.on_behalf_of}</>
          )}{" "}
          to agree.
        </p>
      )}

      {!!error && (
        <div className="mt-2">
          <ErrorNote error={error} />
        </div>
      )}

      {canApprove && (
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <input
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder="Reason for the record (optional)"
            className={`${inputClass} !max-w-sm !text-[0.8125rem]`}
          />
          <Button
            variant="primary"
            disabled={busy}
            onClick={() => act("approve", { note: note || null })}
          >
            {busy ? "…" : "Approve"}
          </Button>
          <Button
            variant="danger"
            disabled={busy}
            onClick={() => act("reject", { reason: note || null })}
          >
            Refuse
          </Button>
        </div>
      )}
    </li>
  );
}

function AgentRow({
  blueprint,
  circleId,
  canAuthor,
  onChanged,
}: {
  blueprint: AgentBlueprintRow;
  circleId: string;
  canAuthor: boolean;
  onChanged: () => void;
}) {
  const [open, setOpen] = useState(false);
  const [addingTool, setAddingTool] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  async function act(fn: () => Promise<unknown>) {
    setBusy(true);
    setError(null);
    try {
      await fn();
      onChanged();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  const suspended = blueprint.status !== "active";

  return (
    <li className="border-t border-[var(--rule)] px-5 py-3.5 first:border-t-0">
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <span
              className={`text-[0.9375rem] font-[590] ${
                suspended ? "text-[var(--ink-faint)] line-through" : "text-[var(--ink)]"
              }`}
            >
              {blueprint.name}
            </span>
            <ModeChip mode={blueprint.execution_mode} />
            {blueprint.is_system && (
              <span
                className="rounded-[var(--r-chip)] bg-[var(--derived-soft)] px-2 py-0.5 text-xs font-[560] text-[var(--derived)]"
                title="Shipped with Circle. Its mandate is part of the product, not a setting."
              >
                Built in
              </span>
            )}
            {blueprint.circle_scoped && (
              <span className="rounded-[var(--r-chip)] bg-[var(--paper-sunk)] px-2 py-0.5 text-xs font-[560] text-[var(--ink-muted)]">
                This Circle only
              </span>
            )}
          </div>

          <p className="mt-1 text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
            {blueprint.mandate}
          </p>
        </div>

        <span className="flex shrink-0 items-center gap-1">
          {canAuthor && !blueprint.is_system && (
            <Button
              variant={suspended ? "quiet" : "danger"}
              disabled={busy}
              title={
                suspended
                  ? "Let this agent run again."
                  : "Stop everything this agent can do, immediately."
              }
              onClick={() =>
                act(() =>
                  api.patch(`/circles/${circleId}/agents/${blueprint.id}`, {
                    status: suspended ? "active" : "suspended",
                  }),
                )
              }
            >
              {suspended ? "Resume" : "Suspend"}
            </Button>
          )}
          {canAuthor && !blueprint.is_running_here && (
            <Button
              disabled={busy}
              onClick={() =>
                act(() =>
                  api.post(`/circles/${circleId}/agents/${blueprint.id}/instantiate`),
                )
              }
            >
              Put to work
            </Button>
          )}
          <button
            onClick={() => setOpen((v) => !v)}
            className="rounded-[var(--r-control)] px-2 py-1 text-xs text-[var(--ink-muted)] hover:bg-[var(--paper-sunk)]"
          >
            {open ? "Hide" : "Details"}
          </button>
        </span>
      </div>

      {!!error && (
        <div className="mt-2">
          <ErrorNote error={error} />
        </div>
      )}

      {open && (
        <div className="mt-3 space-y-3 rounded-[var(--r-control)] bg-[var(--paper-inset)] p-3.5">
          {/*
            What it can actually do, not what it asked for. A blueprint may
            declare more than its mode allows; only this list is real.
          */}
          <div>
            <p className="label">What it can do</p>
            <div className="mt-1.5 flex flex-wrap gap-1.5">
              {blueprint.permissions.length === 0 ? (
                <span className="text-xs text-[var(--ink-faint)]">Nothing.</span>
              ) : (
                blueprint.permissions.map((p) => (
                  <span
                    key={p}
                    className="mono rounded-[var(--r-chip)] bg-[var(--paper-sunk)] px-2 py-0.5 text-xs text-[var(--ink-muted)]"
                  >
                    {p}
                  </span>
                ))
              )}
            </div>
            {blueprint.declared.length > blueprint.permissions.length && (
              <p className="mt-1.5 text-xs text-[var(--ink-faint)]">
                It asked for {blueprint.declared.length} permissions; its mode
                allows {blueprint.permissions.length}.
              </p>
            )}
          </div>

          <div>
            <p className="label">Commands</p>
            {blueprint.tools.length === 0 ? (
              <p className="mt-1.5 text-xs text-[var(--ink-faint)]">
                None. This agent can only read and draft.
              </p>
            ) : (
              <ul className="mt-1.5 space-y-1.5">
                {blueprint.tools.map((t) => (
                  <ToolLine key={t.id} tool={t} />
                ))}
              </ul>
            )}
          </div>

          {canAuthor && !blueprint.is_system && blueprint.execution_mode === "execute" && (
            <div>
              {addingTool ? (
                <ToolComposer
                  circleId={circleId}
                  blueprintId={blueprint.id}
                  onDone={() => {
                    setAddingTool(false);
                    onChanged();
                  }}
                  onCancel={() => setAddingTool(false)}
                />
              ) : (
                <Button variant="quiet" onClick={() => setAddingTool(true)}>
                  Add a command
                </Button>
              )}
            </div>
          )}
        </div>
      )}
    </li>
  );
}

function ToolLine({ tool }: { tool: AgentToolRow }) {
  return (
    <li className="flex flex-wrap items-center gap-2 text-[0.8125rem]">
      <span className="mono text-xs text-[var(--ink)]">{tool.key}</span>
      <EffectChip effect={tool.side_effect} />
      {tool.needs_approval ? (
        <span className="text-xs text-[var(--ink-muted)]">
          needs {tool.approval_role === "owner" ? "an owner" : `a ${tool.approval_role}`}
          {tool.owning_party_only && " from the party it acts for"}
        </span>
      ) : (
        <span className="text-xs text-[var(--ink-faint)]">runs freely</span>
      )}
    </li>
  );
}

function AgentComposer({
  circleId,
  onDone,
  onCancel,
}: {
  circleId: string;
  onDone: () => void;
  onCancel: () => void;
}) {
  const [name, setName] = useState("");
  const [mandate, setMandate] = useState("");
  const [instructions, setInstructions] = useState("");
  const [mode, setMode] = useState<"read_only" | "propose" | "execute">("propose");
  const [circleScoped, setCircleScoped] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  return (
    <div className="space-y-3 rounded-[var(--r-control)] border border-[var(--rule)] bg-[var(--paper-inset)] p-4">
      <Field label="Name">
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          autoFocus
          placeholder="Geotech Reviewer"
          className={inputClass}
        />
      </Field>

      <Field
        label="What is it for?"
        hint="Shown to every party, including the ones whose evidence it reads."
      >
        <textarea
          value={mandate}
          onChange={(e) => setMandate(e.target.value)}
          rows={2}
          placeholder="Reads geotechnical reports and flags where they contradict the load schedule."
          className={inputClass}
        />
      </Field>

      <Field label="How far can it go?">
        <div className="grid gap-2 sm:grid-cols-3">
          <ModeOption
            active={mode === "read_only"}
            onClick={() => setMode("read_only")}
            label="Read only"
            detail="Reads what it is shown. Writes nothing."
          />
          <ModeOption
            active={mode === "propose"}
            onClick={() => setMode("propose")}
            label="Propose"
            detail="Drafts claims, goals and decisions for people to review."
          />
          <ModeOption
            active={mode === "execute"}
            onClick={() => setMode("execute")}
            label="Execute"
            detail="Runs commands. Side effects wait for a person."
          />
        </div>
      </Field>

      <Field
        label="Instructions"
        hint="Optional. The standing brief it works from."
      >
        <textarea
          value={instructions}
          onChange={(e) => setInstructions(e.target.value)}
          rows={3}
          placeholder="Cite the page for every finding. Say when a document does not answer the question."
          className={inputClass}
        />
      </Field>

      <label className="flex items-start gap-2 text-[0.8125rem] text-[var(--ink-muted)]">
        <input
          type="checkbox"
          checked={circleScoped}
          onChange={(e) => setCircleScoped(e.target.checked)}
          className="mt-0.5"
        />
        <span>
          Keep it to this Circle
          <span className="block text-xs text-[var(--ink-faint)]">
            Otherwise your organisation can run it in any of its Circles.
          </span>
        </span>
      </label>

      {!!error && <ErrorNote error={error} />}

      <div className="flex gap-2">
        <Button
          variant="primary"
          disabled={busy || !name.trim() || !mandate.trim()}
          onClick={async () => {
            setBusy(true);
            setError(null);
            try {
              await api.post(`/circles/${circleId}/agents`, {
                name,
                mandate,
                instructions: instructions || null,
                execution_mode: mode,
                circle_scoped: circleScoped,
              });
              onDone();
            } catch (e) {
              setError(e);
            } finally {
              setBusy(false);
            }
          }}
        >
          {busy ? "Building…" : "Build it"}
        </Button>
        <Button variant="quiet" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

function ToolComposer({
  circleId,
  blueprintId,
  onDone,
  onCancel,
}: {
  circleId: string;
  blueprintId: string;
  onDone: () => void;
  onCancel: () => void;
}) {
  const [key, setKey] = useState("");
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [effect, setEffect] = useState<SideEffect>("circle_write");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  return (
    <div className="space-y-3 rounded-[var(--r-control)] border border-[var(--rule)] bg-[var(--paper)] p-3.5">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Key" hint="lowercase_with_underscores">
          <input
            value={key}
            onChange={(e) => setKey(e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, "_"))}
            placeholder="notify_yard"
            className={`${inputClass} mono !text-sm`}
          />
        </Field>
        <Field label="Name">
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Notify the yard"
            className={inputClass}
          />
        </Field>
      </div>

      <Field label="What does it do?">
        <input
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          placeholder="Sends the yard a mobilisation notice."
          className={inputClass}
        />
      </Field>

      {/*
        Classified by consequence, not by name. The approval rules follow from
        this choice and cannot be weakened afterwards.
      */}
      <Field
        label="If this goes wrong, what happens?"
        hint="This decides who has to approve it. You cannot make it looser later."
      >
        <select
          value={effect}
          onChange={(e) => setEffect(e.target.value as SideEffect)}
          className={inputClass}
        >
          <option value="none">Nothing — it only reads and computes</option>
          <option value="circle_write">It changes something in this Circle</option>
          <option value="external_read">It reads something outside the Circle</option>
          <option value="external_write">It writes something outside the Circle</option>
          <option value="financial">Money, or a binding commercial position</option>
        </select>
      </Field>

      <p className="rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3 py-2 text-xs leading-relaxed text-[var(--ink-muted)]">
        {effect === "none"
          ? "Runs without asking anyone."
          : effect === "external_write" || effect === "financial"
            ? "Every call waits for an owner from the party it acts for."
            : "Every call waits for a reviewer or better."}
      </p>

      {!!error && <ErrorNote error={error} />}

      <div className="flex gap-2">
        <Button
          variant="primary"
          disabled={busy || !key.trim() || !name.trim() || !description.trim()}
          onClick={async () => {
            setBusy(true);
            setError(null);
            try {
              await api.post(`/circles/${circleId}/agents/${blueprintId}/tools`, {
                key,
                name,
                description,
                side_effect: effect,
              });
              onDone();
            } catch (e) {
              setError(e);
            } finally {
              setBusy(false);
            }
          }}
        >
          {busy ? "Adding…" : "Add command"}
        </Button>
        <Button variant="quiet" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

function ConnectionComposer({
  circleId,
  parties,
  onDone,
  onCancel,
}: {
  circleId: string;
  parties: Party[];
  onDone: () => void;
  onCancel: () => void;
}) {
  const [partyId, setPartyId] = useState("");
  const [name, setName] = useState("");
  const [authMode, setAuthMode] = useState("signed_webhook");
  const [endpoint, setEndpoint] = useState("");
  const [fingerprint, setFingerprint] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  return (
    <div className="space-y-3 rounded-[var(--r-control)] border border-[var(--rule)] bg-[var(--paper-inset)] p-3.5">
      <Field label="Whose agent is it?">
        <select value={partyId} onChange={(e) => setPartyId(e.target.value)} className={inputClass}>
          <option value="">choose a party…</option>
          {parties.map((p) => (
            <option key={p.id} value={p.id}>
              {p.label}
            </option>
          ))}
        </select>
      </Field>

      <Field label="Name">
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Groundworks Estimator"
          className={inputClass}
        />
      </Field>

      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="How does it connect?">
          <select
            value={authMode}
            onChange={(e) => setAuthMode(e.target.value)}
            className={inputClass}
          >
            <option value="signed_webhook">Signed webhook</option>
            <option value="mcp">MCP server</option>
            <option value="delegated_token">Delegated token</option>
          </select>
        </Field>
        <Field label="Key fingerprint" hint="What the other parties approve.">
          <input
            value={fingerprint}
            onChange={(e) => setFingerprint(e.target.value)}
            placeholder="SHA256:…"
            className={`${inputClass} mono !text-sm`}
          />
        </Field>
      </div>

      <Field label="Endpoint" hint="Optional for MCP.">
        <input
          value={endpoint}
          onChange={(e) => setEndpoint(e.target.value)}
          placeholder="https://agents.groundworks.example/circle"
          className={inputClass}
        />
      </Field>

      <p className="rounded-[var(--r-control)] bg-[var(--paper)] px-3 py-2 text-xs leading-relaxed text-[var(--ink-muted)]">
        Circle never stores the credential itself — only a reference to it. The
        agent arrives pending, and admitting it is a decision the other parties
        can see.
      </p>

      {!!error && <ErrorNote error={error} />}

      <div className="flex gap-2">
        <Button
          variant="primary"
          disabled={busy || !partyId || !name.trim()}
          onClick={async () => {
            setBusy(true);
            setError(null);
            try {
              await api.post(`/circles/${circleId}/agent-connections`, {
                circle_party_id: partyId,
                name,
                auth_mode: authMode,
                endpoint_url: endpoint || null,
                key_fingerprint: fingerprint || null,
              });
              onDone();
            } catch (e) {
              setError(e);
            } finally {
              setBusy(false);
            }
          }}
        >
          {busy ? "Adding…" : "Bring it in"}
        </Button>
        <Button variant="quiet" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

// ------------------------------------------------------------------- bits

/**
 * Side effect, coloured by consequence.
 *
 * Financial and external writes are the only two that get the alarm colour,
 * because using it on `circle_write` too would make all five look the same.
 */
export function EffectChip({ effect }: { effect: SideEffect }) {
  const map: Record<SideEffect, { label: string; bg: string; fg: string }> = {
    none: { label: "no effect", bg: "var(--paper-sunk)", fg: "var(--ink-faint)" },
    circle_write: {
      label: "changes this Circle",
      bg: "var(--paper-sunk)",
      fg: "var(--ink-muted)",
    },
    external_read: {
      label: "reads outside",
      bg: "var(--derived-soft)",
      fg: "var(--derived)",
    },
    external_write: {
      label: "writes outside",
      bg: "var(--signal-soft)",
      fg: "var(--signal)",
    },
    financial: { label: "money", bg: "var(--signal-soft)", fg: "var(--signal)" },
  };

  const tone = map[effect];

  return (
    <span
      className="rounded-[var(--r-chip)] px-2 py-0.5 text-xs font-[560]"
      style={{ background: tone.bg, color: tone.fg }}
    >
      {tone.label}
    </span>
  );
}

function ModeChip({ mode }: { mode: "read_only" | "propose" | "execute" }) {
  const map = {
    read_only: { label: "read only", bg: "var(--paper-sunk)", fg: "var(--ink-muted)" },
    propose: { label: "proposes", bg: "var(--derived-soft)", fg: "var(--derived)" },
    execute: { label: "executes", bg: "var(--accent-soft)", fg: "var(--accent)" },
  }[mode];

  return (
    <span
      className="rounded-[var(--r-chip)] px-2 py-0.5 text-xs font-[560]"
      style={{ background: map.bg, color: map.fg }}
    >
      {map.label}
    </span>
  );
}

function ActionStatusChip({ status }: { status: AgentActionRow["status"] }) {
  const tone =
    status === "executed"
      ? { bg: "var(--settled-soft)", fg: "var(--settled)" }
      : status === "rejected" || status === "failed" || status === "expired"
        ? { bg: "var(--signal-soft)", fg: "var(--signal)" }
        : { bg: "var(--paper-sunk)", fg: "var(--ink-muted)" };

  return (
    <span
      className="rounded-[var(--r-chip)] px-2 py-0.5 text-xs font-[560]"
      style={{ background: tone.bg, color: tone.fg }}
    >
      {status.replace(/_/g, " ")}
    </span>
  );
}

function ModeOption({
  active,
  onClick,
  label,
  detail,
}: {
  active: boolean;
  onClick: () => void;
  label: string;
  detail: string;
}) {
  return (
    <button
      onClick={onClick}
      className={`rounded-[var(--r-control)] px-3 py-2.5 text-left transition-colors ${
        active
          ? "bg-[var(--segment-active)] shadow-[var(--shadow-ring)]"
          : "hover:bg-[var(--paper-sunk)]"
      }`}
    >
      <span
        className={`block text-[0.8125rem] ${
          active ? "font-[620] text-[var(--ink)]" : "font-[560] text-[var(--ink-muted)]"
        }`}
      >
        {label}
      </span>
      <span className="mt-0.5 block text-xs leading-snug text-[var(--ink-faint)]">
        {detail}
      </span>
    </button>
  );
}
