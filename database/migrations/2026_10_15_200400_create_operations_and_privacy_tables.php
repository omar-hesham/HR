<?php

use App\Support\Schema\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §9.3: ai_runs, privacy_requests, deletion_ledger, settings, backup_runs,
 * plus the notice outbox. Tables only in Milestone 1 (except settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 32); // classify | extract | explain (never candidate-facing text, D-90)
            $table->string('driver', 16);
            $table->string('model', 128);
            $table->string('prompt_version', 32);
            $table->string('status', 32);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_egp', 10, 4)->nullable();
            $table->decimal('reserved_cost_egp', 10, 4)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // access | rectification | erasure
            $table->string('status', 20);
            $table->text('details_enc')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('deletion_ledger', function (Blueprint $table) {
            $table->id();
            // Opaque ids only: no name, contact details or quote (spec §9.3).
            $table->json('opaque_resource_ids');
            Columns::binary($table, 'source_hmac', 64)->nullable()->index();
            $table->string('reason_code', 32);
            $table->timestamp('deleted_at');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value_json');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 20);
            Columns::binary($table, 'manifest_hash', 64)->nullable();
            $table->string('location_ref', 128)->nullable();
            $table->timestamp('restore_test_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notice_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20);
            $table->string('notice_version', 32);
            Columns::binary($table, 'idempotency_key', 64)->unique();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 128)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_outbox');
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('deletion_ledger');
        Schema::dropIfExists('privacy_requests');
        Schema::dropIfExists('ai_runs');
    }
};
