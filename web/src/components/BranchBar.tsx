import { useState } from "react";
import { api, formatDate, type Branch, type BranchChange } from "../lib/api";
import { Button, ErrorNote, Field, inputClass, useAsync } from "./ui";

/**
 * Choosing which version of the plan you are looking at, and reviewing it.
 *
 * The bar sits above the tree rather than on a page of its own, because the
 * only useful way to review a proposed change to a plan is against the plan.
 * A diff on a separate screen means holding the tree in your head while you
 * read it, which is precisely the thing people get wrong.
 *
 * Two states it is careful about.
 *
 * A branch with conflicts says so before anything else, and hides the approve
 * button entirely. Someone agreeing to a diff that no longer describes reality
 * has been asked the wrong question, and greying out the button while leaving
 * it visible still invites the attempt.
 *
 * A branch waiting on *you* reads differently from one waiting on somebody
 * else. "Beam Rail has not signed" is information; "you have not signed" is a
 * task, and the two should not look alike.
 */
export function BranchBar({
  circleId,
  branches,
  active,
  onSelect,
  onChanged,
  canBranch,
  canMerge,
  myParties,
}: {
  circleId: string;
  branches: Branch[];
  active: Branch | null;
  onSelect: (id: string | null) => void;
  onChanged: () => void;
  canBranch: boolean;
  canMerge: boolean;
  /** Party labels the viewer sits in, so "waiting on you" can be said plainly. */
  myParties: string[];
}) {
  const [creating, setCreating] = useState(false);

  const live = branches.filter((b) => b.status === "draft" || b.status === "open");

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="label">Viewing</span>

        <div className="flex flex-wrap items-center gap-1">
          <BranchChip
            label="The plan"
            active={active === null}
            onClick={() => onSelect(null)}
          />
          {live.map((b) => (
            <BranchChip
              key={b.id}
              label={b.name}
              status={b.status}
              conflicted={b.has_conflicts}
              active={active?.id === b.id}
              onClick={() => onSelect(b.id)}
            />
          ))}
        </div>

        {canBranch && (
          <span className="ml-auto">
            <Button
              variant={creating ? "quiet" : "quiet"}
              onClick={() => setCreating((v) => !v)}
            >
              {creating ? "Cancel" : "Propose a change"}
            </Button>
          </span>
        )}
      </div>

      {creating && (
        <BranchComposer
          circleId={circleId}
          onDone={(id) => {
            setCreating(false);
            onChanged();
            onSelect(id);
          }}
          onCancel={() => setCreating(false)}
        />
      )}

      {active && (
        <BranchReview
          branch={active}
          canMerge={canMerge}
          myParties={myParties}
          onChanged={onChanged}
          onLeave={() => onSelect(null)}
        />
      )}
    </div>
  );
}

function BranchChip({
  label,
  status,
  conflicted,
  active,
  onClick,
}: {
  label: string;
  status?: Branch["status"];
  conflicted?: boolean;
  active: boolean;
  onClick: () => void;
}) {
  return (
    <button
      onClick={onClick}
      className={`flex items-center gap-1.5 rounded-[var(--r-control)] px-2.5 py-1 text-[0.8125rem] transition-colors ${
        active
          ? "bg-[var(--ink)] font-[560] text-[var(--paper-raised)]"
          : "text-[var(--ink-muted)] hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)]"
      }`}
    >
      {status === "draft" && (
        <span
          className="h-1.5 w-1.5 rounded-full bg-[var(--ink-faint)]"
          title="Draft — only you can see this as a proposal."
        />
      )}
      {status === "open" && (
        <span
          className={`h-1.5 w-1.5 rounded-full ${conflicted ? "bg-[var(--signal)]" : "bg-[var(--derived)]"}`}
          title={conflicted ? "Conflicts with the current plan." : "Open for review."}
        />
      )}
      {label}
    </button>
  );
}

/**
 * The review panel: what the branch does, who still has to agree, and the two
 * buttons that matter.
 */
