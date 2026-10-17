<?php

namespace App\Jobs;

use App\Mail\IntakeAlertMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class NotifyOperations implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $code) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $recipient = (string) config('recruitment.intake.health_alert_recipient');
        if ($recipient === '') {
            return;
        }
        try {
            Mail::mailer((string) config('recruitment.intake.notice_mailer'))->send((new IntakeAlertMail(__('ops.alert_subject', [], 'ar'), __('ops.alert_body', ['code' => $this->code], 'ar')))->to($recipient));
        } catch (Throwable) {
            throw new RuntimeException('operations_alert_failed');
        }
    }

    public function failed(?Throwable $exception): void
    {
        activity('operations')->event('operations.alert_failed')->withProperties(['code' => $this->code])->log('operations.alert_failed');
    }
}
