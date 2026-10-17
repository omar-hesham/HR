<?php

use App\Actions\Review\AddNote;
use App\Actions\Scoring\OverrideScore;
use App\Domain\Access\Permission;
use App\Domain\Access\ReviewAccess;
use App\Domain\Access\Role;
use App\Domain\Applications\ApplicationStatus as Status;
use App\Domain\Applications\Exceptions\InvalidStatusTransition;
use App\Filament\Resources\Applications\Pages\ViewApplication;
use App\Models\FieldCorrection;
use App\Models\Note;
use App\Models\Override;
use App\Models\StatusEvent;
use App\Services\Applications\ApplicationStatusService;
use App\Services\Review\CorrectionForm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/M4.php';

it('allows recruiter follow up but never rejection hiring or archiving', function () {
    $f = m4Application();
    $user = userWithRole(Role::Recruiter, [$f['job']]);
    $statuses = app(ApplicationStatusService::class);
    expect(ReviewAccess::statusOptions($user, $f['application']))->toHaveKeys(['shortlisted', 'on_hold'])->not->toHaveKey('rejected_by_human');
    m4HttpException(fn () => $statuses->transition($f['application'], Status::RejectedByHuman, $user, 'Reason'), 403);
    $statuses->transition($f['application'], Status::Shortlisted, $user, 'Review');
    $statuses->transition($f['application'], Status::Interview, $user, 'Interview logged manually');
    m4HttpException(fn () => $statuses->transition($f['application'], Status::Hired, $user, 'Decision'), 403);
    m4HttpException(fn () => $statuses->transition($f['application'], Status::NotHired, $user, 'Decision'), 403);
    expect($f['application']->fresh()->status)->toBe(Status::Interview);
});

it('allows a hiring manager rejection with reason but reopening is owner only even with a direct grant', function () {
    $f = m4Application();
    $manager = userWithRole(Role::HiringManager, [$f['job']]);
    $owner = userWithRole(Role::Owner);
    $service = app(ApplicationStatusService::class);
    expect(fn () => $service->transition($f['application'], Status::RejectedByHuman, $manager, ''))->toThrow(InvalidStatusTransition::class);
    $service->transition($f['application'], Status::RejectedByHuman, $manager, 'Explicit human decision');
    $manager->givePermissionTo(Permission::RejectionReopen->value);
    m4HttpException(fn () => $service->transition($f['application'], Status::NeedsReview, $manager, 'Reopen'), 403);
    expect(fn () => $service->transition($f['application'], Status::NeedsReview, $owner, ''))->toThrow(InvalidStatusTransition::class);
    $service->transition($f['application'], Status::NeedsReview, $owner, 'Review the corrected evidence');
    expect(StatusEvent::query()->where('application_id', $f['application']->id)->whereNotNull('user_id')->count())->toBe(2)
        ->and($f['application']->fresh()->status)->toBe(Status::NeedsReview);
});

it('refuses worker rejection and forged out of scope human decisions without any event', function () {
    $f = m4Application();
    $service = app(ApplicationStatusService::class);
    $before = StatusEvent::query()->count();
    expect(fn () => $service->transition($f['application'], Status::RejectedByHuman, null, 'Worker'))->toThrow(InvalidStatusTransition::class);
    m4HttpException(fn () => $service->transition($f['application'], Status::RejectedByHuman, userWithRole(Role::HiringManager), 'Outside scope'), 403);
    expect(StatusEvent::query()->count())->toBe($before);
});

it('rechecks stale application status under lock before deciding', function () {
    $f = m4Application();
    $stale = $f['application']->fresh();
    $owner = userWithRole(Role::Owner);
    app(ApplicationStatusService::class)->transition($f['application'], Status::OnHold, $owner, 'Hold');
    expect(fn () => app(ApplicationStatusService::class)->transition($stale, Status::Shortlisted, $owner, 'Stale decision'))
        ->toThrow(HttpException::class);
    expect($stale->fresh()->status)->toBe(Status::OnHold);
});

it('stores internal encrypted notes with actor and time and sends no messages', function () {
    Mail::fake();
    $f = m4Application();
    $recruiter = userWithRole(Role::Recruiter, [$f['job']]);
    Livewire::actingAs($recruiter)->test(ViewApplication::class, ['record' => $f['application']->id])
        ->callAction('note', ['body' => 'Synthetic internal review note'])->assertHasNoActionErrors()->assertSee('Synthetic internal review note');
    $note = Note::query()->sole();
    expect($note->body_enc)->toBe('Synthetic internal review note')->and($note->user_id)->toBe($recruiter->id)
        ->and(DB::table('notes')->value('body_enc'))->not->toContain('Synthetic internal review note');
    Mail::assertNothingSent();
});

