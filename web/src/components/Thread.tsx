import { useState } from "react";
import {
  api,
  formatDate,
  type AttachableSubject,
  type CommentRow,
  type Commitment,
  type Decision,
  type Goal,
  type Thread as ThreadRow,
  type ThreadSubject,
} from "../lib/api";
import { MentionTextarea } from "./MentionInput";
import { Button, Empty, ErrorNote, Loading, useAsync } from "./ui";

/**
 * Conversation attached to one object.
 *
 * Deliberately not a chat window. Threads belong to the goal, claim or decision
 * they are about, and the composer's default is private to your own company —
 * the opposite default from evidence, because a contractor working out its
 * position in front of the client is how a Circle stops being used.
 *
 * Drops into any view as `<Discussion subject={{ type: "goal", id }} />`.
 */
export function Discussion({
  circleId,
  subject,
  canComment,
  compact = false,
}: {
  circleId: string;
  subject: { type: ThreadSubject; id: string };
  canComment: boolean;
  compact?: boolean;
}) {
  const [composing, setComposing] = useState(false);
  const { data, error, loading, reload } = useAsync<ThreadRow[]>(
    () =>
      api
        .get<{ data: ThreadRow[] }>(
          `/circles/${circleId}/threads?subject_type=${subject.type}&subject_id=${subject.id}`,
        )
        .then((r) => r.data),
    [circleId, subject.type, subject.id],
  );

  if (loading) return <Loading what="discussion" />;
  if (error) return <ErrorNote error={error} />;

  const threads = data ?? [];

  return (
    <div className="space-y-3">
      {threads.length === 0 && !composing && <Empty>No discussion yet.</Empty>}

      {threads.map((t) => (
        <ThreadCard
          key={t.id}
          circleId={circleId}
          thread={t}
          canComment={canComment}
          onChanged={reload}
          compact={compact}
        />
      ))}

      {composing ? (
        <Composer
          circleId={circleId}
          subject={subject}
          onDone={() => {
            setComposing(false);
            reload();
          }}
          onCancel={() => setComposing(false)}
        />
      ) : (
        canComment && (
          <Button variant="quiet" onClick={() => setComposing(true)}>
            Start a thread
          </Button>
        )
      )}
    </div>
  );
}

