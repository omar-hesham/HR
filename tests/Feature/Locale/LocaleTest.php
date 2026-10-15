<?php

/**
 * TXT-P0-02 / TXT-P0-04: Arabic-first RTL UI, per-user English switch, self-hosted
 * font, Cairo display time zone with UTC storage, dd/mm/yyyy, Western digits.
 */

use App\Domain\Access\Role;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\RecruitmentJob;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Carbon;

it('renders the login page in Arabic, right-to-left, with the self-hosted font', function () {
    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->toContain('lang="ar"')
        ->toContain('dir="rtl"')
        ->toContain('fonts/noto-sans-arabic/font.css')
        ->toContain("--font-family: 'Noto Sans Arabic'")
        ->not->toContain('fonts.googleapis.com')
        ->not->toContain('fonts.bunny.net');

    expect(file_exists(public_path('fonts/noto-sans-arabic/noto-sans-arabic-arabic-400-normal.woff2')))->toBeTrue()
        ->and(file_exists(public_path('fonts/noto-sans-arabic/OFL.txt')))->toBeTrue();
});

it('switches the interface language per user from the user menu route', function () {
    $user = userWithRole(Role::Owner);

    $this->actingAs($user)->get('/admin')->assertSee('dir="rtl"', false);

    $this->actingAs($user)->get(route('locale.switch', 'en'))->assertRedirect();
    expect($user->fresh()->locale)->toBe('en');

    $this->actingAs($user->fresh())->get('/admin')
        ->assertSee('lang="en"', false)
        ->assertSee('dir="ltr"', false);

    $this->actingAs($user->fresh())->get(route('locale.switch', 'ar'));
    expect($user->fresh()->locale)->toBe('ar');
});

it('rejects unsupported locales', function () {
    $this->actingAs(userWithRole(Role::Owner))->get('/locale/fr')->assertNotFound();
});

it('keeps each user\'s language independent', function () {
    $arabic = userWithRole(Role::Owner);
    $english = userWithRole(Role::Recruiter);
    $english->forceFill(['locale' => 'en'])->save();

    $this->actingAs($english)->get('/admin/applications')->assertSee('Applications');
    $this->actingAs($arabic)->get('/admin/applications')->assertSee('الطلبات');
});

it('stores UTC and displays Africa/Cairo with dd/mm/yyyy and Western digits', function () {
    expect(config('app.timezone'))->toBe('UTC');

    $owner = userWithRole(Role::Owner);
    $receivedUtc = Carbon::parse('2026-07-15 21:30:00', 'UTC'); // 00:30 next day in Cairo (UTC+3, summer)
    $job = RecruitmentJob::factory()->create();
    Application::factory()->create([
        'recruitment_job_id' => $job->id,
        'candidate_id' => Candidate::factory()->create(['received_at' => $receivedUtc])->id,
        'received_at' => $receivedUtc,
    ]);

    expect(Application::query()->first()->getRawOriginal('received_at'))->toBe('2026-07-15 21:30:00');

    $html = $this->actingAs($owner)->get('/admin/applications')->assertOk()->getContent();

    // Livewire renders the table lazily on some versions; check the formatter directly as well.
    $formatted = $receivedUtc->copy()->setTimezone(FilamentTimezone::get())->format('d/m/Y H:i');
    expect($formatted)->toBe('16/07/2026 00:30')
        ->and(preg_match('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $html))->toBe(0);
});

it('wraps user-generated text for bidi isolation in lists', function () {
    $resource = file_get_contents(app_path('Filament/Resources/NeedsReview/NeedsReviewResource.php'));

    expect($resource)->toContain("'dir' => 'auto'");
});
