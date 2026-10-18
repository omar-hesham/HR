<?php

use App\Domain\Access\Role;
use App\Services\Settings\SettingsRepository;

it('shows the demo warning on guest login only when enabled', function (bool $enabled, string $locale) {
    config(['app.demo_banner' => $enabled, 'app.locale' => $locale]);
    app(SettingsRepository::class)->update(['ui.default_locale' => $locale], null);
    app()->setLocale($locale);
    $response = $this->get('/admin/login')->assertOk();
    if ($enabled) {
        $response->assertSee('data-demo-banner', false)->assertSee(__('tour.demo_warning', [], $locale))->assertSee('lang="'.$locale.'"', false);
    } else {
        $response->assertDontSee('data-demo-banner', false)->assertDontSee(__('tour.demo_warning'));
    }
})->with([false, true])->with(['ar', 'en']);

it('shows the demo warning across panel pages only when enabled', function (bool $enabled, string $locale, string $url) {
    config(['app.demo_banner' => $enabled]);
    $user = userWithRole(Role::Owner);
    $user->forceFill(['locale' => $locale, 'tour_completed_at' => now()])->save();
    $response = $this->actingAs($user)->get($url)->assertOk();
    if ($enabled) {
        $response->assertSee('data-demo-banner', false)->assertSee(__('tour.demo_warning', [], $locale));
    } else {
        $response->assertDontSee('data-demo-banner', false)->assertDontSee(__('tour.demo_warning', [], $locale));
    }
})->with([false, true])->with(['ar', 'en'])->with(['/admin/applications', '/admin/weekly-summary', '/admin/profile']);

it('also places the warning above the upload field only when enabled', function (bool $enabled, string $locale) {
    config(['app.demo_banner' => $enabled]);
    $user = userWithRole(Role::Owner);
    $user->forceFill(['locale' => $locale])->save();
    $response = $this->actingAs($user)->get('/admin/upload')->assertOk();
    if ($enabled) {
        $response->assertSee('data-demo-banner', false)->assertSee('data-demo-upload-warning', false)
            ->assertSeeInOrder(['data-demo-upload-warning', __('tour.demo_warning', [], $locale), __('app.upload.files', [], $locale)]);
    } else {
        $response->assertDontSee('data-demo-banner', false)->assertDontSee('data-demo-upload-warning', false);
    }
})->with([false, true])->with(['ar', 'en']);

it('also warns during required MFA enrollment', function (bool $enabled) {
    config(['app.demo_banner' => $enabled]);
    $response = $this->actingAs(userWithRole(Role::Owner, mfa: false))
        ->get(route('filament.admin.auth.multi-factor-authentication.set-up-required'))->assertOk();
    if ($enabled) {
        $response->assertSee('data-demo-banner', false)->assertSee(__('tour.demo_warning'));
    } else {
        $response->assertDontSee('data-demo-banner', false);
    }
    $response->assertDontSee('data-guided-tour', false);
})->with([false, true]);
