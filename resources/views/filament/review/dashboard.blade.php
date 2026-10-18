@php

    $viewer = auth()->user();
    $presenter = app(\App\Services\Review\ReviewApplications::class);
@endphp
@include('filament.review.styles')
<div class="review-stack">
    <div role="status" aria-live="polite" class="review-grid">
        <x-filament::section><strong>{{ __('review.unscored') }}: <bdi>{{ $data['unscored_count'] }}</bdi></strong></x-filament::section>
        <x-filament::section><strong>{{ __('review.needs_review') }}: <bdi>{{ $data['needs_review_count'] }}</bdi></strong></x-filament::section>
        <x-filament::section><strong>{{ __('review.needs_recalculation') }}: <bdi>{{ $data['stale_count'] }}</bdi></strong></x-filament::section>
    </div>
    @foreach (['exceptions' => __('review.exceptions'), 'rescore' => __('review.rescore_section')] as $sectionKey => $sectionHeading)
    <x-filament::section :heading="$sectionHeading">
        <p class="review-muted">{{ __('review.pinned_help') }}</p>
        <div class="review-scroll">
            <table class="review-table" data-section="{{ $sectionKey }}">
                <thead><tr><th>{{ __('app.candidates.singular') }}</th><th>{{ __('app.jobs.singular') }}</th><th>{{ __('app.common.status') }}</th><th>{{ __('review.age') }}</th><th>{{ __('review.latest_reason') }}</th><th>{{ __('review.score') }}</th><th>{{ __('review.actions') }}</th></tr></thead>
                <tbody>
                @forelse ($data[$sectionKey] as $application)
                    <tr wire:key="exception-{{ $application->id }}">
                        <td>
                            @if ($application->currentScore?->score !== null)
                                <input type="checkbox" wire:model="selection" value="{{ $application->id }}" data-compare-id="{{ $application->id }}" aria-label="{{ __('review.select') }} {{ substr($application->uuid, 0, 8) }}">
                            @endif
                            <a href="{{ \App\Filament\Resources\Applications\ApplicationResource::getUrl('view', ['record' => $application]) }}"><bdi dir="auto">{{ $presenter->identity($viewer, $application) }}</bdi></a>
                        </td>
                        <td><bdi dir="auto">{{ $application->job?->title() ?? __('app.applications.unassigned') }}</bdi></td>
                        <td>{{ $application->status->label() }}</td>
                        <td class="review-number">{{ (int) $application->received_at->diffInHours(now()) }} {{ __('review.hours') }}</td>
                        <td>{{ $application->review_reason?->label() ?? app(\App\Services\Ai\AiWorkCoordinator::class)->labelForApplication($application->id) ?? ($application->current_score_id !== null ? '—' : __('review.awaiting_evaluation')) }}</td>
                        <td>
                            @if ($application->currentScore?->score !== null)
                                @if ($presenter->isCurrent($application))
                                    @include('filament.review.score-label', ['score' => $application->currentScore])
                                @else
                                    <x-filament::badge color="warning">{{ __('review.needs_recalculation') }}</x-filament::badge>
                                    @include('filament.review.score-label', ['score' => $application->currentScore])
                                @endif
                                <span class="review-muted">{{ __('review.version') }} {{ $application->currentScore->criteriaVersion->version }}</span>
                            @else
                                {{ __('review.unscored') }}
                            @endif
                        </td>
                        <td>
                            @if ($this->history)
                                <details><summary>{{ __('review.history') }}</summary>
                                @foreach ($application->scores->sortByDesc('id') as $historical)
                                    <div data-history-score="{{ $historical->id }}">{{ __('review.version') }} {{ $historical->criteriaVersion->version }} · <bdi>{{ $historical->score }}</bdi></div>
                                @endforeach
                                </details>
                            @endif
                            @if (\App\Domain\Access\ReviewAccess::manage($viewer, $application) && $application->job?->current_criteria_version_id !== null)
                                <x-filament::button size="sm" wire:click="rescore({{ $application->id }})">{{ __('review.rescore') }}</x-filament::button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">{{ __('review.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $data[$sectionKey]->links() }}
    </x-filament::section>

    @endforeach
    <x-filament::section :heading="__('review.current_ranking')">
        <div class="review-grid">
            <label class="review-filter">{{ __('app.jobs.singular') }}
                <select wire:model.live="job"><option value="">{{ __('review.all') }}</option><option value="unassigned">{{ __('app.applications.unassigned') }}</option>@foreach ($data['jobs'] as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
            </label>
            <label class="review-filter">{{ __('review.band') }}
                <select wire:model.live="band"><option value="">{{ __('review.all') }}</option>@foreach (['strong_match', 'needs_review', 'weak_match'] as $value)<option value="{{ $value }}">{{ __('review.bands.'.$value) }}</option>@endforeach</select>
            </label>
            <label class="review-filter">{{ __('app.common.status') }}
                <select wire:model.live="status"><option value="">{{ __('review.all') }}</option>@foreach (\App\Domain\Applications\ApplicationStatus::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            </label>
            <label class="review-filter">{{ __('app.common.source') }}
                <select wire:model.live="source"><option value="">{{ __('review.all') }}</option>@foreach (\App\Domain\Documents\SourceChannel::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            </label>
            <label class="review-filter">{{ __('review.latest_reason') }}
                <select wire:model.live="exception"><option value="">{{ __('review.all') }}</option>@foreach (\App\Domain\Documents\ReviewReason::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    <option value="awaiting_service">{{ __('review.awaiting_evaluation') }}</option><option value="awaiting_budget">{{ __('ai.states.awaiting_budget') }}</option>
                </select>
            </label>
            <label class="review-filter">{{ __('review.from') }}<input type="date" wire:model.live="from"></label>
            <label class="review-filter">{{ __('review.until') }}<input type="date" wire:model.live="until"></label>
        </div>
        <div style="display:flex;gap:1rem;align-items:center;margin-block:1rem">
            <label><input type="checkbox" wire:model.live="history"> {{ __('review.show_history') }}</label>
            <x-filament::button wire:click="compareSelection">{{ __('review.compare_selected') }}</x-filament::button>
        </div>
        @error('selection')<p role="alert">{{ $message }}</p>@enderror
        <div class="review-scroll">
            <table class="review-table" data-section="ranked">
                <thead><tr><th>{{ __('review.select') }}</th><th>{{ __('app.candidates.singular') }}</th><th>{{ __('app.jobs.singular') }}</th><th>{{ __('review.score') }}</th><th>{{ __('app.common.status') }}</th><th>{{ __('review.age') }}</th><th>{{ __('app.common.received_at') }}</th></tr></thead>
                <tbody>
                @forelse ($data['ranked'] as $application)
                    <tr wire:key="ranked-{{ $application->id }}">
                        <td><input type="checkbox" wire:model="selection" value="{{ $application->id }}" aria-label="{{ __('review.select') }} {{ substr($application->uuid, 0, 8) }}"></td>
                        <td>
                            <a href="{{ \App\Filament\Resources\Applications\ApplicationResource::getUrl('view', ['record' => $application]) }}"><bdi dir="auto">{{ $presenter->identity($viewer, $application) }}</bdi></a>
                            @unless ($presenter->blind($viewer, $application))
                                <div><bdi dir="ltr">{{ $application->candidate->phone }}</bdi></div><div><bdi dir="ltr">{{ $application->candidate->email }}</bdi></div>
                            @endunless
                        </td>
                        <td><bdi dir="auto">{{ $application->job?->title() }}</bdi></td>
                        <td>
                            @include('filament.review.score-label', ['score' => $application->currentScore])
                            @if ($this->history)
                                <details><summary>{{ __('review.history') }}</summary>
                                @foreach ($application->scores->sortByDesc('id') as $historical)
                                    <div data-history-score="{{ $historical->id }}">{{ __('review.version') }} {{ $historical->criteriaVersion->version }} · <bdi>{{ $historical->score }}</bdi> · {{ __('review.bands.'.$historical->band) }}</div>
                                @endforeach
                                </details>
                            @endif
                        </td>
                        <td>{{ $application->status->label() }}<div class="review-muted">{{ $application->review_reason?->label() ?? app(\App\Services\Ai\AiWorkCoordinator::class)->labelForApplication($application->id) }}</div></td>
                        <td class="review-number">{{ (int) $application->received_at->diffInHours(now()) }} {{ __('review.hours') }}</td>
                        <td class="review-number">{{ $application->received_at->timezone('Africa/Cairo')->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">{{ __('review.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $data['ranked']->links() }}
    </x-filament::section>
</div>

