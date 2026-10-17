<?php

namespace App\Jobs;

use App\Mail\IntakeAlertMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/** Approved operational channel only; no applicant identifiers or CV content. */
final class NotifyAiOperator implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $provider)
    {
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $recipient = (string) config('recruitment.intake.health_alert_recipient', '');
        if ($recipient === '') {
            return;
        }
        try {
            Mail::mailer((string) config('recruitment.intake.notice_mailer'))->send((new IntakeAlertMail(__('ai.alert_subject', [], 'ar'), __('ai.alert_body', ['driver' => $this->provider], 'ar')))->to($recipient));
        } catch (Throwable) {
            throw new RuntimeException('ai_operator_alert_failed');
        }
    }

    public function failed(?Throwable $exception): void
    {
        activity('operations')->event('ai.operator_alert_failed')->withProperties(['provider' => $this->provider])->log('ai.operator_alert_failed');
    }
}
