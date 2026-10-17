<?php

use App\Actions\Scoring\EvaluateApplication;
use App\Domain\Access\Role;
use App\Domain\Calibration\AgreementMetrics;
use App\Filament\Pages\Calibration as CalibrationPage;
use App\Services\Calibration\Calibration;
use App\Services\Calibration\HumanFieldVerification;
use App\Services\Review\CorrectionForm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/M4.php';

beforeEach(function () {
    Storage::fake('private');
    Storage::fake('review_exports');
});

it('computes exact Spearman average-rank ties overlap and missed suitable', function () {
    $entries = [];
    foreach ([[1, 1], [2, 2], [2, 3], [4, 4], [5, 5], [6, 6]] as $i => [$manager,$tool]) {
        $entries[] = ['application_id' => $i + 1, 'manager_rank' => (float) $manager, 'tool_rank' => (float) $tool, 'suitable' => true, 'presented' => $i !== 5];
    }
    $metrics = (new AgreementMetrics)->calculate($entries);
    expect($metrics['spearman'])->toEqualWithDelta(0.9856107606091623, 1e-12)->and($metrics['top5_overlap'])->toBe(5)->and($metrics['suitable_not_presented'])->toBe(1)->and($metrics['ties'])->toBe(1)->and($metrics['targets_met'])->toBeFalse();
});

it('handles insufficient samples constant ranks missing scores and reversed ranking honestly', function () {
    expect((new AgreementMetrics)->calculate([])['spearman'])->toBeNull();
    $entries = [];
    for ($i = 1; $i <= 6; $i++) {
        $entries[] = ['application_id' => $i, 'manager_rank' => (float) $i, 'tool_rank' => (float) (7 - $i), 'suitable' => false, 'presented' => true];
    }
    expect((new AgreementMetrics)->calculate($entries)['spearman'])->toBe(-1.0)->and((new AgreementMetrics)->calculate($entries)['top5_overlap'])->toBe(4);
    foreach ($entries as &$entry) {
        $entry['manager_rank'] = 1.;
    } unset($entry);
    expect((new AgreementMetrics)->calculate($entries)['spearman'])->toBeNull();
});

it('locks blind inputs before results and persists an immutable per-job week3 fallback', function () {
    $a = m4Application();
    $b = m4Application($a['job']);
    $manager = userWithRole(Role::HiringManager, [$a['job']]);
    $owner = userWithRole(Role::Owner);
    $service = app(Calibration::class);
    $batch = $service->start($manager, $a['job'], [$a['application']->id, $b['application']->id]);
    m4HttpException(fn () => $service->results($manager, $batch), 403);
    Livewire::actingAs($manager)->test(CalibrationPage::class, ['batch' => $batch])->assertSee(__('ops.blind_locked'))->assertDontSee(__('ops.metrics.spearman'));
    $service->input($manager, $batch, $a['application']->id, 1., true);
    m4HttpException(fn () => $service->input($manager, $batch, $a['application']->id, 2., false), 422);
    $service->input($manager, $batch, $b['application']->id, 2., true);
    expect($service->results($manager, $batch)['sample_size'])->toBe(2);
    m4HttpException(fn () => $service->checkpoint($manager, $batch, 'approved', 'Not approver'), 403);
    $service->checkpoint($owner, $batch, 'rules_only', 'Synthetic week3 fallback decision');
    expect(DB::table('calibration_checkpoints')->count())->toBe(1)->and($a['job']->fresh()->getAttribute('rules_only'))->toBe(1);
    m4HttpException(fn () => $service->checkpoint($owner, $batch, 'approved', 'Rewrite'), 422);
    expect(app(EvaluateApplication::class)->handle($a['application']->id))->toBeNull();
    app(HumanFieldVerification::class)->verify($manager, $a['application']->fresh(), 'Fields verified against synthetic document');
    expect(app(HumanFieldVerification::class)->valid($a['application'], $a['extraction']))->toBeTrue();
});

it('refuses mixed jobs and out of scope direct calibration URLs', function () {
    $a = m4Application();
    $b = m4Application();
    $manager = userWithRole(Role::HiringManager, [$a['job']]);
    m4HttpException(fn () => app(Calibration::class)->start($manager, $a['job'], [$a['application']->id, $b['application']->id]), 403);
    $batch = app(Calibration::class)->start(userWithRole(Role::Owner), $b['job'], [$b['application']->id, m4Application($b['job'])['application']->id]);
    Livewire::actingAs($manager)->test(CalibrationPage::class, ['batch' => $batch])->assertForbidden();
    expect(DB::table('calibration_batches')->count())->toBe(1);
});

it('computes acceptance metrics on twenty synthetic ranks without implying manager approval', function () {
    $entries = [];
    for ($rank = 1; $rank <= 20; $rank++) {
        $entries[] = ['application_id' => $rank, 'manager_rank' => (float) $rank, 'tool_rank' => (float) $rank, 'suitable' => true, 'presented' => true];
    }
    $metrics = (new AgreementMetrics)->calculate($entries);
    expect($metrics['sample_size'])->toBe(20)->and($metrics['top5_overlap'])->toBe(5)->and($metrics['spearman'])->toBe(1.0)->and($metrics['suitable_not_presented'])->toBe(0)->and($metrics['targets_met'])->toBeTrue();
    expect(DB::table('calibration_checkpoints')->count())->toBe(0);
});

it('supports manually verified new rules-only CVs without an AI call and invalidates later corrections', function () {
    $f = m3Application(false);
    $owner = userWithRole(Role::Owner);
    $f['job']->forceFill(['rules_only' => true])->save();
    app(HumanFieldVerification::class)->enter($owner, $f['application'], m3Response(), 'Verified all synthetic fields');
    $extraction = $f['document']->fresh()->latestExtraction;
    expect($extraction->id)->not->toBe($f['extraction']->id)->and(app(HumanFieldVerification::class)->valid($f['application'], $extraction))->toBeTrue();
    app(CorrectionForm::class)->handle($f['application']->fresh(), $owner, 'skills.0', 'plc', 'Diagnosing PLC faults', 'New human correction');
    expect(app(HumanFieldVerification::class)->valid($f['application'], $extraction))->toBeFalse()->and(DB::table('ai_runs')->count())->toBe(0);
});

it('keeps real pilot selection unavailable on a local or test workstation', function () {
    $a = m4Application();
    $b = m4Application($a['job']);
    m4HttpException(fn () => app(Calibration::class)->start(userWithRole(Role::Owner), $a['job'], [$a['application']->id, $b['application']->id], 'egypt_pilot'), 403);
    expect(DB::table('calibration_batches')->count())->toBe(0);
});
