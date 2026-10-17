<?php

use App\Actions\Documents\StoreCvFile;
use App\Actions\Privacy\DeleteCandidateData;
use App\Actions\Scoring\EvaluateApplication;
use App\Domain\Access\Role;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Documents\SourceChannel;
use App\Domain\Privacy\DeletionReason;
use App\Domain\Privacy\RetentionTrack;
use App\Infrastructure\Storage\ContentEncrypter;
use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Jobs\ExtractDocumentText;
use App\Jobs\RetentionSweep;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\DeletionLedgerEntry;
use App\Models\DocumentSource;
use App\Models\IntakeMessage;
use App\Models\Note;
use App\Services\Applications\ApplicationStatusService;
use App\Services\Intake\ProcessInboundMessage;
use App\Services\Privacy\DeletionLedger;
use App\Services\Privacy\HiredHandover;
use App\Services\Privacy\RetentionPolicy;
use App\Services\Review\ExportApplications;
use App\Services\Review\WeeklyReport;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/M4.php';

beforeEach(function () {
    Storage::fake('private');
    Storage::fake('review_exports');
    Mail::fake();
    m3Settings(['retention.applicant_days' => 10, 'retention.idle_days' => 5, 'retention.hard_max_days' => 30, 'retention.owner_notice_days' => 3, 'retention.hired_handover_days' => 2]);
});

/** Recursively inspect JSON audit properties as well as both encrypted column formats. */
function m4bPersonalPayloads(mixed $payload): array
{
    if (is_array($payload)) {
        $strings = [];
        foreach ($payload as $part) {
            $strings = [...$strings, ...m4bPersonalPayloads($part)];
        }

        return $strings;
    }
    if (! is_string($payload)) {
        return [];
    }
    $strings = [$payload];
    $json = json_decode($payload, true);
    if (json_last_error() === JSON_ERROR_NONE && $json !== $payload) {
        $strings = [...$strings, ...m4bPersonalPayloads($json)];
    }
    try {
        $strings = [...$strings, ...m4bPersonalPayloads(Crypt::decryptString($payload))];
    } catch (DecryptException) {
    }
    try {
        $strings = [...$strings, ...m4bPersonalPayloads(app(ContentEncrypter::class)->decryptString($payload))];
    } catch (DecryptException) {
    }

    return $strings;
}

function m4bSweep(): void
{
    app()->call([new RetentionSweep, 'handle']);
}

it('anchors retention to the first final decision through archive and file views', function () {
    $f = m4Application();
    $owner = userWithRole(Role::Owner);
    $statuses = app(ApplicationStatusService::class);
    $statuses->transition($f['application'], ApplicationStatus::RejectedByHuman, $owner, 'Synthetic decision');
    $first = $f['application']->fresh()->final_decision_at;
    $this->travel(4)->days();
    $this->actingAs($owner)->get(route('documents.download', $f['document']->uuid))->assertOk();
    $statuses->transition($f['application'], ApplicationStatus::Archived, $owner, 'Archive after decision');
    expect($f['application']->fresh()->final_decision_at->equalTo($first))->toBeTrue();
    expect(app(RetentionPolicy::class)->due($f['application']->fresh())->equalTo($first->copy()->addDays(10)))->toBeTrue();
    $this->travel(6)->days();
    m4bSweep();
    expect($f['application']->fresh()->status)->toBe(ApplicationStatus::Deleted);
});

it('surfaces each active status to owner without ordinary deletion', function (ApplicationStatus $status) {
    $f = m4Application();
    $f['application']->forceFill(['status' => $status, 'last_activity_at' => now()->subDays(11)])->save();
    m4bSweep();
    expect($f['application']->fresh()->status)->toBe($status)->and($f['application']->fresh()->retention_due_at->isPast())->toBeTrue();
    $this->actingAs(userWithRole(Role::Owner))->get('/admin/retention-due')->assertOk()->assertSee('#'.$f['application']->id);
    Storage::disk('private')->assertExists($f['document']->path);
})->with([ApplicationStatus::Processing, ApplicationStatus::NeedsReview, ApplicationStatus::Scored, ApplicationStatus::Shortlisted, ApplicationStatus::OnHold, ApplicationStatus::Interview]);

it('deletes idle non-final applications only after the activity and idleness thresholds', function () {
    $f = m4Application();
    $f['application']->forceFill(['status' => ApplicationStatus::Received, 'last_activity_at' => now()->subDays(9)])->save();
    m4bSweep();
    expect($f['application']->fresh()->status)->toBe(ApplicationStatus::Received);
    $this->travel(1)->days();
    m4bSweep();
    expect($f['application']->fresh()->status)->toBe(ApplicationStatus::Deleted);
});

