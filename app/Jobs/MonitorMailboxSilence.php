<?php

namespace App\Jobs;

use App\Mail\IntakeAlertMail;
use App\Models\Mailbox;
use App\Services\Settings\SettingsRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;

/**
 * "No mail received in N hours" alert (brief §7, spec §9.4 HealthCheck). Runs
 * hourly; alerts at most once per silence period per mailbox. The alert carries
 * mailbox ids and thresholds only — never candidate data.
 */
final class MonitorMailboxSilence implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue((string) config('recruitment.queues.intake', 'intake'));
    }

    public function handle(SettingsRepository $settings): void
    {
        // One failed delivery must not skip the silence check of the other mailboxes.
        $failed = false;

        foreach (Mailbox::query()->where('active', true)->get() as $mailbox) {
            $hours = $mailbox->silence_alert_hours ?? $settings->int('intake.silence_alert_hours');
            $reference = $mailbox->last_message_at ?? $mailbox->start_at ?? $mailbox->created_at;

            if ($reference === null || $reference->diffInHours(now()) < $hours) {
                continue;
            }

            if ($mailbox->last_silence_alert_at !== null && $mailbox->last_silence_alert_at->gte($reference)) {
                // Already alerted for this silence period.
                continue;
            }

            activity('intake')
                ->event('intake.silence_alert')
                ->withProperties([
                    'mailbox_id' => $mailbox->id,
                    'hours' => $hours,
                    'last_message_at' => $reference->toIso8601String(),
                ])
                ->log('intake.silence_alert');

            try {
                $this->notify($mailbox, $hours);
            } catch (\Throwable) {
                $mailbox->forceFill(['last_error' => 'health_alert_delivery_failed'])->save();
                $failed = true;

                continue;
            }

            $mailbox->forceFill(['last_silence_alert_at' => now()])->save();
        }

        if ($failed) {
            throw new \RuntimeException('health_alert_delivery_failed');
        }
    }

    private function notify(Mailbox $mailbox, int $hours): void
    {
        $recipient = (string) config('recruitment.intake.health_alert_recipient', '');

        if ($recipient !== '') {
            Mail::mailer((string) config('recruitment.intake.notice_mailer'))->send(
                (new IntakeAlertMail(
                    alertSubject: (string) Lang::get('app.intake.silence_alert_subject', ['hours' => $hours], 'ar'),
                    body: (string) Lang::get('app.intake.silence_alert_body', ['address' => $mailbox->address, 'hours' => $hours], 'ar'),
                ))->to($recipient)
            );
        }
    }
}
