<?php

use App\Models\AiWorkItem;
use App\Services\Ai\AiWorkCoordinator;

it('does not label a scored application as waiting because of an optional explanation task', function () {
    $f = m4Application();

    AiWorkItem::query()->create(['idempotency_key' => 'explain-'.$f['application']->id, 'purpose' => 'explain', 'application_id' => $f['application']->id, 'state' => 'waiting', 'reason' => 'awaiting_service']);

    expect(app(AiWorkCoordinator::class)->labelForApplication($f['application']->id))->toBeNull();

    AiWorkItem::query()->create(['idempotency_key' => 'extract-'.$f['application']->id, 'purpose' => 'extract', 'application_id' => $f['application']->id, 'state' => 'waiting', 'reason' => 'awaiting_service']);

    expect(app(AiWorkCoordinator::class)->labelForApplication($f['application']->id))->toBe(__('ai.states.awaiting_service'));
});
