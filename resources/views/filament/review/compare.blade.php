
@include('filament.review.styles')
<div class="review-stack">
    <label class="review-filter">{{ __('review.version') }}
        <select wire:model.live="version"><option value="">{{ __('review.current_scores') }}</option>
            @foreach ($data['versions'] as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
        </select>
    </label>
    @if ($data['error'] !== null)<x-filament::section><p role="alert">{{ $data['error'] }}</p></x-filament::section>@endif
    <div class="review-grid">
        @foreach ($data['rows'] as $candidate)
            <x-filament::section :heading="$candidate['identity']">
                @if ($candidate['phone'] !== null)<p><bdi dir="ltr">{{ $candidate['phone'] }}</bdi></p>@endif
                @if ($candidate['email'] !== null)<p><bdi dir="ltr">{{ $candidate['email'] }}</bdi></p>@endif
                <x-filament::badge :color="match ($candidate['band']) { 'strong_match' => 'success', 'weak_match' => 'danger', default => 'warning' }">{{ __('review.bands.'.$candidate['band']) }}</x-filament::badge>
                <div class="review-number"><meter min="0" max="100" value="{{ $candidate['score'] ?? 0 }}" aria-label="{{ __('review.score') }}"></meter> {{ $candidate['score'] }}</div>
                <p>{{ __('review.coverage') }}: <bdi>{{ $candidate['coverage'] }}</bdi> · {{ __('review.version') }} <bdi>{{ $candidate['version'] }}</bdi></p>
                @foreach ($candidate['rows'] as $row)
                    <article class="review-outcome" data-result="{{ $row['result'] }}" style="margin-block:1rem">
                        <strong dir="auto">{{ $row['label'] }}</strong><div>{{ __('review.outcomes.'.$row['result']) }}</div>
                        <p class="review-muted">{{ __('review.confidence') }}: <bdi>{{ number_format((float) $row['confidence'] * 100, 0, '.', '') }}%</bdi> · {{ __('review.sources.'.$row['source']) }}</p>
                        @foreach ($row['evidence'] as $quote)
                            <blockquote dir="auto">{{ $quote['quote'] }}</blockquote>
                            @if ($quote['start'] !== null)<p class="review-muted">{{ __('review.document_anchor', ['document' => $quote['document_id'], 'start' => $quote['start'], 'end' => $quote['end']]) }}</p>@endif
                        @endforeach
                    </article>
                @endforeach
            </x-filament::section>
        @endforeach
    </div>
</div>

