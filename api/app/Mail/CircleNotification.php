<?php

namespace App\Mail;

use App\Enums\NotificationKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Every message the product sends, which is deliberately one class.
 *
 * Five kinds share one shape because the shape is the point: what happened, the
 * few facts you need to judge whether it matters, and one link to the place you
 * would act on it. A message that needed its own layout would be a message
 * carrying more than a person can read on a phone between meetings, and the
 * answer to that is a shorter message rather than a better template.
 *
 * Queued, so a mail provider being slow or down cannot fail the request that
 * caused it. The state change has already been committed and audited by the
 * time this is dispatched; the notification is an attempt to tell somebody
 * about a fact, not part of the fact.
 */
class CircleNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, string>  $facts  Label => value, rendered in order.
     */
    public function __construct(
        public readonly NotificationKind $kind,
        public readonly string $circleName,
        public readonly string $headline,
        public readonly array $facts,
        public readonly string $actionUrl,
        public readonly ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->kind->subject($this->circleName));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.circle-notification',
            with: [
                'kind'         => $this->kind,
                'circleName'   => $this->circleName,
                'headline'     => $this->headline,
                'facts'        => $this->facts,
                'actionUrl'    => $this->actionUrl,
                'actionLabel'  => $this->kind->callToAction(),
                'note'         => $this->note,
            ],
        );
    }
}