function BranchReview({
  branch,
  canMerge,
  myParties,
  onChanged,
  onLeave,
}: {
  branch: Branch;
  canMerge: boolean;
  myParties: string[];
  onChanged: () => void;
  onLeave: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [note, setNote] = useState("");

  async function act(path: string, body?: unknown) {
    setBusy(true);
    setError(null);
    try {
      await api.post(`/branches/${branch.id}/${path}`, body ?? {});
      setNote("");
      onChanged();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  const waitingOnMe = branch.awaiting.some((p) => myParties.includes(p.label));
  const isDraft = branch.status === "draft";

  /*
    The change list is fetched here rather than carried in the index payload.
    A reviewer has to be able to read the whole diff on the screen where they
    agree to it; sending every branch's changes with the list would make the
    common case — glancing at what is open — pay for the rare one.
  */
  const detail = useAsync<Branch>(
    () => api.get<{ data: Branch }>(`/branches/${branch.id}`).then((r) => r.data),
    [branch.id, branch.change_count, branch.status, branch.signatures.length],
  );

  const changes = detail.data?.changes ?? [];

  return (
    <div className="rounded-[var(--r-panel)] border border-[var(--rule)] bg-[var(--paper-inset)]">
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-b border-[var(--rule)] px-4 py-3">
        <div className="min-w-0 flex-1">
          <p className="display text-[1rem] font-[620] text-[var(--ink)]">{branch.name}</p>
          {branch.intent && (
            <p className="mt-1 max-w-[70ch] text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
              {branch.intent}
            </p>
          )}
          <p className="mt-1 text-xs text-[var(--ink-faint)]">
            {branch.change_count} change{branch.change_count === 1 ? "" : "s"} ·{" "}
            {branch.author ?? "—"}
            {branch.party && ` · ${branch.party}`}
            {branch.proposed_at && ` · proposed ${formatDate(branch.proposed_at)}`}
          </p>
        </div>

        <Button variant="quiet" onClick={onLeave}>
          Back to the plan
        </Button>
      </div>

      {/*
        Conflicts first, and everything else is subordinate to them. A reviewer
        who reads the diff before learning the plan moved has already formed a
        view of a proposal that no longer exists.
      */}
      {branch.has_conflicts && (
        <div className="border-b border-[var(--rule)] bg-[var(--signal-soft)] px-4 py-3">
          <p className="text-[0.8125rem] font-[560] text-[var(--signal)]">
            The plan has moved since this was drafted.
          </p>
          <ul className="mt-1.5 space-y-1">
            {branch.conflicts.map((c, i) => (
              <li key={i} className="text-xs leading-relaxed text-[var(--signal)]">
                {c.message}
              </li>
            ))}
          </ul>
          <p className="mt-2 text-xs leading-relaxed text-[var(--ink-muted)]">
            Rebasing points the branch at the plan as it stands now. What it asks
            for is untouched — but everyone who already signed is asked again,
            because they agreed to a different diff.
          </p>
          <div className="mt-2">
            <Button variant="primary" disabled={busy} onClick={() => act("rebase")}>
              {busy ? "Rebasing…" : "Rebase onto the plan"}
            </Button>
          </div>
        </div>
      )}

      {/*
        The diff, in full, above the buttons that act on it. Whatever else this
        panel gets wrong, somebody must not be able to reach "Agree" without
        having passed the thing they are agreeing to.
      */}
      {changes.length > 0 && (
        <div className="border-b border-[var(--rule)]">
          <p className="label px-4 pt-3">
            What this would change
          </p>
          <ul className="mt-1.5">
            {changes.map((c) => (
              <ChangeLine key={c.id} change={c} />
            ))}
          </ul>
        </div>
      )}

      <div className="px-4 py-3">
        {isDraft ? (
          <p className="text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
            A draft. Edit the tree below and your changes are staged here rather
            than applied. Nobody else is asked anything until you propose it.
          </p>
        ) : (
          <Signatures branch={branch} waitingOnMe={waitingOnMe} />
        )}

        {!!error && (
          <div className="mt-2">
            <ErrorNote error={error} />
          </div>
        )}

        <div className="mt-3 flex flex-wrap items-center gap-2">
          {isDraft && (
            <Button
              variant="primary"
              disabled={busy || branch.change_count === 0}
              title={
                branch.change_count === 0
                  ? "An empty branch proposes nothing."
                  : "Ask the affected parties to agree to this."
              }
              onClick={() => act("propose")}
            >
              {busy ? "…" : "Propose it"}
            </Button>
          )}

          {branch.status === "open" && canMerge && !branch.has_conflicts && (
            <>
              <input
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder="A note for the record (optional)"
                className={`${inputClass} !max-w-xs !text-[0.8125rem]`}
              />
              <Button
                variant="primary"
                disabled={busy || !waitingOnMe}
                title={
                  waitingOnMe
                    ? "Agree to this on behalf of your company."
                    : "Your party has already answered, or this does not touch your work."
                }
                onClick={() => act("approve", { comment: note || null })}
              >
                {busy ? "…" : "Agree"}
              </Button>
              <Button
                variant="danger"
                disabled={busy || !waitingOnMe}
                onClick={() => act("refuse", { reason: note || null })}
              >
                Refuse
              </Button>
            </>
          )}

          <Button variant="quiet" disabled={busy} onClick={() => act("withdraw")}>
            Withdraw
          </Button>
        </div>
      </div>
    </div>
  );
}

function Signatures({ branch, waitingOnMe }: { branch: Branch; waitingOnMe: boolean }) {
  const answered = new Map(branch.signatures.map((s) => [s.party ?? s.person, s]));

  return (
    <div>
      <p className="label">Needs agreement from</p>

      <ul className="mt-1.5 space-y-1.5">
        {branch.affected_parties.map((p) => {
          const sig = answered.get(p.label);
          const refused = sig?.outcome === "rejected";

          return (
            <li key={p.id} className="flex flex-wrap items-baseline gap-x-2 text-[0.8125rem]">
              <span
                className={`h-1.5 w-1.5 shrink-0 rounded-full ${
                  sig && !refused
                    ? "bg-[var(--settled)]"
                    : refused
                      ? "bg-[var(--signal)]"
                      : "bg-[var(--ink-faint)]"
                }`}
              />
              <span className="font-[560] text-[var(--ink)]">{p.label}</span>
              <span
                className={
                  refused
                    ? "text-[var(--signal)]"
                    : sig
                      ? "text-[var(--settled)]"
                      : "text-[var(--ink-faint)]"
                }
              >
                {refused ? "refused" : sig ? "agreed" : "not yet"}
              </span>
              {sig?.person && (
                <span className="text-xs text-[var(--ink-faint)]">
                  {sig.person} · {formatDate(sig.at)}
                </span>
              )}
              {sig?.comment && (
                <span className="w-full text-xs leading-relaxed text-[var(--ink-muted)]">
                  “{sig.comment}”
                </span>
              )}
            </li>
          );
        })}
      </ul>

      {waitingOnMe && (
        <p className="mt-2 text-[0.8125rem] font-[560] text-[var(--ink)]">
          This is waiting on you.
        </p>
      )}

      {!waitingOnMe && branch.awaiting.length > 0 && (
        <p className="mt-2 text-xs text-[var(--ink-faint)]">
          Waiting on {branch.awaiting.map((p) => p.label).join(", ")}.
        </p>
      )}
    </div>
  );
}

function BranchComposer({
  circleId,
  onDone,
  onCancel,
}: {
  circleId: string;
  onDone: (id: string) => void;
  onCancel: () => void;
}) {
  const [name, setName] = useState("");
  const [intent, setIntent] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  return (
    <div className="space-y-3 rounded-[var(--r-control)] border border-[var(--rule)] bg-[var(--paper-raised)] p-3.5">
      <Field label="What are you proposing?">
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          autoFocus
          placeholder="Pull the weld inspection forward two weeks"
          className={inputClass}
        />
      </Field>

      <Field
        label="Why"
        hint="The other parties read this before they read the diff. It is the case you are making."
      >
        <input
          value={intent}
          onChange={(e) => setIntent(e.target.value)}
          placeholder="Freight landed early, so the inspection window opens sooner."
          className={inputClass}
        />
      </Field>

      {!!error && <ErrorNote error={error} />}

      <div className="flex gap-2">
        <Button
          variant="primary"
          disabled={busy || !name.trim()}
          onClick={async () => {
            setBusy(true);
            setError(null);
            try {
              const res = await api.post<{ data: { id: string } }>(
                `/circles/${circleId}/branches`,
                { name, intent: intent || null },
              );
              onDone(res.data.id);
            } catch (e) {
              setError(e);
            } finally {
              setBusy(false);
            }
          }}
        >
          {busy ? "Opening…" : "Start drafting"}
        </Button>
        <Button variant="quiet" onClick={onCancel}>
          Cancel
        </Button>
      </div>

      <p className="text-xs leading-relaxed text-[var(--ink-faint)]">
        Nothing you do on a branch touches the plan. When you propose it, every
        company whose work it changes has to agree before it does.
      </p>
    </div>
  );
}

/** A single staged edit, rendered as a before → after. */
export function ChangeLine({ change }: { change: BranchChange }) {
  const attributes = change.attributes ?? {};
  const base = change.base ?? {};

  return (
    <li className="border-t border-[var(--rule)] px-4 py-2.5 first:border-t-0">
      <div className="flex flex-wrap items-center gap-2">
        <ChangeMark type={change.change_type} />
        <span className="text-[0.8125rem] font-[560] text-[var(--ink)]">
          {change.goal_title ?? attributes.title ?? "a goal"}
        </span>
        {change.conflicts.length > 0 && (
          <span className="rounded-[var(--r-chip)] bg-[var(--signal-soft)] px-1.5 py-0.5 text-[0.6875rem] font-[560] text-[var(--signal)]">
            conflicts
          </span>
        )}
      </div>

      {change.change_type === "update" && (
        <dl className="mt-1.5 space-y-0.5">
          {Object.keys(attributes).map((field) => (
            <div key={field} className="flex flex-wrap items-baseline gap-1.5 text-xs">
              <dt className="text-[var(--ink-faint)]">{field.replace(/_/g, " ")}</dt>
              <dd className="text-[var(--ink-muted)] line-through">
                {readable(base[field])}
              </dd>
              <span className="text-[var(--ink-faint)]">→</span>
              <dd className="font-[560] text-[var(--ink)]">{readable(attributes[field])}</dd>
            </div>
          ))}
        </dl>
      )}

      {change.reason && (
        <p className="mt-1 text-xs leading-relaxed text-[var(--ink-muted)]">{change.reason}</p>
      )}
    </li>
  );
}

function ChangeMark({ type }: { type: BranchChange["change_type"] }) {
  const [symbol, colour, label] =
    type === "add"
      ? ["+", "var(--settled)", "Added"]
      : type === "remove"
        ? ["−", "var(--signal)", "Dropped"]
        : ["~", "var(--derived)", "Changed"];

  return (
    <span
      className="mono flex h-4 w-4 shrink-0 items-center justify-center rounded-[3px] text-xs font-[600]"
      style={{ background: `color-mix(in srgb, ${colour} 14%, transparent)`, color: colour }}
      title={label}
      aria-label={label}
    >
      {symbol}
    </span>
  );
}

function readable(value: unknown): string {
  if (value === null || value === undefined || value === "") return "not set";
  if (typeof value === "string" && /^\d{4}-\d{2}-\d{2}T/.test(value)) return formatDate(value);
  return String(value);
}