it('blocks empty notes and notes from management or outside scope', function () {
    $f = m4Application();
    $manager = userWithRole(Role::Management, [$f['job']]);
    m4HttpException(fn () => app(AddNote::class)->handle($f['application'], $manager, 'Private note'), 403);
    m4HttpException(fn () => app(AddNote::class)->handle($f['application'], userWithRole(Role::Recruiter), 'Private note'), 403);
    m4HttpException(fn () => app(AddNote::class)->handle($f['application'], userWithRole(Role::Owner), ' '), 422);
});

it('uses thin correction and override forms, preserving original criterion results', function () {
    $f = m4Application();
    $owner = userWithRole(Role::Owner);
    $originalResults = $f['score']->criterionResults->map->getRawOriginal()->all();
    Livewire::actingAs($owner)->test(ViewApplication::class, ['record' => $f['application']->id])
        ->callAction('correct', ['path' => 'can_reach_plant_for_shift', 'value' => 'false', 'evidence' => 'Synthetic human answer: unable to attend', 'reason' => 'Verified by reviewer'])
        ->assertHasNoActionErrors();
    expect(FieldCorrection::query()->sole()->source)->toBe('human_answer');
    Livewire::test(ViewApplication::class, ['record' => $f['application']->id])
        ->callAction('override', ['value' => '82', 'reason' => 'Synthetic manager rationale'])->assertHasNoActionErrors();
    expect(Override::query()->sole()->old_value)->toBe($f['score']->score)
        ->and(Override::query()->sole()->new_value)->toBe('82.00')
        ->and($f['score']->fresh()->criterionResults->map->getRawOriginal()->all())->toBe($originalResults);
});

it('requires a reason in correction and override UI and keeps protected paths out of the picker', function () {
    $f = m4Application();
    $owner = userWithRole(Role::Owner);
    $component = Livewire::actingAs($owner)->test(ViewApplication::class, ['record' => $f['application']->id]);
    $component->callAction('override', ['value' => '80', 'reason' => ''])->assertHasActionErrors(['reason' => 'required']);
    Livewire::test(ViewApplication::class, ['record' => $f['application']->id])->callAction('correct', ['path' => 'can_reach_plant_for_shift', 'value' => 'true', 'evidence' => 'Confirmed', 'reason' => ''])->assertHasActionErrors(['reason' => 'required']);
    $paths = app(CorrectionForm::class)->paths($f['extraction']);
    expect($paths)->not->toHaveKeys(['religion', 'gender', 'age', 'contacts.email', 'governorate', 'name']);
    m4HttpException(fn () => app(CorrectionForm::class)->handle($f['application'], $owner, 'religion', 'x', 'x', 'reason'), 422);
});

it('enforces correction and override permissions both in UI and direct action', function () {
    $f = m4Application();
    $recruiter = userWithRole(Role::Recruiter, [$f['job']]);
    $manager = userWithRole(Role::HiringManager, [$f['job']]);
    Livewire::actingAs($recruiter)->test(ViewApplication::class, ['record' => $f['application']->id])->assertActionHidden('override');
    m4HttpException(fn () => app(OverrideScore::class)->handle($f['score'], '90', $recruiter, 'No permission'), 403);
    Livewire::actingAs($manager)->test(ViewApplication::class, ['record' => $f['application']->id])->assertActionHidden('correct');
    m4HttpException(fn () => app(CorrectionForm::class)->handle($f['application'], $manager, 'can_reach_plant_for_shift', 'true', 'Human answer', 'No permission'), 403);
});

it('never sends candidate messages during any manual decision', function () {
    Mail::fake();
    $f = m4Application();
    $owner = userWithRole(Role::Owner);
    $service = app(ApplicationStatusService::class);
    $service->transition($f['application'], Status::Shortlisted, $owner, 'Review');
    $service->transition($f['application'], Status::Interview, $owner, 'Manual interview logging');
    $service->transition($f['application'], Status::NotHired, $owner, 'Human final decision');
    Mail::assertNothingSent();
});

it('records UI status decisions while refusing a forged recruiter rejection', function () {
    $f = m4Application();
    $recruiter = userWithRole(Role::Recruiter, [$f['job']]);
    Livewire::actingAs($recruiter)->test(ViewApplication::class, ['record' => $f['application']->id])
        ->callAction('status', ['status' => 'rejected_by_human', 'reason' => 'Forged decision'])->assertHasActionErrors(['status']);
    expect($f['application']->fresh()->status)->toBe(Status::Scored);
    Livewire::test(ViewApplication::class, ['record' => $f['application']->id])
        ->callAction('status', ['status' => 'on_hold', 'reason' => 'Follow up required'])->assertHasNoActionErrors();
    expect($f['application']->fresh()->status)->toBe(Status::OnHold)
        ->and(StatusEvent::query()->where('application_id', $f['application']->id)->latest('id')->first()->user_id)->toBe($recruiter->id);
});
