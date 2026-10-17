<?php

use App\Domain\Intake\IntakeOutcome;
use App\Models\DocumentSource;
use App\Services\Intake\IntakeHealthSnapshot;

/** @var IntakeHealthSnapshot $snapshotService */
$snapshot = app(IntakeHealthSnapshot::class)->build();

$outcomeLabel = static fn (string $outcome): string => IntakeOutcome::tryFrom($outcome)?->label() ?? $outcome;
?>

<div class="space-y-6">
    <x-filament::section>
        <x-slot:title>{{ __('app.intake.section_overview') }}</x-slot>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            @foreach (['processed' => 'processed', 'ignored' => 'ignored', 'needs_review' => 'needs_review', 'pending' => 'pending', 'total' => 'total'] as $key => $label)
                <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __("app.intake.count_{$label}") }}</p>
                    <p class="mt-1 text-2xl font-semibold" dir="ltr">{{ number_format($snapshot['totals'][$key]) }}</p>
                    <p class="mt-1 text-xs text-gray-400">{{ __('app.intake.last_7_days') }}</p>
                </div>
            @endforeach
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('app.intake.quarantined') }}</p>
                <p class="mt-1 text-2xl font-semibold" dir="ltr">{{ number_format($snapshot['quarantined']) }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('app.intake.notices_sent') }}</p>
                <p class="mt-1 text-2xl font-semibold" dir="ltr">{{ number_format($snapshot['notices']['sent']) }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('app.intake.notices_failed') }}</p>
                <p class="mt-1 text-2xl font-semibold" dir="ltr">{{ number_format($snapshot['notices']['failed']) }}</p>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot:title>{{ __('app.intake.section_mailboxes') }}</x-slot>

        @if (count($snapshot['mailboxes']) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('app.intake.no_mailboxes') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr class="text-gray-500 dark:text-gray-400">
                            <th class="px-3 py-2 text-start">{{ __('app.intake.mailbox') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('app.intake.last_poll') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('app.intake.last_message') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('app.intake.counts') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('app.intake.open_reviews') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('app.intake.silence') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('app.common.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($snapshot['mailboxes'] as $mailbox)
                            <tr class="border-t border-gray-100 dark:border-gray-700">
                                <td class="px-3 py-2">
                                    <span dir="ltr">{{ $mailbox['address'] }}</span>
                                    <span class="block text-xs text-gray-400" dir="ltr">{{ $mailbox['provider'] }}</span>
                                </td>
                                <td class="px-3 py-2">{{ $mailbox['last_success_at']?->timezone(config('recruitment.display_timezone'))->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $mailbox['last_message_at']?->timezone(config('recruitment.display_timezone'))->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs" dir="ltr">
                                    @foreach ($mailbox['counts'] as $outcome => $count)
                                        <span class="me-2">{{ $outcomeLabel((string) $outcome) }}: {{ (int) $count }}</span>
                                    @endforeach
                                    @if (count($mailbox['counts']) === 0)— @endif
                                </td>
                                <td class="px-3 py-2" dir="ltr">{{ $mailbox['open_reviews'] }}</td>
                                <td class="px-3 py-2">
                                    @if ($mailbox['silence']['alerted'])
                                        <span class="text-sm font-semibold text-danger-600">{{ __('app.intake.silence_alerted') }}</span>
                                    @else
                                        <span class="text-sm" dir="ltr">{{ $mailbox['silence']['silent_hours'] === null ? '—' : __('app.intake.hours_elapsed', ['elapsed' => $mailbox['silence']['silent_hours'], 'threshold' => $mailbox['silence']['threshold_hours']]) }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    @if ($mailbox['last_error'] !== null && $mailbox['last_error'] !== '')
                                        <span class="font-semibold text-danger-600" dir="ltr">{{ __('app.intake.error.'.$mailbox['last_error']) }}</span>
                                    @elseif ($mailbox['active'])
                                        <span class="font-semibold text-success-600">{{ __('app.intake.active') }}</span>
                                    @else
                                        <span class="text-gray-400">{{ __('app.intake.inactive') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot:title>{{ __('app.intake.section_errors') }}</x-slot>

        @if (count($snapshot['errors']) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('app.intake.no_errors') }}</p>
        @else
            <ul class="space-y-2 text-sm">
                @foreach ($snapshot['errors'] as $error)
                    <li class="rounded-lg bg-white p-3 shadow-sm dark:bg-gray-800">
                        <span class="font-semibold text-danger-600" dir="ltr">{{ __('app.intake.error.'.$error['error']) }}</span>
                        <span class="block text-xs text-gray-400" dir="ltr">#{{ $error['external_id'] }} — {{ $error['at']->timezone(config('recruitment.display_timezone'))->format('d/m/Y H:i') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</div>
