<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one message that is not about a Circle.
 *
 * Every other thing this system sends concerns work inside a Circle the
 * recipient belongs to, and CircleNotification puts each of them through the
 * access gate before speaking. This one cannot: the person asking has, by
 * definition, lost the ability to prove who they are. What bounds it instead is
 * that it says nothing — no Circle names, no parties, no indication of what the
 * account can reach. It is a link and an expiry.
 */
class PasswordReset extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $resetUrl,
        public readonly int $expiresMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your Circle password');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.password-reset',
            with: [
                'resetUrl'       => $this->resetUrl,
                'expiresMinutes' => $this->expiresMinutes,
            ],
        );
    }
}
