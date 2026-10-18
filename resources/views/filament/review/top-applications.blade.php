<x-filament::section :heading="__('brain.top_title')" data-tour="top-three">
    <p>{{ __('brain.suggestion') }}</p>
    <div class="review-grid" style="margin-block-start:1rem">
        @forelse ($data['top'] as $card)
            <x-filament::section :heading="$card['title']" data-top-job="{{ $card['job_id'] }}">
                <ol class="review-stack">
                    @forelse ($card['entries'] as $entry)
                        <li data-top-application="{{ $entry['id'] }}" class="review-outcome">
                            <strong><bdi>{{ $loop->iteration }}.</bdi> <bdi dir="auto">{{ $entry['identity'] }}</bdi></strong>
                            <div>{{ __('review.score') }}: <bdi>{{ $entry['score'] }}%</bdi> · {{ __('review.bands.'.$entry['band']) }}</div>
                            <div>{{ __('review.coverage') }}: <bdi>{{ $entry['coverage'] }}%</bdi></div>
                            @forelse ($entry['quotes'] as $quote)
                                <blockquote><bdi dir="auto">{{ $quote['quote'] }}</bdi> <small>({{ __('review.sources.'.$quote['source']) }})</small></blockquote>
                            @empty
                                <p class="review-muted">{{ __('brain.no_quotes') }}</p>
                            @endforelse
                            <p>{{ __('brain.missing') }}:</p>
                            @forelse ($entry['missing'] as $missing)
                                <div>{{ $missing['label'] }} — {{ __('review.outcomes.'.$missing['outcome']) }}</div>
                            @empty
                                <p class="review-muted">{{ __('brain.none_missing') }}</p>
                            @endforelse
                            <a href="{{ \App\Filament\Resources\Applications\ApplicationResource::getUrl('view', ['record' => $entry['id']]) }}">{{ __('brain.detail') }}</a>
                        </li>
                    @empty
                        <li>{{ __('brain.no_ranked') }}</li>
                    @endforelse
                </ol>
            </x-filament::section>
        @empty
            <p>{{ __('brain.no_ranked') }}</p>
        @endforelse
    </div>
</x-filament::section>
