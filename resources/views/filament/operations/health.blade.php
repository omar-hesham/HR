<section class="space-y-4">
    <h2>{{ __('ops.health') }}</h2>
    <p>{{ __('ops.no_pii') }}</p>
    @foreach($health['mailboxes'] as $mailbox)
        <p>{{ __('ops.mail_poll') }} #{{ $mailbox['id'] }} — {{ $mailbox['last_success']?->format('d/m/Y H:i') ?? __('ops.unknown') }} — {{ $mailbox['age'] ?? '—' }} {{ __('ops.seconds') }} {{ $mailbox['error'] ? __('ops.mail_error') : '' }}</p>
    @endforeach
    <p>{{ __('ops.queue_age') }}: {{ $health['oldest_job_seconds'] ?? '—' }} {{ __('ops.seconds') }}</p>
    <p>{{ __('ops.disk_free') }}: {{ number_format((float) $health['disk_free'] / 1048576, 0, '.', ',') }} / {{ number_format((float) $health['disk_total'] / 1048576, 0, '.', ',') }} {{ __('ops.megabytes') }}</p>
    <p>{{ __('ops.notice_failures') }}: {{ $health['notice_failures'] }}</p>
    <p>{{ __('ops.backups') }}: {{ $health['backup']['fresh'] ? __('ops.backup_fresh') : __('ops.backup_stale') }} — {{ $health['backup']['restore_test_at'] ?? __('ops.unknown') }}</p>
    <h3>{{ __('ops.failed_jobs') }}</h3>
    @foreach($health['failed_jobs'] as $job)<p>{{ $job->uuid }} — {{ $job->failed_at }}</p>@endforeach
    @foreach($health['alerts'] as $alert)<p>{{ __('ops.alert_code') }}: {{ $alert }}</p>@endforeach
</section>