function ThreadCard({
  circleId,
  thread,
  canComment,
  onChanged,
  compact,
}: {
  circleId: string;
  thread: ThreadRow;
  canComment: boolean;
  onChanged: () => void;
  compact: boolean;
}) {
  const [open, setOpen] = useState(!compact);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [body, setBody] = useState("");

  const isPrivate = thread.visibility === "party";

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

  return (
    <div
      className={`rounded-[var(--r-control)] border border-[var(--rule)] ${
        thread.is_resolved ? "opacity-70" : ""
      }`}
    >
      {/* A general thread names itself, because there is no object above it
          doing so. Sits on its own line: it is the heading, not a chip. */}
      {thread.title && (
        <p className="display px-3.5 pt-3 text-[0.9375rem] font-[600] leading-snug">
          {thread.title}
        </p>
      )}

      <header className="flex flex-wrap items-center gap-2 px-3.5 py-2.5">
        <VisibilityChip visibility={thread.visibility} party={thread.party} />

        {thread.attached && (
          <span
            className="rounded-[var(--r-chip)] bg-[var(--paper-sunk)] px-2 py-0.5 text-xs text-[var(--ink-muted)]"
            title={`Moved here from the general discussion${
              thread.attached.by ? ` by ${thread.attached.by}` : ""
            }.`}
          >
            Filed from discussion
          </span>
        )}

        {thread.is_resolved && (
          <span className="rounded-[var(--r-chip)] bg-[var(--settled-soft)] px-2 py-0.5 text-xs font-[560] text-[var(--settled)]">
            Resolved
          </span>
        )}

        <span className="text-xs text-[var(--ink-faint)]">
          {thread.comment_count} message{thread.comment_count === 1 ? "" : "s"} ·{" "}
          {formatDate(thread.last_activity_at ?? thread.created_at, true)}
        </span>

        <span className="ml-auto flex items-center gap-1">
          {/* Widening is offered; narrowing is not, because the other parties
              have already read it and the product cannot take that back. */}
          {isPrivate && canComment && !thread.is_resolved && (
            <Button
              variant="quiet"
              disabled={busy}
              title="Make this visible to every party in the Circle. You can't undo it."
              onClick={() => act(() => api.post(`/threads/${thread.id}/share`))}
            >
              Share with Circle
            </Button>
          )}
          {canComment && !thread.is_resolved && (
            <Button
              variant="quiet"
              disabled={busy}
              onClick={() => act(() => api.post(`/threads/${thread.id}/resolve`))}
            >
              Resolve
            </Button>
          )}
          {/* Only a general thread can be filed. Something already about a
              decision is where it belongs. */}
          {thread.is_general && canComment && (
            <AttachControl circleId={circleId} thread={thread} busy={busy} act={act} />
          )}
          <button
            onClick={() => setOpen((v) => !v)}
            className="rounded-[var(--r-control)] px-2 py-1 text-xs text-[var(--ink-muted)] hover:bg-[var(--paper-sunk)]"
          >
            {open ? "Hide" : "Show"}
          </button>
        </span>
      </header>

      {open && (
        <div className="border-t border-[var(--rule)]">
          <ul>
            {(thread.comments ?? []).map((c) => (
              <Message key={c.id} comment={c} canComment={canComment} onChanged={onChanged} />
            ))}
          </ul>

          {!!error && (
            <div className="px-3.5 pb-3">
              <ErrorNote error={error} />
            </div>
          )}

          {canComment && !thread.is_resolved && (
            <div className="flex items-end gap-2 border-t border-[var(--rule)] px-3.5 py-3">
              {/* The thread's own readership is the mention list: it is already
                  open, so the server can answer who a mention here reaches. */}
              <MentionTextarea
                source={`/threads/${thread.id}/mentionable`}
                value={body}
                onChange={setBody}
                rows={2}
                placeholder="Reply — @ to notify someone"
              />
              <Button
                variant="primary"
                disabled={busy || !body.trim()}
                onClick={() =>
                  act(async () => {
                    await api.post(`/threads/${thread.id}/comments`, { body });
                    setBody("");
                  })
                }
              >
                Send
              </Button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function Message({
  comment,
  canComment,
  onChanged,
}: {
  comment: CommentRow;
  canComment: boolean;
  onChanged: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const isAgent = comment.author_type === "agent";

  return (
    <li className="border-t border-[var(--rule)] px-3.5 py-3 first:border-t-0">
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span
          className={`text-[0.8125rem] font-[590] ${
            isAgent ? "text-[var(--derived)]" : "text-[var(--ink)]"
          }`}
        >
          {comment.author ?? (isAgent ? "Agent" : "Someone")}
        </span>
        {comment.author_party && (
          <span className="text-xs text-[var(--ink-faint)]">{comment.author_party}</span>
        )}

        {/* A comment that carried a state change is part of the decision
            record whether anyone chose that or not. */}
        {comment.action_type && (
          <span className="rounded-[var(--r-chip)] bg-[var(--paper-sunk)] px-2 py-0.5 text-xs font-[560] text-[var(--ink-muted)]">
            {comment.action_type.replace(/_/g, " ")}
          </span>
        )}
        {comment.on_record && (
          <span
            className="rounded-[var(--r-chip)] bg-[var(--accent-soft)] px-2 py-0.5 text-xs font-[560] text-[var(--accent)]"
            title="Goes into the export packet when the Circle closes."
          >
            On record
          </span>
        )}

        <span className="ml-auto text-xs text-[var(--ink-faint)]">
          {formatDate(comment.created_at, true)}
        </span>
      </div>

      <p className="mt-1.5 whitespace-pre-wrap text-sm leading-relaxed text-[var(--ink)]">
        {comment.body}
      </p>

      {comment.mentions.length > 0 && (
        <p className="mt-1.5 text-xs text-[var(--ink-faint)]">
          Notified {comment.mentions.join(", ")}
        </p>
      )}

      {canComment && !comment.on_record && (
        <button
          disabled={busy}
          title="Include this in the export packet when the Circle closes."
          onClick={async () => {
            setBusy(true);
            try {
              await api.post(`/comments/${comment.id}/for-the-record`);
              onChanged();
            } finally {
              setBusy(false);
            }
          }}
          className="mt-2 text-xs text-[var(--accent)] hover:underline disabled:opacity-40"
        >
          Mark for the record
        </button>
      )}
    </li>
  );
}

/**
 * Move a general discussion onto the object it turned out to be about.
 *
 * The reason a general room is safe to have. §20.3 refused one because
 * substance migrates into it and the structured record decays — true, and
 * unavoidable, because people ask questions before there is anything to attach
 * them to. What is avoidable is the conversation staying stranded there. One
 * control, and the whole thread moves with every comment and marking intact.
 *
 * Goals first and expanded, because that is where nearly everything belongs.
 */
function AttachControl({
  circleId,
  thread,
  busy,
  act,
}: {
  circleId: string;
  thread: ThreadRow;
  busy: boolean;
  act: (fn: () => Promise<unknown>) => Promise<void>;
}) {
  const [open, setOpen] = useState(false);

  const { data, loading } = useAsync<{ type: AttachableSubject; id: string; label: string }[]>(
    async () => {
      if (!open) return [];

      const [goals, decisions, commitments] = await Promise.all([
        api.get<{ data: Goal[] }>(`/circles/${circleId}/goals`).then((r) => r.data),
        api.get<{ data: Decision[] }>(`/circles/${circleId}/decisions`).then((r) => r.data),
        api
          .get<{ data: Commitment[] }>(`/circles/${circleId}/commitments`)
          .then((r) => r.data),
      ]);

      // The tree arrives nested; flatten it, keeping the order somebody reads
      // it in rather than sorting alphabetically and scattering the packages.
      const flatGoals: { type: AttachableSubject; id: string; label: string }[] = [];
      const walk = (nodes: Goal[], depth: number) => {
        for (const g of nodes) {
          if (g.id) {
            flatGoals.push({
              type: "goal",
              id: g.id,
              label: `${"— ".repeat(depth)}${g.title}`,
            });
          }
          if (g.children?.length) walk(g.children, depth + 1);
        }
      };
      walk(goals, 0);

      return [
        ...flatGoals,
        ...decisions.map((d) => ({ type: "decision" as const, id: d.id, label: d.title })),
        ...commitments.map((c) => ({ type: "commitment" as const, id: c.id, label: c.title })),
      ];
    },
    [open, circleId],
  );

  if (!open) {
    return (
      <Button
        variant="quiet"
        disabled={busy}
        title="File this conversation against the goal, decision or commitment it is about."
        onClick={() => setOpen(true)}
      >
        File against…
      </Button>
    );
  }

  return (
    <select
      autoFocus
      disabled={busy || loading}
      defaultValue=""
      onChange={(e) => {
        const option = (data ?? []).find((o) => `${o.type}:${o.id}` === e.target.value);
        if (!option) return;
        void act(() =>
          api.post(`/threads/${thread.id}/attach`, {
            subject_type: option.type,
            subject_id: option.id,
          }),
        );
      }}
      onBlur={() => setOpen(false)}
      className="max-w-[16rem] rounded-[var(--r-control)] border border-[var(--rule)] bg-[var(--paper)] px-2 py-1 text-xs"
    >
      <option value="" disabled>
        {loading ? "Loading…" : "Choose where this belongs"}
      </option>
      {(data ?? []).map((o) => (
        <option key={`${o.type}:${o.id}`} value={`${o.type}:${o.id}`}>
          {o.label}
        </option>
      ))}
    </select>
  );
}

function Composer({
  circleId,
  subject,
  onDone,
  onCancel,
}: {
  circleId: string;
  subject: { type: ThreadSubject; id: string };
  onDone: () => void;
  onCancel: () => void;
}) {
  const [body, setBody] = useState("");
  const [title, setTitle] = useState("");
  const [visibility, setVisibility] = useState<"party" | "circle">("party");
  const [forRecord, setForRecord] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  // A discussion about the Circle has no object to take its name from, so it
  // has to carry one. This is the difference between a table of contents and
  // the undifferentiated log a general room otherwise becomes.
  const general = subject.type === "circle";

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await api.post(`/circles/${circleId}/threads`, {
        subject_type: subject.type,
        subject_id: subject.id,
        title: general ? title : undefined,
        body,
        visibility,
        for_the_record: forRecord,
      });
      onDone();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-3 rounded-[var(--r-control)] border border-[var(--rule)] bg-[var(--paper-inset)] p-3.5">
      {general && (
        <input
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          maxLength={200}
          autoFocus
          placeholder="What is this about?"
          className="w-full rounded-[var(--r-control)] border border-[var(--rule)] bg-[var(--paper)] px-3 py-2 text-[0.9375rem] font-[560] outline-none focus:border-[var(--ink-faint)]"
        />
      )}

      {/* Re-asked when the visibility toggle moves: who a mention reaches is
          decided by which thread this is about to become. */}
      <MentionTextarea
        source={`/circles/${circleId}/mentionable?visibility=${visibility}`}
        value={body}
        onChange={setBody}
        rows={3}
        autoFocus
        placeholder="What needs saying? @ to notify someone."
      />

      {/*
        Two choices, stated as consequences rather than as labels. "Party" and
        "Circle" mean nothing to someone deciding whether to be candid.
      */}
      <div className="flex flex-wrap gap-1.5">
        <Segment
          active={visibility === "party"}
          onClick={() => setVisibility("party")}
          label="My company only"
          hint="Other parties cannot see this thread."
        />
        <Segment
          active={visibility === "circle"}
          onClick={() => setVisibility("circle")}
          label="Everyone in the Circle"
          hint="Every party can read and reply."
        />
      </div>

      <label className="flex items-start gap-2 text-[0.8125rem] text-[var(--ink-muted)]">
        <input
          type="checkbox"
          checked={forRecord}
          onChange={(e) => setForRecord(e.target.checked)}
          className="mt-0.5"
        />
        <span>
          Put this on the record
          <span className="block text-xs text-[var(--ink-faint)]">
            Goes into the export packet. Ordinary discussion doesn't.
          </span>
        </span>
      </label>

      {!!error && <ErrorNote error={error} />}

      <div className="flex gap-2">
        <Button
          variant="primary"
          disabled={busy || !body.trim() || (general && !title.trim())}
          onClick={submit}
        >
          {busy ? "Posting…" : "Post"}
        </Button>
        <Button variant="quiet" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

function Segment({
  active,
  onClick,
  label,
  hint,
}: {
  active: boolean;
  onClick: () => void;
  label: string;
  hint: string;
}) {
  return (
    <button
      onClick={onClick}
      title={hint}
      className={`rounded-[var(--r-control)] px-3 py-1.5 text-[0.8125rem] transition-colors ${
        active
          ? "bg-[var(--segment-active)] font-[590] text-[var(--ink)] shadow-[var(--shadow-ring)]"
          : "text-[var(--ink-muted)] hover:bg-[var(--paper-sunk)]"
      }`}
    >
      {label}
    </button>
  );
}

export function VisibilityChip({
  visibility,
  party,
}: {
  visibility: "circle" | "party";
  party: string | null;
}) {
  if (visibility === "circle") {
    return (
      <span className="rounded-[var(--r-chip)] bg-[var(--paper-sunk)] px-2 py-0.5 text-xs font-[560] text-[var(--ink-muted)]">
        Whole Circle
      </span>
    );
  }

  return (
    <span
      className="rounded-[var(--r-chip)] bg-[var(--accent-soft)] px-2 py-0.5 text-xs font-[560] text-[var(--accent)]"
      title="Only people from this party can read this thread."
    >
      {party ?? "Private"} only
    </span>
  );
}
