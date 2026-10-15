<?php

/**
 * Regression: session and cookie middleware must not be persistent Livewire middleware.
 * When they were, every Livewire update decrypted the session cookie twice, started a
 * fresh session and signed the user out after the first page (found in manual QA).
 */

use App\Domain\Access\Role;
use App\Http\Middleware\ApplyUserLocale;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

it('keeps only the locale middleware persistent for Livewire requests', function () {
    $panel = filament()->getPanel('admin');
    $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

    expect($persistent)->toContain(ApplyUserLocale::class)
        ->not->toContain(StartSession::class)
        ->not->toContain(EncryptCookies::class)
        ->and($panel->getMiddleware())->toContain(StartSession::class);
});

it('keeps a signed-in session across consecutive panel requests', function () {
    $user = userWithRole(Role::Owner);

    // Real session flow (no actingAs shortcut on the second request).
    $this->actingAs($user)->get('/admin')->assertOk();
    $this->get('/admin/recruitment-jobs')->assertOk();
    $this->get('/admin/applications')->assertOk();
});
