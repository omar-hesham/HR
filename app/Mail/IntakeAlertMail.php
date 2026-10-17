<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Operational intake alert (no-mail silence, spec §9.4 HealthCheck). Carries
 * mailbox ids and thresholds only — never candidate data.
 */
final class IntakeAlertMail extends Mailable
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(
        public readonly string $alertSubject,
        public readonly string $body,
    ) {
        $this->onQueue((string) config('recruitment.queues.intake', 'intake'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alertSubject);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.intake-alert');
    }
}
