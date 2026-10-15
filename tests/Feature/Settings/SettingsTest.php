<?php

/**
 * M11-P0-03 + kickoff §5.5: settings for retention (two tracks), quality thresholds,
 * upload limits and default language; owner only; every change versioned and logged.
 */

use App\Domain\Access\Role;
use App\Filament\Pages\ManageSettings;
use App\Models\Setting;
use App\Services\Settings\SettingsRepository;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('falls back to configured defaults when nothing is stored', function () {
    $settings = app(SettingsRepository::class);

    expect($settings->int('retention.applicant_days'))->toBe(365)
        ->and($settings->float('quality.min_letter_ratio'))->toBe(0.60)
        ->and($settings->int('upload.max_file_mb'))->toBe(10)
        ->and($settings->string('ui.default_locale'))->toBe('ar')
        ->and($settings->bool('privacy.keep_id_card_attachments'))->toBeFalse();
});

it('versions every change and logs old and new values', function () {
    $owner = userWithRole(Role::Owner);
    $settings = app(SettingsRepository::class);

    $settings->update(['retention.applicant_days' => 200], $owner);
    $settings->update(['retention.applicant_days' => 210], $owner);

    $row = Setting::query()->where('key', 'retention.applicant_days')->firstOrFail();
    $log = Activity::query()->where('event', 'settings.updated')->latest('id')->firstOrFail();

    expect($row->version)->toBe(2)
        ->and($row->updated_by)->toBe($owner->id)
        ->and($log->properties['old'])->toBe(200)
        ->and($log->properties['new'])->toBe(210);
});

it('refuses unknown keys so secrets can never be stored as settings', function () {
    app(SettingsRepository::class)->update(['MAILBOX_PASSWORD' => 'x'], null);
})->throws(InvalidArgumentException::class);

it('saves the settings form as the owner', function () {
    $owner = userWithRole(Role::Owner);
    $this->actingAs($owner);

    Livewire::test(ManageSettings::class)
        ->set('data.retention.applicant_days', 120)
        ->set('data.quality.max_reversed_ratio', 0.15)
        ->set('data.upload.max_batch_files', 20)
        ->set('data.ui.default_locale', 'en')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(SettingsRepository::class);
    $settings->flush();

    expect($settings->int('retention.applicant_days'))->toBe(120)
        ->and($settings->float('quality.max_reversed_ratio'))->toBe(0.15)
        ->and($settings->int('upload.max_batch_files'))->toBe(20)
        ->and($settings->string('ui.default_locale'))->toBe('en');
});

it('validates the settings form', function () {
    $this->actingAs(userWithRole(Role::Owner));

    Livewire::test(ManageSettings::class)
        ->set('data.retention.applicant_days', 400)
        ->set('data.retention.hard_max_days', 100)
        ->set('data.quality.min_letter_ratio', 1.5)
        ->call('save')
        ->assertHasErrors(['data.retention.hard_max_days', 'data.quality.min_letter_ratio']);
});

it('keeps the settings page away from every other role', function (Role $role) {
    $this->actingAs(userWithRole($role))->get('/admin/settings')->assertForbidden();
})->with([Role::HiringManager, Role::Recruiter, Role::Coordinator, Role::Management, Role::Operator]);
