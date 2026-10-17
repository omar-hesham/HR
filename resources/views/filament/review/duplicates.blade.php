
@include('filament.review.styles')
<div class="review-stack">
    <p class="review-muted">{{ __('review.duplicate_help') }}</p>
    @forelse ($pairs as $pair)
        <x-filament::section>
            <div class="review-grid">
                @foreach (['existing', 'incoming'] as $side)
                    @php $person = $pair[$side]; $other = $pair[$side === 'existing' ? 'incoming' : 'existing']; @endphp
                    <article>
                        <h3>{{ __('review.'.$side) }}</h3>
                        @foreach (['name', 'email', 'phone'] as $field)
                            <p @class(['review-diff' => $person->{$field} !== $other->{$field}])><bdi dir="{{ $field === 'name' ? 'auto' : 'ltr' }}">{{ $person->{$field} }}</bdi></p>
                        @endforeach
                        <div @class(['review-diff' => $person->applications->pluck('recruitment_job_id')->sort()->values()->all() !== $other->applications->pluck('recruitment_job_id')->sort()->values()->all()])>
                            @foreach ($person->applications as $application)<p><bdi dir="auto">{{ $application->job?->title() ?? __('app.applications.unassigned') }}</bdi> · {{ $application->status->label() }}</p>@endforeach
                        </div>
                        @if ($person->activeDocument !== null && auth()->user()->can('download', $person->activeDocument))
                            <a href="{{ route('documents.download', $person->activeDocument->uuid) }}">{{ __('app.documents.download') }}</a>
                            <pre class="review-preview" dir="auto">{{ $person->activeDocument->latestExtraction?->normalizedText() }}</pre>
                            @if ($person->activeDocument->extension === 'pdf')
                                <iframe src="{{ route('documents.download', ['document' => $person->activeDocument->uuid, 'inline' => 1]) }}" title="{{ __('review.cv_preview') }}" style="width:100%;height:22rem;border:0" sandbox></iframe>
                            @endif
                        @endif
                    </article>
                @endforeach
            </div>
            <x-filament::button wire:click="mountAction('resolveDuplicate', { existing: {{ $pair['existing']->id }}, incoming: {{ $pair['incoming']->id }} })">{{ __('review.resolve_duplicate') }}</x-filament::button>
        </x-filament::section>
    @empty
        <x-filament::section>{{ __('review.empty') }}</x-filament::section>
    @endforelse
</div>

