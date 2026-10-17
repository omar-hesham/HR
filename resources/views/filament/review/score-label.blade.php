@if ($score !== null)
    <div><x-filament::badge :color="match ($score->band) { 'strong_match' => 'success', 'weak_match' => 'danger', default => 'warning' }">{{ __('review.bands.'.$score->band) }}</x-filament::badge></div>
    <div class="review-number"><meter min="0" max="100" value="{{ $score->score ?? 0 }}" aria-label="{{ __('review.score') }}"></meter> {{ $score->score ?? '—' }}</div>
    <div class="review-muted">{{ __('review.coverage') }}: <bdi>{{ $score->coverage }}</bdi> · {{ __('review.version') }} <bdi>{{ $score->criteriaVersion->version }}</bdi></div>
    @if ($score->overrides->isNotEmpty())
        <div>{{ __('review.override') }}: <bdi>{{ $score->overrides->sortByDesc('id')->first()->new_value }}</bdi></div>
    @endif
@endif

