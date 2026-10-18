<?php

use App\Actions\Review\AddNote;
use App\Domain\Access\Role;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Review\EvidencePreview;
use App\Filament\Pages\WeeklySummary;
use App\Models\AiRun;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\DocumentSource;
use App\Models\IntakeMessage;
use App\Models\Score;
use App\Models\User;
use App\Services\Applications\ApplicationStatusService;
use App\Services\Review\DuplicateQueue;
use App\Services\Review\HumanDuration;
use App\Services\Review\ReviewApplications;
use App\Services\Review\WeeklyReport;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoReviewSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/M4.php';

it('highlights Unicode code-point ranges and escapes HTML, rejecting false anchors', function () {
    $text = 'أحمد 😀 PLC <script>alert(1)</script>';
    $start = mb_strpos($text, 'PLC');
    $evidence = [['quote' => 'PLC', 'start' => $start, 'end' => $start + 3, 'document_id' => 4],
        ['quote' => 'wrong', 'start' => 0, 'end' => 4, 'document_id' => 4],
        ['quote' => 'أحمد', 'start' => 0, 'end' => 4, 'document_id' => 99]];
    $html = (string) app(EvidencePreview::class)->render($text, $evidence, 4);
    expect($html)->toContain('<mark id="evidence-4-'.$start.'">PLC</mark>')->toContain('&lt;script&gt;')
        ->not->toContain('<script>')->not->toContain('evidence-4-0');
});

it('reconciles weekly intake files people applications and distinct event counts with stored units', function () {
    Mail::fake();
    $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'Africa/Cairo'));
    $f = m4Application();
    $second = m4Application($f['job']);
    $owner = userWithRole(Role::Owner);
    app(ApplicationStatusService::class)->recordCreated($second['application'], $owner);
    $message = IntakeMessage::factory()->create(['received_at' => now()->subMinutes(10), 'visible_at' => now()->subMinutes(9)]);
    $message2 = IntakeMessage::factory()->create(['received_at' => now()->subMinutes(20), 'visible_at' => now()->subMinutes(18)]);
    DocumentSource::query()->where('application_id', $f['application']->id)->update(['intake_message_id' => $message->id]);
    DocumentSource::query()->where('application_id', $second['application']->id)->update(['intake_message_id' => $message2->id, 'disposition' => 'duplicate_linked']);
    app(AddNote::class)->handle($f['application'], $owner, 'One note');
    app(AddNote::class)->handle($f['application'], $owner, 'Second note, same application');
    app(ApplicationStatusService::class)->transition($f['application'], ApplicationStatus::Shortlisted, $owner, 'Review');
    app(ApplicationStatusService::class)->transition($f['application'], ApplicationStatus::Interview, $owner, 'Interview');
    $report = app(WeeklyReport::class)->data($owner, '2026-10-14');
    expect($report['counts'])->toMatchArray(['intake' => 2, 'files' => 2, 'candidates' => 2, 'applications' => 2, 'duplicates' => 1, 'reviewed' => 1, 'scored' => 2, 'decisions' => 1])
        ->and($report['units'])->toMatchArray(['intake' => 'messages', 'files' => 'files', 'candidates' => 'candidates', 'applications' => 'applications', 'scored' => 'applications']);
    Mail::assertNothingSent();
});

it('shows every visibility delay worst first, pending visibility and exception owners with no candidate PII', function () {
    $f = m4Application(null, ['name' => 'NeverReportThisName', 'email' => 'never@example.test', 'phone' => '+201001234567']);
    $coordinator = userWithRole(Role::Coordinator);
    $owner = userWithRole(Role::Owner);
    IntakeMessage::factory()->create(['received_at' => now()->subHours(3), 'visible_at' => now()->subHour()]);
    IntakeMessage::factory()->create(['received_at' => now()->subHours(2), 'visible_at' => null]);
    IntakeMessage::factory()->create(['received_at' => now()->subMinutes(2), 'visible_at' => now()->subMinute()]);
    $f['application']->update(['current_score_id' => null]);
    $data = app(WeeklyReport::class)->data($owner, now()->toDateString());
    expect($data['visibility'])->toHaveCount(4)->and($data['worst'])->toBeGreaterThanOrEqual(7200)
        ->and($data['pending'])->toBe(1)->and($data['exceptions'][0]['owners'])->toContain($owner->id);
    Livewire::actingAs($coordinator)->test(WeeklySummary::class)->assertSee(__('review.backup_pending'))
        ->assertDontSee('NeverReportThisName')->assertDontSee('never@example.test')->assertDontSee('+201001234567');
});

