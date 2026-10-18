
@include('filament.review.styles')
@php $duration = app(\App\Services\Review\HumanDuration::class); @endphp
<div class="review-stack">
    <x-filament::section :heading="__('review.weekly')">
        <label class="review-filter">{{ __('review.week') }}<input type="date" wire:model.live="week"></label>
        <p><bdi>{{ $data['week_start'] }} — {{ $data['week_end'] }}</bdi></p>
        <p class="review-muted">{{ __('review.report_help') }} {{ $data['global'] ? __('review.global_scope') : __('review.scoped_scope') }}</p>
        <div class="review-grid">
            @foreach ($data['counts'] as $metric => $count)
                <article><strong>{{ __('review.metrics.'.$metric) }}</strong><div class="review-number">{{ $count }}</div><div class="review-muted">{{ __('review.units.'.$data['units'][$metric]) }}</div></article>
            @endforeach
        </div>
    </x-filament::section>
    <x-filament::section :heading="__('review.kpis')">
        <div class="review-grid">
            <article>{{ __('review.worst_visibility') }}<div class="review-number" title="{{ $data['worst'] !== null ? $data['worst'].' '.__('review.units.seconds') : '' }}">{{ $data['worst'] !== null ? $duration->format((int) $data['worst']) : '—' }}</div></article>
            <article>{{ __('review.p95_visibility') }}<div class="review-number" title="{{ $data['p95'] !== null ? $data['p95'].' '.__('review.units.seconds') : '' }}">{{ $data['p95'] !== null ? $duration->format((int) $data['p95']) : '—' }}</div></article>
            <article>{{ __('review.pending_visibility') }}<div class="review-number">{{ $data['pending'] }}</div></article>
            <article>{{ __('review.active_users') }}<div class="review-number">{{ $data['active_users'] }} {{ __('review.units.users') }}</div></article>
            <article>{{ __('review.cloud_cap') }}<div class="review-number">{{ $data['cloud'] }} / {{ $data['cap'] }} {{ __('review.units.egp') }}</div></article>
            <article>{{ __('review.pilot_time') }}<div class="review-number">{{ $data['pilot'] ?? '—' }} {{ __('review.minutes') }}</div></article>
            <article>{{ __('review.backup_health') }}<div class="review-muted">{{ ! $data['backup']['configured'] ? __('review.backup_pending') : ($data['backup']['fresh'] ? __('ops.backup_fresh') : __('ops.backup_stale')) }}</div></article>
        </div>
    </x-filament::section>
    <x-filament::section :heading="__('review.visibility_all')">
        <div class="review-scroll"><table class="review-table">
            <thead><tr><th>{{ __('review.unit') }}</th><th>{{ __('review.reference_label') }}</th><th>{{ __('review.received') }}</th><th>{{ __('review.visible') }}</th><th>{{ __('review.seconds') }}</th></tr></thead>
            <tbody>@foreach ($data['visibility'] as $row)
                <tr><td>{{ __('review.units.'.$row['kind']) }}</td><td><bdi>{{ $row['id'] }}</bdi></td><td class="review-number">{{ $row['received']->timezone('Africa/Cairo')->format('d/m/Y H:i:s') }}</td><td class="review-number">{{ $row['visible']?->timezone('Africa/Cairo')->format('d/m/Y H:i:s') ?? __('review.pending') }}</td><td class="review-number" title="{{ $row['seconds'] }} {{ __('review.units.seconds') }}">{{ $duration->format((int) $row['seconds']) }}</td></tr>
            @endforeach</tbody>
        </table></div>
    </x-filament::section>
    <x-filament::section :heading="__('review.exception_age')">
        <table class="review-table"><thead><tr><th>{{ __('review.reference_label') }}</th><th>{{ __('review.seconds') }}</th><th>{{ __('review.owners') }}</th></tr></thead><tbody>
        @foreach ($data['exceptions'] as $row)
            <tr><td><bdi>{{ $row['id'] }}</bdi></td><td class="review-number" title="{{ $row['seconds'] }} {{ __('review.units.seconds') }}">{{ $duration->format((int) $row['seconds']) }}</td><td>@foreach ($row['owners'] as $owner)<bdi dir="auto">{{ $row['owner_names'][$owner] ?? __('review.actor_id', ['id' => $owner]) }}</bdi> @endforeach</td></tr>
        @endforeach
        </tbody></table>
    </x-filament::section>
</div>

