<?php

namespace App\Services\Notifications;

use App\Enums\NotificationKind;
use App\Mail\CircleNotification;
use App\Models\Circle;
use App\Models\CircleResource;
use App\Models\Comment;
use App\Models\CommentMention;
use App\Models\Decision;
use App\Models\EvidenceVersion;
use App\Models\Goal;
use App\Models\GoalScheduleChange;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Evidence\SupersessionImpact;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The one place that decides who gets told what.
 *
 * Before this existed, nothing left the browser: mentions were recorded and
 * surfaced in-app, and every deadline, assignment and waiting approval was
 * state somebody had to remember to go and look at. The spec said so about
 * itself. A cross-company workspace where the other company is never told
 * anything is a workspace the other company stops opening.
 *
 * Two rules govern everything below, and both are the reason this is a service
 * rather than a `Mail::to()` call at five call sites.
 *
 * **A notification is a disclosure.** It names an object, and often quotes it.
 * Sending one to somebody the gate would refuse leaks precisely what the gate
 * exists to protect — and it leaks it to their inbox, outside the system,
 * where no access check will ever run again. So every message is put through
 * AccessGate for the permission its kind declares, against the resource it
 * concerns, immediately before it is sent. A recipient whose access was
 * narrowed since they cited a document is silently not told, which is correct:
 * they can no longer see the thing the message would be about.
 *
 * **Telling somebody must not be able to break the thing it is about.** Every
 * caller below has already committed and audited its state change by the time
 * it gets here. Mail is queued, every failure is caught, and nothing in this
 * class throws. A mail provider having a bad afternoon must not roll back an
 * approval.
 *
 * The gate check uses `allows()` rather than `authorise()` on purpose. A
 * refusal here is not an attempted access — it is this service deciding not to
 * speak — and writing `access.denied` for everyone it considered would fill the
 * Circle's record with events no person caused.
 */
class Notifier
{
    public function __construct(
        private readonly \App\Services\Authorisation\AccessGate $gate,
        private readonly SupersessionImpact $impact,
    ) {}

    // ------------------------------------------------------------- invitations

    /**
     * Somebody has been invited and holds a token they cannot use if they never
     * receive it.
     *
     * The only kind sent to a non-member, and so the only one with no gate
     * check available — the recipient has no membership for the gate to read.
     * What bounds it instead is the content: the Circle's name, who invited
     * them, the role offered, and the token. No purpose, no parties, no
     * evidence. An invitation says come and look, not here is what is inside.
     */
    public function invited(Invitation $invitation, string $token): void
    {
        $circle = $invitation->circle;

        if ($circle === null) {
            return;
        }

        $inviter = $invitation->invitedBy?->name ?? 'A Circle owner';

        $this->dispatch(
            email: $invitation->email,
            kind: NotificationKind::Invited,
            circle: $circle,
            headline: "{$inviter} has invited you to take part",
            facts: array_filter([
                'Circle'  => $circle->name,
                'Role'    => $invitation->circle_role->value,
                'Expires' => $invitation->expires_at?->toDayDateTimeString() ?? '',
            ]),
            url: $this->url("/invitations/{$token}"),
            note: 'Accepting does not give you access to everything in this Circle. '
                . 'What you can see is decided item by item.',
        );
    }

    // --------------------------------------------------------------- decisions

    /**
     * A decision named somebody as its approver.
     *
     * Only the assigned approver can resolve a decision — holding the role is
     * not enough (spec §8) — which makes this the one message whose absence
     * stops work outright. Nobody else can do it for them, and until now
     * nothing told them it existed.
     */
    public function decisionAssigned(Decision $decision): void
    {
        $approver = $decision->approver;
        $circle   = $decision->circle;

        if ($approver === null || $circle === null) {
            return;
        }

        $this->send(
            recipient: $approver,
            kind: NotificationKind::DecisionAssigned,
            circle: $circle,
            headline: $decision->title,
            facts: array_filter([
                'Subject' => $decision->subject_type
                    ? "{$decision->subject_type} v{$decision->subject_version}"
                    : '',
                'Expires' => $decision->expires_at?->toDayDateTimeString() ?? '',
            ]),
            url: $this->url("/circles/{$circle->id}/decisions"),
            note: 'Your approval binds to this exact version. If the subject is edited '
                . 'afterwards, the new version is not approved by this.',
        );
    }

