<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Lang;

/**
 * The approved privacy notice (M10-P0-01): Arabic then English in one message,
 * sent through the dedicated notice mailer. The text comes from settings with
 * the lang file as fallback (D-114); the subject is always the approved
 * Arabic copy from lang.
 */
final class PrivacyNoticeMail extends Mailable
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(public readonly string $body)
    {
        $this->subject = (string) Lang::get('app.privacy.notice_subject', [], 'ar');
        $this->onQueue((string) config('recruitment.queues.intake', 'intake'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subject);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'Auto-Submitted' => 'auto-generated',
            'X-Auto-Response-Suppress' => 'All',
        ]);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.privacy-notice');
    }
}
