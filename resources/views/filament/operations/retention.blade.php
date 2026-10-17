<section>
<h2>{{ __('ops.retention_due') }}</h2>
@foreach($applications as $application)<p>#{{ $application->id }} — {{ $application->status->label() }} — {{ $application->retention_due_at->format('d/m/Y') }}</p>@endforeach
<h3>{{ __('ops.hard_cap_notices') }}</h3>
@foreach($notices as $notice)<p>{{ $notice->candidate_ref }} — {{ $notice->hard_due_at }}</p>@endforeach
</section>