it('scopes summary counts and detail references to the reviewer jobs', function () {
    $mine = m4Application();
    m4Application();
    $reviewer = userWithRole(Role::HiringManager, [$mine['job']]);
    $data = app(WeeklyReport::class)->data($reviewer, now()->toDateString());
    expect($data['counts']['applications'])->toBe(1)->and($data['counts']['scored'])->toBe(1)->and($data['global'])->toBeFalse();
});

it('records pilot minutes manually with the unit and never invents a time value', function () {
    $owner = userWithRole(Role::Owner);
    $report = app(WeeklyReport::class);
    expect($report->data($owner, now()->toDateString())['pilot'])->toBeNull();
    Livewire::actingAs($owner)->test(WeeklySummary::class)->callAction('pilot', ['week' => now()->toDateString(), 'minutes' => 55])->assertHasNoActionErrors();
    expect(DB::table('weekly_pilot_logs')->sole()->minutes)->toBe(55)
        ->and(DB::table('weekly_pilot_logs')->sole()->unit)->toBe('minutes');
    m4HttpException(fn () => $report->pilot($owner, now()->toDateString(), -1), 422);
});

it('keeps review translations complete and every literal review key translated', function () {
    $flatten = function (array $array, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($array as $key => $value) {
            $keys = [...$keys, ...(is_array($value) ? $flatten($value, $prefix.$key.'.') : [$prefix.$key])];
        }

        return $keys;
    };
    expect($flatten(require lang_path('ar/review.php')))->toBe($flatten(require lang_path('en/review.php')));
    foreach (array_merge(File::allFiles(app_path()), File::allFiles(resource_path('views'))) as $file) {
        preg_match_all("/__\\('(review\\.[a-z_]+(?:\\.[a-z0-9_]+)*)'/", file_get_contents($file), $matches);
        foreach ($matches[1] as $key) {
            foreach (['ar', 'en'] as $locale) {
                expect(trans($key, [], $locale))->not->toBe($key);
            }
        }
    }
});

it('retains an anchor for each overlapping evidence quote', function () {
    $html = (string) app(EvidencePreview::class)->render('ABCDE', [
        ['document_id' => 1, 'quote' => 'ABCD', 'start' => 0, 'end' => 4],
        ['document_id' => 1, 'quote' => 'BCD', 'start' => 1, 'end' => 4],
    ], 1);
    expect($html)->toContain('id="evidence-1-0"')->toContain('id="evidence-1-1"')
        ->and(strip_tags($html))->toBe('ABCDE');
});

it('seeds an offline synthetic review routine once with no provider runs', function () {
    Storage::fake('private');
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoReviewSeeder::class);
    $owner = User::where('email', 'owner@example.test')->firstOrFail();
    $review = app(ReviewApplications::class);
    expect(Application::query()->count())->toBe(12)
        ->and($review->ranked($owner)->count())->toBe(9)
        ->and($review->ranked($owner)->get()->pluck('currentScore.band')->unique()->sort()->values()->all())->toBe(['needs_review', 'strong_match', 'weak_match'])
        ->and($review->stale($owner)->count())->toBe(1)
        ->and(AiRun::query()->count())->toBe(0)
        ->and(app(DuplicateQueue::class)->pairs($owner))->toHaveCount(1)
        ->and(Candidate::query()->where('email', 'demo-shared@example.test')->count())->toBe(2);
});

it('keeps every seeded evidence quote an exact substring of the synthetic CV text', function () {
    Storage::fake('private');
    $this->seed(DatabaseSeeder::class);
    $checked = 0;
    foreach (Score::query()->with(['criterionResults', 'extraction'])->get() as $score) {
        $text = (string) $score->extraction->normalizedText();
        foreach ($score->criterionResults as $result) {
            foreach (json_decode((string) $result->evidence_enc, true, 512) ?: [] as $evidence) {
                expect(mb_strpos($text, (string) $evidence['quote']))->not->toBeFalse();
                $checked++;
            }
        }
    }
    expect($checked)->toBeGreaterThan(0);
});

it('resolves exception owners to display names with the id kept as fallback', function () {
    $f = m4Application();
    $owner = userWithRole(Role::Owner);
    $data = app(WeeklyReport::class)->data($owner, now()->toDateString());
    expect($data['exceptions'][0]['owners'])->toContain($owner->id)
        ->and($data['exceptions'][0]['owner_names'][$owner->id])->toBe($owner->name);
});

it('humanizes durations on the weekly summary while the stored KPI stays seconds', function () {
    $format = app(HumanDuration::class);
    app()->setLocale('ar');
    expect($format->format(417))->toBe('6 د 57 ث')
        ->and($format->format(11700))->toBe('3 س 15 د')
        ->and($format->format(45))->toBe('45 ث');
    app()->setLocale('en');
    expect($format->format(417))->toBe('6 m 57 s');
    app()->setLocale('ar');
});
