import { CircleFrame } from "./CircleFrame";
import { Discussion } from "./Thread";
import { Panel } from "./ui";

/**
 * The general room.
 *
 * §20.3 refused one, and the reasoning was right about what a general room does
 * to a structured record: substance migrates in and the record decays into
 * something somebody updates afterwards out of duty. It was wrong about what
 * refusing one achieves. Work begins before there is a goal to attach a
 * question to — "are we bidding this?", "can you send last year's scope?" — and
 * a product with nowhere to ask that does not prevent the question. It sends it
 * to email, and the answer follows, and the conversation is lost somewhere with
 * no visibility rules at all.
 *
 * So the room exists, and the objection is answered rather than overruled. Two
 * things do that, and both are visible on this page: every discussion carries a
 * subject line, so this is a table of contents rather than one long log; and
 * any of them can be filed against the goal, decision or commitment it turns
 * out to be about, taking the whole conversation with it.
 *
 * The composer's default is still party-scoped. Nothing about having a general
 * room changes who can read what.
 */
export function DiscussionView({ circleId }: { circleId: string }) {
  return (
    <CircleFrame circleId={circleId} tab="discussion">
      {(circle) => (
        <Panel
          title="Discussion"
          meta={
            <span className="text-[0.8125rem] text-[var(--ink-muted)]">
              Not attached to anything yet
            </span>
          }
        >
          <div className="space-y-4 px-5 pb-5">
            <p className="max-w-[60ch] text-[0.875rem] leading-relaxed text-[var(--ink-muted)]">
              For anything that hasn't found its place yet. When a thread turns
              out to be about a goal, a decision or a commitment,{" "}
              <strong className="font-[560] text-[var(--ink)]">file it against that</strong> — the
              whole thread moves across, so the record stays up to date on its
              own.
            </p>

            <Discussion
              circleId={circleId}
              subject={{ type: "circle", id: circleId }}
              canComment={
                circle?.my_access?.permissions.includes("comment.create") === true &&
                !circle?.is_closed
              }
            />
          </div>
        </Panel>
      )}
    </CircleFrame>
  );
}
