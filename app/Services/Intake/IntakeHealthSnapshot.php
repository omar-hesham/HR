<?php

namespace App\Services\Intake;

use App\Domain\Documents\ReviewReason;
use App\Models\DocumentSource;
use App\Models\IntakeMessage;
use App\Models\Mailbox;
use App\Models\NoticeOutbox;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Carbon;

/**
 * Read-model for the intake health page (M11-P0-01): last poll, counts per
 * outcome, errors, quarantine and notice stats. Ids and codes only — no
 * candidate data, no sender addresses.
 */
final class IntakeHealthSnapshot
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'mailboxes' => $this->mailboxes(),
            'totals' => $this->totals(),
            'notices' => $this->notices(),
            'quarantined' => DocumentSource::query()
                ->where('reason', ReviewReason::MalwareDetected->value)
                ->count(),
            'errors' => $this->errors(),
            'generated_at' => now(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mailboxes(): array
    {
        $rows = [];

        foreach (Mailbox::query()->orderBy('address')->get() as $mailbox) {
            $counts = IntakeMessage::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy('outcome')
                ->selectRaw('outcome, count(*) as total')
                ->pluck('total', 'outcome');

            $openReviews = DocumentSource::query()
                ->whereHas('intakeMessage', static fn ($query) => $query->where('mailbox_id', $mailbox->id))
                ->openReview()
                ->count();

            $threshold = $mailbox->silence_alert_hours ?? $this->settings->int('intake.silence_alert_hours');
            $reference = $mailbox->last_message_at ?? $mailbox->start_at;

            $rows[] = [
                'id' => $mailbox->id,
                'address' => $mailbox->address,
                'provider' => $mailbox->provider,
                'active' => $mailbox->active,
                'last_success_at' => $mailbox->last_success_at,
                'last_message_at' => $mailbox->last_message_at,
                'last_error' => $mailbox->last_error,
                'cursor' => $mailbox->cursor,
                'uidvalidity' => $mailbox->uidvalidity,
                'counts' => $counts,
                'open_reviews' => $openReviews,
                'silence' => [
                    'threshold_hours' => $threshold,
                    'alerted' => $mailbox->last_silence_alert_at !== null && $reference !== null && $mailbox->last_silence_alert_at->gte($reference),
                    'silent_hours' => $reference === null ? null : (int) round(abs($reference->diffInHours(now()))),
                ],
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    private function totals(): array
    {
        $counts = IntakeMessage::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->groupBy('outcome')
            ->selectRaw('outcome, count(*) as total')
            ->pluck('total', 'outcome');

        return [
            'processed' => (int) ($counts['processed'] ?? 0),
            'ignored' => (int) ($counts['ignored_with_reason'] ?? 0),
            'needs_review' => (int) ($counts['needs_review'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'total' => (int) $counts->sum(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function notices(): array
    {
        $since = now()->subDays(7);

        return [
            'pending' => NoticeOutbox::query()->where('status', 'pending')->where('created_at', '>=', $since)->count(),
            'sent' => NoticeOutbox::query()->where('status', 'sent')->where('created_at', '>=', $since)->count(),
            'failed' => NoticeOutbox::query()->whereIn('status', ['failed', 'sending', 'uncertain'])->where('created_at', '>=', $since)->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function errors(): array
    {
        $rows = [];

        $failing = IntakeMessage::query()
            ->whereNotNull('last_error')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'mailbox_id', 'external_id', 'last_error', 'created_at']);

        foreach ($failing as $message) {
            $rows[] = [
                'mailbox_id' => $message->mailbox_id,
                'external_id' => $message->external_id,
                'error' => $message->last_error,
                'at' => Carbon::instance($message->created_at),
            ];
        }

        return $rows;
    }
}