    // ---------------------------------------------------------------- evidence

    /**
     * A version was superseded, and something relied on it.
     *
     * This is the message the product was missing most. Version lineage was
     * already exact and the old bytes stayed addressable forever, but nothing
     * answered "what did this just invalidate" and nothing carried the answer
     * to the person holding it.
     *
     * Silence when nothing relied on it. Most revisions land on documents
     * nobody has cited or approved against, and a message saying so would
     * teach everybody to filter these.
     */
    public function evidenceSuperseded(EvidenceVersion $superseded, EvidenceVersion $replacement): void
    {
        $item     = $superseded->evidenceItem;
        $resource = $item?->resource;
        $circle   = $resource?->circle;

        if ($circle === null) {
            return;
        }

        $impact = $this->impact->of($superseded);

        if ($impact['recipients']->isEmpty()) {
            return;
        }

        $name = $resource->name ?? $superseded->original_filename;

        foreach ($impact['recipients'] as $recipient) {
            $this->send(
                recipient: $recipient,
                kind: NotificationKind::EvidenceSuperseded,
                circle: $circle,
                headline: "{$name} has been superseded",
                facts: array_filter([
                    'Was'       => 'v' . $superseded->version_number,
                    'Now'       => 'v' . $replacement->version_number,
                    'Claims'    => $impact['claims']->isNotEmpty()
                        ? $impact['claims']->count() . ' cite the old version'
                        : '',
                    'Approvals' => $impact['decisions']->isNotEmpty()
                        ? $impact['decisions']->count() . ' bound to the old version'
                        : '',
                ]),
                url: $this->url("/circles/{$circle->id}/context"),
                note: 'Nothing has been changed for you. The old version is still exactly '
                    . 'what it was, and every citation to it still resolves. Whether any of '
                    . 'it needs revisiting is your call.',
                resource: $resource,
            );
        }
    }

    // ------------------------------------------------------------------- dates

    /**
     * A due date moved and it waits on one party's agreement.
     *
     * The date has already moved by the time this is sent — rescheduling
     * records what happened rather than gating it — so this is not an approval
     * request. It is the counterparty finding out on the day instead of at the
     * end of the job, which is the entire difference between a schedule change
     * register and an argument.
     */
    public function scheduleChangeProposed(GoalScheduleChange $change): void
    {
        $goal   = $change->goal;
        $circle = $goal?->circle;

        if ($goal === null || $circle === null || $change->requires_party_id === null) {
            return;
        }

        $movedBy = $change->changedBy?->name ?? 'Someone';

        foreach ($this->membersOfParty($circle, $change->requires_party_id) as $recipient) {
            if ($recipient->id === $change->changed_by_user_id) {
                continue;
            }

            $this->send(
                recipient: $recipient,
                kind: NotificationKind::ScheduleChangeProposed,
                circle: $circle,
                headline: "{$movedBy} moved a date on {$goal->title}",
                facts: array_filter([
                    'From'   => $change->from_due_at?->toFormattedDayDateString() ?? 'no date',
                    'To'     => $change->to_due_at?->toFormattedDayDateString() ?? 'no date',
                    'Reason' => $change->reason ?? '',
                ]),
                url: $this->url("/circles/{$circle->id}"),
                note: 'Your agreement is recorded against this change. Until you give it, '
                    . 'the record shows the date moved without it.',
                subject: $goal,
            );
        }
    }

    // ---------------------------------------------------------------- mentions

