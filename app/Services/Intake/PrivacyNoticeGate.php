<?php

namespace App\Services\Intake;

use App\Jobs\SendPrivacyNotice;
use App\Models\IntakeMessage;
use App\Models\NoticeOutbox;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * Decides whether a privacy notice may be queued for a message (M10-P0-01,
 * brief §6): at most one per sender (sender_hmac) within the configured
 * window; one idempotency key per message; never for auto-generated senders
 * (checked by the caller via AutoReplyDetector).
 */
final class PrivacyNoticeGate
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function shouldQueue(IntakeMessage $message): bool
    {
        $sender = trim((string) $message->sender_enc);

        if ($sender === '' || $message->sender_hmac === null) {
            return false;
        }

        $windowDays = $this->settings->int('intake.privacy_notice_resend_days');

        return ! NoticeOutbox::query()
            ->join('intake_messages', 'intake_messages.id', '=', 'notice_outbox.intake_message_id')
            ->where('intake_messages.sender_hmac', $message->sender_hmac)
            ->where('notice_outbox.created_at', '>=', now()->subDays($windowDays))
            ->exists();
    }

    public function queue(IntakeMessage $message): ?NoticeOutbox
    {
        return Cache::lock('notice-sender-'.$message->sender_hmac, 60)
            ->block(5, fn () => $this->reserve($message));
    }

    private function reserve(IntakeMessage $message): ?NoticeOutbox
    {
        if (! $this->shouldQueue($message)) {
            return null;
        }

        try {
            $notice = NoticeOutbox::query()->create([
                'intake_message_id' => $message->id,
                'application_id' => $message->documentSources()
                    ->whereNotNull('application_id')
                    ->value('application_id'),
                'channel' => 'email',
                'notice_version' => (string) $this->settings->get('privacy.notice_version'),
                'idempotency_key' => hash('sha256', $message->sender_hmac.'|'.$message->id),
                'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already queued for this exact message (unique idempotency key).
            return null;
        }

        SendPrivacyNotice::dispatch($notice->id)->afterCommit();

        return $notice;
    }
}