it('records prior hard-cap notice and deletes active identities at the original receipt cap', function () {
    $f = m4Application();
    $f['candidate']->forceFill(['received_at' => now()->subDays(27)])->save();
    m4bSweep();
    expect(DB::table('retention_notices')->count())->toBe(1)->and($f['candidate']->fresh()->retention_notice_at)->not->toBeNull();
    $f['application']->forceFill(['last_activity_at' => now()])->save();
    $this->travel(3)->days();
    m4bSweep();
    expect($f['application']->fresh()->status)->toBe(ApplicationStatus::Deleted)->and(DeletionLedgerEntry::query()->where('reason_code', 'hard_cap')->count())->toBeGreaterThan(0);
    expect(DB::table('activity_log')->where('event', 'retention.hard_cap_notice')->count())->toBe(1);
});

it('keeps a shared source for the live application and detaches the ended application identity', function () {
    $f = m4Application();
    $f['application']->forceFill(['status' => ApplicationStatus::Archived, 'final_decision_at' => now()->subDays(11)])->save();
    $other = Application::factory()->create(['candidate_id' => $f['candidate']->id, 'status' => ApplicationStatus::Interview]);
    DocumentSource::factory()->create(['document_id' => $f['document']->id, 'application_id' => $other->id]);
    m4bSweep();
    expect($f['application']->fresh()->status)->toBe(ApplicationStatus::Deleted)->and($f['application']->fresh()->candidate_id)->not->toBe($f['candidate']->id)->and($other->fresh()->status)->toBe(ApplicationStatus::Interview);
    Storage::disk('private')->assertExists($f['document']->path);
    expect($f['document']->fresh()->extractions()->count())->toBe(1);
    $counts = app(WeeklyReport::class)->data(userWithRole(Role::Owner), now()->startOfWeek()->toDateString())['counts'];
    expect($counts['candidates'])->toBe(1);
});

it('erases all personal tables files exports duplicate aliases and quotes and is idempotent', function () {
    $f = m4Application(null, ['name' => 'UniqueSyntheticErasureName', 'email' => 'erase-only@example.test', 'phone' => '+201001239999', 'search_normalized' => 'UniqueSyntheticErasureName']);
    $f['extraction']->forceFill(['raw_text_enc' => 'UniqueSyntheticQuote', 'normalized_text_enc' => 'UniqueSyntheticQuote', 'fields_json_enc' => json_encode(['private' => 'UniqueSyntheticQuote'])])->save();
    $owner = userWithRole(Role::Owner);
    Note::query()->create(['application_id' => $f['application']->id, 'user_id' => $owner->id, 'body_enc' => 'UniqueSyntheticQuote']);
    activity('access')->performedOn($f['application'])->withProperties(['nested' => ['reason_enc' => encrypt('UniqueSyntheticQuote')]])->event('synthetic.access')->log('synthetic.access');
    $alias = Candidate::factory()->create(['name' => 'UniqueSyntheticErasureName', 'merged_into_id' => $f['candidate']->id]);
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $export = app(ExportApplications::class)->create($owner, $f['job'], 'csv', 'UniqueSyntheticQuote');
    $action = app(DeleteCandidateData::class);
    $action->candidate($alias->id, DeletionReason::ErasureRequest);
    $count = DeletionLedgerEntry::query()->count();
    $action->candidate($alias->id, DeletionReason::ErasureRequest);
    expect(DeletionLedgerEntry::query()->count())->toBe($count)->and($alias->fresh()->name)->toBeNull()->and($f['candidate']->fresh()->email)->toBeNull();
    Storage::disk('private')->assertMissing($f['document']->path);
    Storage::disk('review_exports')->assertMissing($export->path);
    foreach (Schema::getTableListing() as $table) {
        if (str_starts_with($table, 'sqlite_')) {
            continue;
        }
        foreach (DB::table($table)->get() as $row) {
            foreach ((array) $row as $column) {
                if (! is_string($column)) {
                    continue;
                }
                $decoded = implode(' ', m4bPersonalPayloads($column));
                foreach (['UniqueSyntheticErasureName', 'erase-only@example.test', '+201001239999', 'UniqueSyntheticQuote'] as $needle) {
                    expect($decoded)->not->toContain($needle, $table);
                }
            }
        }
    }
});

