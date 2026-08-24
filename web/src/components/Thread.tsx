import { useState } from "react";
import {
  api,
  formatDate,
  type CommentRow,
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
      {threads.length === 0 && !composing && (
        <Empty>
          {canComment
            ? "No discussion yet. Ask a question here rather than in email — it stays attached to this item."
            : "No discussion yet."}
        </Empty>
      )}

      {threads.map((t) => (
        <ThreadCard
          key={t.id}
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
  thread,
  canComment,
  onChanged,
  compact,
}: {
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
      <header className="flex flex-wrap items-center gap-2 px-3.5 py-2.5">
        <VisibilityChip visibility={thread.visibility} party={thread.party} />

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
              title="Make this visible to every party in the Circle. This cannot be undone."
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
            title="Included in the export packet at closure."
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
          title="Include this in the export packet at closure."
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
  const [visibility, setVisibility] = useState<"party" | "circle">("party");
  const [forRecord, setForRecord] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await api.post(`/circles/${circleId}/threads`, {
        subject_type: subject.type,
        subject_id: subject.id,
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
            Included in the export packet. Ordinary discussion is not.
          </span>
        </span>
      </label>

      {!!error && <ErrorNote error={error} />}

      <div className="flex gap-2">
        <Button variant="primary" disabled={busy || !body.trim()} onClick={submit}>
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
