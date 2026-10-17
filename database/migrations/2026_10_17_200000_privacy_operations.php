<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', fn (Blueprint $t) => $t->string('restore_engine', 16)->nullable());
        Schema::table('recruitment_jobs', fn (Blueprint $t) => $t->boolean('rules_only')->default(false));
        Schema::create('human_field_verifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained()->cascadeOnDelete();
            $t->foreignId('extraction_id')->constrained()->cascadeOnDelete();
            $t->string('revision_hash', 64);
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->text('reason_enc');
            $t->timestamp('created_at');
        });
        Schema::table('candidates', fn (Blueprint $t) => $t->timestamp('erased_at')->nullable());
        Schema::table('privacy_requests', function (Blueprint $t) {
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('verification_enc')->nullable();
            $t->text('decision_enc')->nullable();
            $t->text('channel_enc')->nullable();
            $t->text('notification_enc')->nullable();
            $t->timestamp('notified_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->boolean('mailbox_read_only')->default(true);
            $t->boolean('provider_deletion_factory_responsibility')->default(true);
        });
        Schema::create('privacy_artifacts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('privacy_request_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('application_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->timestamp('expires_at');
            $t->timestamp('purged_at')->nullable();
        });
        Schema::create('erasure_files', function (Blueprint $t) {
            $t->id();
            $t->string('disk', 32);
            $t->string('path');
            $t->timestamp('purged_at')->nullable();
            $t->timestamps();
            $t->unique(['disk', 'path']);
        });
        Schema::create('retention_notices', function (Blueprint $t) {
            $t->id();
            $t->uuid('candidate_ref')->unique();
            $t->timestamp('hard_due_at');
            $t->timestamp('created_at');
        });
        Schema::create('calibration_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recruitment_job_id')->constrained()->restrictOnDelete();
            $t->foreignId('criteria_version_id')->constrained()->restrictOnDelete();
            $t->foreignId('manager_id')->constrained('users')->restrictOnDelete();
            $t->boolean('synthetic')->default(true);
            $t->timestamp('locked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('calibration_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('calibration_batch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('manager_rank', 8, 3)->nullable();
            $t->boolean('suitable')->nullable();
            $t->decimal('tool_rank', 8, 3)->nullable();
            $t->boolean('presented')->nullable();
            $t->timestamp('entered_at')->nullable();
            $t->unique(['calibration_batch_id', 'application_id']);
        });
        Schema::create('calibration_checkpoints', function (Blueprint $t) {
            $t->id();
            $t->foreignId('calibration_batch_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->string('outcome', 32);
            $t->json('metrics');
            $t->text('reason_enc');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', fn (Blueprint $t) => $t->dropColumn('restore_engine'));
        Schema::dropIfExists('human_field_verifications');
        Schema::table('recruitment_jobs', fn (Blueprint $t) => $t->dropColumn('rules_only'));
        foreach (['calibration_checkpoints', 'calibration_entries', 'calibration_batches', 'retention_notices', 'erasure_files', 'privacy_artifacts'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('privacy_requests', function (Blueprint $t) {
            $t->dropConstrainedForeignId('requested_by');
            $t->dropColumn(['verification_enc', 'decision_enc', 'channel_enc', 'notification_enc', 'notified_at', 'decided_at', 'mailbox_read_only', 'provider_deletion_factory_responsibility']);
        });
        Schema::table('candidates', fn (Blueprint $t) => $t->dropColumn('erased_at'));
    }
};
