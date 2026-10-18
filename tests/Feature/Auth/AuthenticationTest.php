<?php

/**
 * T-M9-02 / M9-P0-02/03/04: MFA required for every account, inactive and expired
 * accounts refused on every request, sessions revoked when access is reduced.
 */

use App\Actions\Users\ResetUserMfa;
use App\Actions\Users\SetUserActive;
use App\Actions\Users\SyncUserAccess;
use App\Domain\Access\Role;
use App\Models\Document;
use App\Models\RecruitmentJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

it('enables required TOTP MFA with recovery codes on the panel', function () {
    $panel = filament()->getPanel('admin');

    expect($panel->isMultiFactorAuthenticationRequired())->toBeTrue()
        ->and($panel->getMultiFactorAuthenticationProviders())->toHaveKey('app');
});

it('sends a user without MFA to the set-up page before anything else', function (string $url) {
    $user = userWithRole(Role::Owner, mfa: false);

    $this->actingAs($user)->get($url)
        ->assertRedirect(route('filament.admin.auth.multi-factor-authentication.set-up-required'));
})->with(['/admin', '/admin/applications', '/admin/needs-review', '/admin/settings']);

it('lets an MFA-enrolled user into the panel, landing on the review list', function () {
    $this->actingAs(userWithRole(Role::Owner))->get('/admin')->assertRedirect('/admin/applications');
});

it('refuses the file download route to a user who has not enrolled MFA', function () {
    $owner = userWithRole(Role::Owner, mfa: false);
    $document = Document::factory()->create();

    $this->actingAs($owner)->get(route('documents.download', $document->uuid))->assertForbidden();
});

it('refuses inactive and expired accounts on every request', function () {
    $inactive = userWithRole(Role::Owner);
    $inactive->forceFill(['active' => false])->save();

    $expired = userWithRole(Role::Recruiter);
    $expired->forceFill(['access_expires_at' => now()->subMinute()])->save();

    $this->actingAs($inactive)->get('/admin')->assertForbidden();
    $this->actingAs($expired)->get('/admin')->assertForbidden();
});

it('requires the support account to be time-limited', function () {
    $support = userWithRole(Role::Operator);
    expect($support->isUsable())->toBeTrue();

    $support->forceFill(['access_expires_at' => null])->save();
    expect($support->fresh()->isUsable())->toBeFalse();
});

it('uses a 30-minute idle session timeout', function () {
    expect(config('session.lifetime'))->toBe(30);
});

it('ends sessions and logs old/new values when a role is removed', function () {
    $owner = userWithRole(Role::Owner);
    $job = RecruitmentJob::factory()->create();
    $manager = userWithRole(Role::HiringManager, [$job]);

    DB::table('sessions')->insert(['id' => 'synthetic-session', 'user_id' => $manager->id, 'payload' => 'x', 'last_activity' => time()]);

    app(SyncUserAccess::class)->handle($manager, [Role::Management->value], [], [$job->id], $owner);

    expect(DB::table('sessions')->where('user_id', $manager->id)->count())->toBe(0)
        ->and($manager->fresh()->hasRole(Role::HiringManager->value))->toBeFalse();

    $log = Activity::query()->where('event', 'user.access_changed')->latest('id')->first();
    expect($log->causer_id)->toBe($owner->id)
        ->and($log->properties['old']['roles'])->toBe([Role::HiringManager->value])
        ->and($log->properties['new']['roles'])->toBe([Role::Management->value])
        ->and($log->properties['sessions_revoked'])->toBeTrue();
});

it('keeps sessions when access only grows', function () {
    $owner = userWithRole(Role::Owner);
    $recruiter = userWithRole(Role::Recruiter);
    DB::table('sessions')->insert(['id' => 'synthetic-session-2', 'user_id' => $recruiter->id, 'payload' => 'x', 'last_activity' => time()]);

    $job = RecruitmentJob::factory()->create();
    app(SyncUserAccess::class)->handle($recruiter, [Role::Recruiter->value], [], [$job->id], $owner);

    expect(DB::table('sessions')->where('user_id', $recruiter->id)->count())->toBe(1);
});

it('ends sessions when an account is deactivated', function () {
    $owner = userWithRole(Role::Owner);
    $recruiter = userWithRole(Role::Recruiter);
    DB::table('sessions')->insert(['id' => 'synthetic-session-3', 'user_id' => $recruiter->id, 'payload' => 'x', 'last_activity' => time()]);

    app(SetUserActive::class)->handle($recruiter, false, $owner);

    expect(DB::table('sessions')->where('user_id', $recruiter->id)->count())->toBe(0);
});

it('resets MFA only with a reason and logs it (M9-P0-04)', function () {
    $user = userWithRole(Role::Recruiter);

    $this->artisan('recruit:mfa-reset', ['email' => $user->email])->assertFailed();

    $this->artisan('recruit:mfa-reset', ['email' => $user->email, '--reason' => 'Identity verified by phone call with the owner'])->assertSuccessful();

    expect($user->fresh()->getAppAuthenticationSecret())->toBeNull()
        ->and(Activity::query()->where('event', 'user.mfa_reset')->exists())->toBeTrue();
});

it('refuses an MFA reset without a reason in code as well', function () {
    app(ResetUserMfa::class)->handle(userWithRole(Role::Recruiter), ' ');
})->throws(InvalidArgumentException::class);

it('stores the TOTP secret encrypted', function () {
    $user = User::factory()->withMfa()->create();
    $raw = DB::table('users')->where('id', $user->id)->value('app_authentication_secret');

    expect($raw)->not->toBe($user->getAppAuthenticationSecret())
        ->and(decrypt($raw, false))->toBe($user->getAppAuthenticationSecret());
});
