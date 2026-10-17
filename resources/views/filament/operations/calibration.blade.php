<section class="space-y-4">
<p>{{ __('ops.synthetic_only') }}</p><p>{{ __('ops.blind_locked') }}</p>
@foreach($batches as $batch)<p><a href="?batch={{ $batch->id }}">{{ __('ops.batch') }} #{{ $batch->id }} — {{ __('ops.job') }} #{{ $batch->recruitment_job_id }}</a></p>@endforeach
@foreach($entries as $entry)<p>{{ __('ops.application') }} #{{ $entry['application_id'] }} — {{ $entry['entered_at'] ? __('ops.input_locked') : __('ops.awaiting_input') }} @if($entry['document_uuid'])<a href="{{ route('documents.download', $entry['document_uuid']) }}">{{ __('ops.download_cv') }}</a>@endif</p>@endforeach
@if($metrics)
    @foreach(['sample_size','paired_size','top5_overlap','top5_denominator','spearman','suitable_not_presented','ties'] as $metric)<p>{{ __('ops.metrics.'.$metric) }}: {{ $metrics[$metric] ?? '—' }}</p>@endforeach
    <p>{{ __('ops.tie_method') }}</p><p>{{ __('ops.sample_limitations') }}</p>
@endif
</section>
