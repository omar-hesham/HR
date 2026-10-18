@php $application = $data['application']; $candidate = $application->candidate; @endphp
@include('filament.review.styles')
<div class="review-stack">
    <x-filament::section :heading="__('review.profile')">
        <div class="review-grid">
            <div><strong><bdi dir="auto">{{ $candidate->displayName() }}</bdi></strong><div><bdi dir="ltr">{{ $candidate->phone }}</bdi></div><div><bdi dir="ltr">{{ $candidate->email }}</bdi></div></div>
            <div><bdi dir="auto">{{ $application->job?->title() ?? __('app.applications.unassigned') }}</bdi><div>{{ $application->status->label() }}</div><div class="review-muted">{{ $application->review_reason?->label() }}</div></div>
            <div>{{ __('review.received') }}: <bdi>{{ $application->received_at->timezone('Africa/Cairo')->format('d/m/Y H:i') }}</bdi></div>
        </div>
        <p class="review-muted">{{ __('review.internal_only') }}</p>
        @if ($data['document'] !== null && auth()->user()->can('download', $data['document']))
            <a href="{{ route('documents.download', $data['document']->uuid) }}">{{ __('app.documents.download') }}</a>
            @if ($data['document']->extension === 'pdf')
                <iframe src="{{ route('documents.download', ['document' => $data['document']->uuid, 'inline' => 1]) }}" title="{{ __('review.cv_preview') }}" style="width:100%;height:28rem;border:0;margin-block:1rem" sandbox></iframe>
            @endif
        @endif
    </x-filament::section>
    @if ($data['score'] !== null)
        @php $score = $data['score']['score']; @endphp
        <x-filament::section :heading="__('review.criteria')">
            @include('filament.review.score-label')
            <p dir="auto" style="margin-block:1rem">{{ $score->reason_enc }}</p>
            @if ($data['score']['enrichment'] !== null)<p dir="auto">{{ $data['score']['enrichment'] }}</p>@endif
            <div class="review-scroll">
                <table class="review-table"><thead><tr><th>{{ __('review.criterion') }}</th><th>{{ __('review.outcome') }}</th><th>{{ __('review.confidence') }}</th><th>{{ __('app.common.source') }}</th><th>{{ __('review.evidence') }}</th></tr></thead><tbody>
                @foreach ($data['score']['rows'] as $row)
                    <tr data-result="{{ $row['result'] }}">
                        <td dir="auto">{{ app()->getLocale() === 'ar' ? $row['criterion']->label_ar : $row['criterion']->label_en }}
                            @foreach ($row['normalizations'] as $normalization)
                                <div class="review-muted" dir="auto">{{ __('review.normalized_value', ['value' => is_bool($normalization['value']) ? json_encode($normalization['value']) : $normalization['value'], 'from' => $normalization['normalized_from']]) }}</div>
                            @endforeach
                        </td>
                        <td><div class="review-outcome" data-result="{{ $row['result'] }}">{{ __('review.outcomes.'.$row['result']) }}</div></td>
                        <td class="review-number">{{ number_format((float) $row['confidence'] * 100, 0, '.', '') }}%</td>
                        <td>{{ __('review.sources.'.$row['source']) }}</td>
                        <td>@foreach ($row['evidence'] as $evidence)
                            <blockquote dir="auto">{{ $evidence['quote'] ?? '' }}</blockquote>
                            @if (isset($evidence['document_id'], $evidence['start'], $evidence['end']))
                                <a class="review-muted" href="#evidence-{{ $evidence['document_id'] }}-{{ $evidence['start'] }}">{{ __('review.document_anchor', ['document' => $evidence['document_id'], 'start' => $evidence['start'], 'end' => $evidence['end']]) }}</a>
                            @else
                                <span class="review-muted">{{ __('review.human_statement') }}</span>
                            @endif
                        @endforeach</td>
                    </tr>
                @endforeach
                </tbody></table>
            </div>
        </x-filament::section>
        @if ($score->extraction?->document !== null && auth()->user()->can('download', $score->extraction->document))
            <x-filament::section :heading="__('review.normalized_preview')"><pre class="review-preview" dir="auto">{{ $data['score']['preview'] }}</pre></x-filament::section>
        @endif
    @elseif ($data['document']?->latestExtraction !== null && auth()->user()->can('download', $data['document']))
        <x-filament::section :heading="__('review.normalized_preview')"><pre class="review-preview" dir="auto">{{ $data['document']->latestExtraction->normalizedText() }}</pre></x-filament::section>
    @endif
    <x-filament::section :heading="__('review.history')">
        @foreach ($data['scores'] as $historical)
            <details><summary>{{ __('review.version') }} {{ $historical->criteriaVersion->version }} · <bdi>{{ $historical->score }}</bdi> · {{ $historical->created_at->timezone('Africa/Cairo')->format('d/m/Y H:i') }}</summary>
                <a href="{{ \App\Filament\Resources\Applications\ApplicationResource::getUrl('view', ['record' => $application, 'scoreId' => $historical->id]) }}">{{ __('review.historical_score') }}</a>
                <p dir="auto">{{ $historical->reason_enc }}</p>
                @foreach ($historical->overrides as $override)
                    <p><bdi>{{ $override->old_value }} → {{ $override->new_value }}</bdi> · {{ $override->user?->name }} · <bdi>{{ $override->created_at->timezone('Africa/Cairo')->format('d/m/Y H:i') }}</bdi> · <bdi dir="auto">{{ $override->reason_enc }}</bdi></p>
                @endforeach
            </details>
        @endforeach
        {{ $data['scores']->links() }}
    </x-filament::section>
    <div class="review-grid">
        <x-filament::section :heading="__('review.timeline')">
            @foreach ($data['events'] as $event)
                <article style="margin-bottom:1rem"><bdi>{{ $event->created_at->timezone('Africa/Cairo')->format('d/m/Y H:i') }}</bdi> · {{ $event->user?->name ?? __('app.activity.system') }}
                    <div>{{ $event->from_status?->label() }} → {{ $event->to_status->label() }}</div><p dir="auto">{{ $event->reason_enc }}</p>
                </article>
            @endforeach
            {{ $data['events']->links() }}
        </x-filament::section>
        <x-filament::section :heading="__('review.notes')">
            @foreach ($data['notes'] as $note)
                <article style="margin-bottom:1rem"><strong>{{ $note->user?->name }}</strong> · <bdi>{{ $note->created_at->timezone('Africa/Cairo')->format('d/m/Y H:i') }}</bdi><p dir="auto">{{ $note->body_enc }}</p></article>
            @endforeach
            {{ $data['notes']->links() }}
        </x-filament::section>
    </div>
    <x-filament::section :heading="__('review.corrections')">
        @foreach ($data['corrections'] as $correction)
            <details><summary><bdi>{{ $correction->path }}</bdi> · <bdi dir="auto">{{ $correction->user?->name ?? __('review.actor_id', ['id' => $correction->user_id]) }}</bdi> · <bdi>{{ $correction->created_at->timezone('Africa/Cairo')->format('d/m/Y H:i') }}</bdi> · {{ __('review.sources.'.$correction->source) }}</summary>
                <div dir="auto">{{ $correction->before_enc }} → {{ $correction->after_enc }}</div><p dir="auto">{{ $correction->reason_enc }}</p>
            </details>
        @endforeach
        {{ $data['corrections']->links() }}
    </x-filament::section>
</div>