    /**
     * Somebody was named in a comment.
     *
     * `comment_mentions.notified_at` has been in the schema since mentions were
     * built, waiting for something to stamp it. This stamps it, and only for
     * the people actually sent to — so the column means "was told", not "was
     * named", which is the distinction that makes it worth having.
     *
     * A party-scoped thread is invisible outside its party, convener included.
     * The gate check below is what enforces that here, rather than a second
     * copy of the visibility rule living in this class.
     */
    public function mentioned(Comment $comment): void
    {
        $thread = $comment->thread;
        $circle = $thread?->circle;

        if ($circle === null) {
            return;
        }

        // Comment carries author_type/author_id rather than a relation, because
        // an agent can post one. An agent's mention still reaches a person.
        $author = $comment->author_type === 'user'
            ? User::find($comment->author_id)?->name
            : 'An agent';

        foreach ($comment->mentions as $mention) {
            $recipient = $mention->user;

            if ($recipient === null || $recipient->id === $comment->author_id) {
                continue;
            }

            if ($mention->notified_at !== null) {
                continue;
            }

            $sent = $this->send(
                recipient: $recipient,
                kind: NotificationKind::Mentioned,
                circle: $circle,
                headline: ($author ? "{$author} mentioned you" : 'You were mentioned')
                    . " on a {$thread->subject_type}",
                facts: [
                    'Said' => \Illuminate\Support\Str::limit((string) $comment->body, 240),
                ],
                url: $this->url("/circles/{$circle->id}/now"),
            );

            if ($sent) {
                CommentMention::whereKey($mention->id)->update(['notified_at' => now()]);
            }
        }
    }

    // ----------------------------------------------------------------- plumbing

    /**
     * Send to a member, if the gate says this message is theirs to receive.
     *
     * @param  array<string, string>  $facts
     */
    private function send(
        User $recipient,
        NotificationKind $kind,
        Circle $circle,
        string $headline,
        array $facts,
        string $url,
        ?string $note = null,
        ?CircleResource $resource = null,
        ?Goal $subject = null,
    ): bool {
        $permission = $kind->requires();

        if ($permission !== null && ! $this->gate->allows($recipient, $permission, $circle, $resource, $subject)) {
            return false;
        }

        return $this->dispatch($recipient->email, $kind, $circle, $headline, $facts, $url, $note);
    }

    /**
     * Hand it to the mailer, and swallow anything that goes wrong.
     *
     * @param  array<string, string>  $facts
     */
    private function dispatch(
        string $email,
        NotificationKind $kind,
        Circle $circle,
        string $headline,
        array $facts,
        string $url,
        ?string $note = null,
    ): bool {
        if (! config('circle.notifications.enabled')) {
            return false;
        }

        try {
            // afterCommit, because some callers post a comment from inside an
            // enclosing transaction. A message about a state change that has
            // not committed yet can describe something that never happened.
            $mail = (new CircleNotification(
                kind: $kind,
                circleName: $circle->name,
                headline: $headline,
                facts: $facts,
                actionUrl: $url,
                note: $note,
            ))->afterCommit();

            Mail::to($email)->queue($mail);

            return true;
        } catch (\Throwable $e) {
            // Deliberately swallowed. The state change this describes is already
            // committed and in the audit chain; failing the request now would
            // undo a real thing because we could not talk about it.
            Log::warning('Notification not sent', [
                'kind'   => $kind->value,
                'circle' => $circle->id,
                'error'  => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Active members belonging to one party.
     *
     * `effectivePartyId()` rather than the raw column, so the convener's own
     * staff — who may have no party row — resolve to the convening party, the
     * same way the gate reads them.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function membersOfParty(Circle $circle, string $partyId): \Illuminate\Support\Collection
    {
        return $circle->memberships()
            ->whereNull('revoked_at')
            ->where('invite_status', 'active')
            ->with('user')
            ->get()
            ->filter(fn ($m) => $m->effectivePartyId() === $partyId)
            ->map(fn ($m) => $m->user)
            ->filter()
            ->unique('id')
            ->values();
    }

    private function url(string $path): string
    {
        return config('circle.notifications.web_url') . $path;
    }
}
