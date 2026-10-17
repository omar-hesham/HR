<?php

namespace App\Jobs;

use App\Mail\PrivacyNoticeMail;
use App\Models\IntakeMessage;
use App\Models\NoticeOutbox;
use App\Services\Settings\SettingsRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the approved privacy notice (Arabic then English, M10-P0-01) through the
 * dedicated notice mailer (spec §5.3 separate dispatch credentials). Notice
 * text comes from settings, falling back to the lang files (D-114).
 */
final class SendPrivacyNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $noticeId)
    {
        $this->onQueue((string) config('recruitment.queues.intake', 'intake'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        Cache::lock('notice-delivery-'.$this->noticeId, 120)
            ->block(5, fn () => $this->deliver());
    }

    private function deliver(): void
    {
        $notice = NoticeOutbox::query()->find($this->noticeId);

        if ($notice === null || $notice->status !== 'pending') {
            return;
        }

        $notice->forceFill(['attempts' => $notice->attempts + 1])->save();

        /** @var IntakeMessage|null $message */
        $message = $notice->intakeMessage;
        $sender = trim((string) ($message->sender_enc ?? ''));

        if ($sender === '') {
            $notice->forceFill(['status' => 'failed', 'last_error' => 'no_sender_address'])->save();

            return;
        }

        $body = $this->text('ar')."\n\n".str_repeat('—', 20)."\n\n".$this->text('en');

        $notice->forceFill(['status' => 'sending'])->save();

        try {
            Mail::mailer((string) config('recruitment.intake.notice_mailer'))
                ->send((new PrivacyNoticeMail($body))->to($sender));
        } catch (Throwable) {
            $notice->forceFill(['status' => 'uncertain', 'last_error' => 'notice_delivery_uncertain'])->save();
            throw new \RuntimeException('notice_delivery_uncertain');
        }

        $notice->forceFill(['status' => 'sent', 'sent_at' => now(), 'last_error' => null])->save();

        activity('intake')
            ->event('privacy_notice.sent')
            ->withProperties(['notice_id' => $notice->id, 'intake_message_id' => $message?->id])
            ->log('privacy_notice.sent');
    }

    /**
     * Called by the queue after the last attempt fails.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Privacy notice failed permanently.', [
            'notice_id' => $this->noticeId,
            'error' => $exception === null ? null : $exception::class,
        ]);

        $notice = NoticeOutbox::query()->find($this->noticeId);

        if ($notice !== null && ! in_array($notice->status, ['sent', 'sending', 'uncertain'], true)) {
            $notice->forceFill([
                'status' => 'failed',
                'last_error' => 'notice_delivery_failed',
            ])->save();
        }
    }

    private function text(string $locale): string
    {
        /** @var array<string, mixed> $all */
        $all = app(SettingsRepository::class)->all();

        $value = (string) ($all['privacy.notice_text_'.$locale] ?? '');

        if ($value !== '') {
            return $value;
        }

        return (string) Lang::get('app.privacy.notice_body', [], $locale);
    }
}