it('prevents stale scoring and extraction workers from resurrecting erased records', function () {
    $f = m4Application();
    app(DeleteCandidateData::class)->candidate($f['candidate']->id, DeletionReason::ErasureRequest);
    expect(app(EvaluateApplication::class)->handle($f['application']->id))->toBeNull();
    app()->call([new ExtractDocumentText($f['document']->id), 'handle']);
    expect(DB::table('extractions')->count())->toBe(0)->and(DB::table('scores')->count())->toBe(0);
});

it('switches hired track and expires only after recorded handover', function () {
    $f = m4Application();
    $owner = userWithRole(Role::Owner);
    $f['application']->forceFill(['status' => ApplicationStatus::Interview])->save();
    app(ApplicationStatusService::class)->transition($f['application'], ApplicationStatus::Hired, $owner, 'Synthetic hire');
    expect($f['application']->fresh()->retention_track)->toBe(RetentionTrack::HiredHandover)->and($f['application']->fresh()->retention_due_at)->toBeNull();
    $id = app(HiredHandover::class)->record($owner, $f['application']->fresh(), 'Confirmed external handover');
    $this->actingAs($owner)->get(route('privacy-artifacts.download', $id))->assertOk();
    $this->travel(2)->days();
    m4bSweep();
    expect($f['application']->fresh()->status)->toBe(ApplicationStatus::Deleted);
});

it('keeps a failed file purge durable and completes it on retry without resurrecting identity', function () {
    $f = m4Application();
    $originalDisk = Storage::disk('private');
    $failedDisk = Mockery::mock(Filesystem::class);
    $failedDisk->shouldReceive('exists')->with($f['document']->path)->andReturn(true);
    $failedDisk->shouldReceive('delete')->with($f['document']->path)->andReturn(false);
    Storage::set('private', $failedDisk);
    expect(fn () => app(DeleteCandidateData::class)->candidate($f['candidate']->id, DeletionReason::ErasureRequest))->toThrow(RuntimeException::class, 'erasure_storage_failed');
    expect($f['candidate']->fresh()->name)->toBeNull()->and(DB::table('erasure_files')->whereNull('purged_at')->count())->toBe(1);
    Storage::set('private', $originalDisk);
    app(DeleteCandidateData::class)->candidate($f['candidate']->id, DeletionReason::ErasureRequest);
    Storage::disk('private')->assertMissing($f['document']->path);
    expect(DB::table('erasure_files')->whereNull('purged_at')->count())->toBe(0);
});

it('scrubs source mail and prevents ledgered bytes from re-entering through another mailbox uid', function () {
    $f = m4Application();
    $f['document']->forceFill(['sha256' => hash('sha256', '%PDF-synthetic')])->save();
    $mailbox = fakeMailbox();
    $message = IntakeMessage::query()->create(['mailbox_id' => $mailbox->id, 'source' => 'email', 'external_id' => 'synthetic-original-uid', 'received_at' => now(), 'outcome' => 'processed', 'sender_enc' => 'remove@example.test', 'subject_enc' => 'Remove synthetic subject', 'raw_sha256' => hash('sha256', 'Unique raw mail')]);
    $path = app(EncryptedDocumentStore::class)->putIntake('synthetic-mail', 'Unique raw mail');
    $message->forceFill(['raw_path' => $path])->save();
    $f['application']->sources()->first()->forceFill(['intake_message_id' => $message->id])->save();
    app(DeleteCandidateData::class)->candidate($f['candidate']->id, DeletionReason::ErasureRequest);
    expect($message->fresh()->sender_enc)->toBeNull()->and($message->fresh()->subject_enc)->toBeNull()->and($message->fresh()->external_id)->toBe('erased-'.$message->id);
    Storage::disk('private')->assertMissing($path);
    $outcome = app(ProcessInboundMessage::class)->process($mailbox, 'different-uid', 'Unique raw mail');
    expect($outcome->ingested)->toBeFalse();
    $file = app(StoreCvFile::class)->store('%PDF-synthetic', 'do-not-retain-name.pdf', 'application/pdf', SourceChannel::Upload);
    expect($file->reason)->toBe('data_erased')->and($file->createdApplication)->toBeFalse();
});

it('initializes mail application clocks from original receipt rather than worker time', function () {
    $mailbox = fakeMailbox();
    $received = now()->subDays(8)->startOfSecond();
    $message = IntakeMessage::query()->create(['mailbox_id' => $mailbox->id, 'source' => 'email', 'external_id' => 'receipt-clock', 'received_at' => $received, 'outcome' => 'processed']);
    $stored = app(StoreCvFile::class)->store(file_get_contents(cvFixture('10_en_quality.docx')), 'synthetic.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', SourceChannel::Email, intakeMessageId: $message->id);
    $application = Application::query()->findOrFail($stored->applicationId);
    expect($application->received_at->equalTo($received))->toBeTrue()->and($application->last_activity_at->equalTo($received))->toBeTrue()->and($application->candidate->received_at->equalTo($received))->toBeTrue();
});

