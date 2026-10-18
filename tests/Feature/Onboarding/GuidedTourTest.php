<?php

use App\Domain\Access\Role;
use App\Livewire\GuidedTour;
use App\Services\Onboarding\GuidedTour as Tour;
use Illuminate\Support\Arr;
use Livewire\Livewire;

it('starts the tour only on the first applications list visit for each user', function () {
    $first = userWithRole(Role::Owner);
    $completed = userWithRole(Role::Owner);
    $completed->forceFill(['tour_completed_at' => now()->subDay()])->save();

    $this->actingAs($first)->get('/admin/applications')->assertOk()->assertSee('data-guided-tour', false);
    $this->actingAs($completed)->get('/admin/applications')->assertOk()->assertDontSee('data-guided-tour', false);
    $this->actingAs($first)->get('/admin/upload')->assertOk()->assertDontSee('data-guided-tour', false);
    expect($first->fresh()->tour_completed_at)->toBeNull();
});

it('does not offer the tour before login and required MFA', function () {
    $this->get('/admin/login')->assertOk()->assertDontSee('data-guided-tour', false);
    $this->get('/admin/applications')->assertRedirect('/admin/login');
    $this->actingAs(userWithRole(Role::Owner, mfa: false))->get('/admin/applications')
        ->assertRedirect(route('filament.admin.auth.multi-factor-authentication.set-up-required'));
});

it('persists finishing or skipping for the current user only', function (string $method) {
    $this->travelTo(now()->startOfSecond());
    $current = userWithRole(Role::Owner);
    $other = userWithRole(Role::Recruiter);

    Livewire::actingAs($current)->test(GuidedTour::class)
        ->call('replay')->assertSee('data-guided-tour', false)
        ->call($method)->assertSet('open', false)->assertDontSee('data-guided-tour', false);

    expect($current->fresh()->tour_completed_at->equalTo(now()))->toBeTrue()
        ->and($other->fresh()->tour_completed_at)->toBeNull();
    $this->actingAs($current->fresh())->get('/admin/applications')->assertDontSee('data-guided-tour', false);
})->with(['complete', 'skip']);

it('replays a completed tour without clearing or changing its timestamp', function () {
    $current = userWithRole(Role::Owner);
    $time = now()->subDay()->startOfSecond();
    $current->forceFill(['tour_completed_at' => $time])->save();

    $component = Livewire::actingAs($current)->test(GuidedTour::class)->assertDontSee('data-guided-tour', false)
        ->call('replay')->assertSet('open', true)->assertSee('data-guided-tour', false);
    expect($current->fresh()->tour_completed_at->equalTo($time))->toBeTrue();
    $component->call('complete');
    expect($current->fresh()->tour_completed_at->equalTo($time))->toBeTrue();
});

it('exposes the replay action in the user menu in either locale', function (string $locale, string $url) {
    $user = userWithRole(Role::Owner);
    $user->forceFill(['locale' => $locale, 'tour_completed_at' => now()])->save();
    $this->actingAs($user)->get($url)->assertOk()
        ->assertSee('recruit-tour-replay.window', false)
        ->assertSee(__('tour.menu', [], $locale))->assertSee('recruit-tour-replay', false);

    $item = filament()->getPanel('admin')->getUserMenuItems()['guided-tour'];
    expect($item->getName())->toBe('guided-tour')
        ->and($item->getCustomAlpineClickHandler())->toContain('recruit-tour-replay');
})->with(['ar', 'en'])->with(['/admin/applications', '/admin/profile']);

it('refuses anonymous tour components', function () {
    Livewire::test(GuidedTour::class)->assertUnauthorized();
});

it('refuses tour actions when the current account is unusable or lacks MFA', function (string $state, string $method) {
    $user = userWithRole(Role::Owner);
    $component = Livewire::actingAs($user)->test(GuidedTour::class);
    $user->forceFill(match ($state) {
        'inactive' => ['active' => false],
        'expired' => ['access_expires_at' => now()->subMinute()],
        'no-mfa' => ['app_authentication_secret' => null],
    })->save();

    $component->call($method)->assertForbidden();
    expect($user->fresh()->tour_completed_at)->toBeNull();
})->with(['inactive', 'expired', 'no-mfa'])->with(['complete', 'skip', 'replay']);

it('has complete translated copy for all twelve steps and controls', function () {
    $ar = Arr::dot(require lang_path('ar/tour.php'));
    $en = Arr::dot(require lang_path('en/tour.php'));
    expect(array_keys($ar))->toBe(array_keys($en));
    foreach ([$ar, $en] as $strings) {
        foreach ($strings as $value) {
            expect($value)->toBeString()->not->toBeEmpty();
        }
    }
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $steps = app(Tour::class)->steps();
        expect($steps)->toHaveCount(12)->and($steps[0]['target'])->toBeNull()->and($steps[11]['target'])->toBeNull();
        foreach ($steps as $step) {
            expect($step['title'])->not->toStartWith('tour.steps.')
                ->and($step['body'])->not->toStartWith('tour.steps.');
        }
    }
});
