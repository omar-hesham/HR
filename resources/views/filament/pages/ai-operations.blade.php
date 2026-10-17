@php($snapshot = app(\App\Services\Ai\AiOperationsSnapshot::class)->data())
<div class="space-y-4" wire:poll.60s>
    @if($snapshot['suspended'])
        <div role="alert" class="rounded-xl bg-warning-100 p-4 text-warning-900">{{ __('ai.suspended') }}</div>
    @endif
    <dl class="grid grid-cols-2 gap-4">
        <div><dt>{{ __('ai.pipeline') }}</dt><dd>{{ $snapshot['enabled'] ? __('ai.on') : __('ai.off') }}</dd></div>
        <div><dt>{{ __('ai.driver') }}</dt><dd dir="ltr">{{ $snapshot['driver'] }}</dd></div>
        <div><dt>{{ __('ai.breaker') }}</dt><dd>{{ __('ai.breakers.'.$snapshot['breaker']) }}</dd></div>
        <div><dt>{{ __('ai.consumption') }}</dt><dd dir="ltr">{{ number_format($snapshot['consumed'], 4, '.', '') }} / {{ number_format($snapshot['cap'], 2, '.', '') }} {{ __('ai.egp') }}</dd></div>
        <div><dt>{{ __('ai.waiting') }}</dt><dd>{{ $snapshot['pending'] }}</dd></div>
        <div><dt>{{ __('ai.alerts') }}</dt><dd>{{ $snapshot['alerts'] }}</dd></div>
    </dl>
    <p>{{ __('ai.estimate_notice') }}</p>
    <h2>{{ __('ai.recent_errors') }}</h2>
    <table class="w-full text-start"><thead><tr><th>{{ __('ai.purpose') }}</th><th>{{ __('ai.error') }}</th><th>{{ __('ai.request') }}</th><th>{{ __('ai.time') }}</th></tr></thead><tbody>
        @foreach($snapshot['errors'] as $error)
            <tr><td>{{ __('ai.purposes.'.$error->purpose) }}</td><td dir="ltr">{{ $error->error_code }}</td><td dir="ltr">{{ $error->request_id }}</td><td dir="ltr">{{ \Illuminate\Support\Carbon::parse($error->created_at)->timezone('Africa/Cairo')->format('d/m/Y H:i') }}</td></tr>
        @endforeach
    </tbody></table>
</div>