it('fails safely instead of erasing candidates under invalid retention periods', function () {
    $f = m4Application();
    m3Settings(['retention.hard_max_days' => -1]);
    expect(fn () => m4bSweep())->toThrow(RuntimeException::class, 'retention_policy_invalid');
    expect($f['candidate']->fresh()->name)->not->toBeNull()->and(DeletionLedgerEntry::query()->count())->toBe(0);
    Storage::disk('private')->assertExists($f['document']->path);
});

it('rolls back erasure when current-ledger publication fails and retries the authorized intent safely', function () {
    $f = m4Application();
    $ledgerPath = storage_path('app/private/ops/ledger-failure-'.Str::uuid().'.bin');
    config(['operations.ledger_target' => $ledgerPath, 'operations.backup_key' => '']);
    expect(fn () => app(DeleteCandidateData::class)->candidate($f['candidate']->id, DeletionReason::ErasureRequest))->toThrow(RuntimeException::class, 'backup_key_missing');
    expect($f['candidate']->fresh()->name)->toBe('Samir Hassan')->and(DeletionLedgerEntry::query()->count())->toBe(0);
    Storage::disk('private')->assertExists($f['document']->path);
    config(['operations.backup_key' => ContentEncrypter::generateKey()]);
    app(DeleteCandidateData::class)->candidate($f['candidate']->id, DeletionReason::ErasureRequest);
    Storage::disk('private')->assertMissing($f['document']->path);
    expect(is_file($ledgerPath))->toBeTrue()->and($f['candidate']->fresh()->name)->toBeNull();
    $published = file_get_contents($ledgerPath);
    DB::table('deletion_ledger')->delete();
    app(DeletionLedger::class)->publish();
    $encrypter = new ContentEncrypter(config('operations.backup_key'));
    expect(json_decode($encrypter->decryptString(file_get_contents($ledgerPath)), true))->toBe(json_decode($encrypter->decryptString($published), true));
});

it('keeps a raw message shared with a live application and erases it with the last one', function () {
    $f = m4Application();
    $mailbox = fakeMailbox();
    $message = IntakeMessage::query()->create(['mailbox_id' => $mailbox->id, 'source' => 'email', 'external_id' => 'synthetic-shared-uid', 'received_at' => now(), 'outcome' => 'processed', 'sender_enc' => 'shared@example.test', 'raw_sha256' => hash('sha256', 'Shared raw mail')]);
    $path = app(EncryptedDocumentStore::class)->putIntake('synthetic-shared', 'Shared raw mail');
    $message->forceFill(['raw_path' => $path])->save();
    $f['application']->sources()->first()->forceFill(['intake_message_id' => $message->id])->save();
    $other = Application::factory()->create(['candidate_id' => $f['candidate']->id, 'status' => ApplicationStatus::Interview]);
    DocumentSource::factory()->create(['document_id' => $f['document']->id, 'application_id' => $other->id, 'intake_message_id' => $message->id]);

    app(DeleteCandidateData::class)->application($f['application']->id, DeletionReason::Retention);

    expect($f['application']->fresh()->status)->toBe(ApplicationStatus::Deleted)
        ->and($message->fresh()->raw_path)->toBe($path);
    Storage::disk('private')->assertExists($path);

    app(DeleteCandidateData::class)->application($other->id, DeletionReason::Retention);

    expect($message->fresh()->raw_path)->toBeNull();
    Storage::disk('private')->assertMissing($path);
});

it('does not duplicate ledger rows when the same ledger is replayed twice', function () {
    $f = m4Application();
    app(DeleteCandidateData::class)->candidate($f['candidate']->id, DeletionReason::ErasureRequest);
    $before = DeletionLedgerEntry::query()->count();
    $entries = DeletionLedgerEntry::query()->get()->map(fn ($e) => ['opaque_resource_ids' => $e->opaque_resource_ids, 'reason_code' => $e->reason_code, 'source_hmac' => $e->source_hmac, 'deleted_at' => (string) $e->deleted_at])->values()->all();

    app(DeletionLedger::class)->replay($entries);
    app(DeletionLedger::class)->replay($entries);

    expect($before)->toBeGreaterThan(0)->and(DeletionLedgerEntry::query()->count())->toBe($before);
});
