<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_states', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 16)->unique();
            $t->string('state', 16)->default('closed');
            $t->unsignedInteger('failures')->default(0);
            $t->timestamp('probe_after')->nullable();
            $t->string('trial_token', 64)->nullable();
            $t->timestamp('trial_until')->nullable();
            $t->timestamps();
        });
        Schema::create('ai_budget_periods', function (Blueprint $t) {
            $t->id();
            $t->string('month', 7)->unique();
            $t->timestamps();
        });
        Schema::create('ai_consents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('candidate_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('intake_message_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('reference', 128);
            $t->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('ai_work_items', function (Blueprint $t) {
            $t->id();
            $t->string('idempotency_key', 64)->unique();
            $t->string('purpose', 16);
            $t->foreignId('document_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('application_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('intake_message_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('score_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('state', 24)->default('waiting');
            $t->string('reason', 64)->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('available_at')->nullable();
            $t->timestamp('queued_at')->nullable();
            $t->longText('result_enc')->nullable();
            $t->timestamps();
            $t->index(['state', 'available_at']);
        });
        Schema::create('ai_extraction_contexts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('extraction_id')->unique()->constrained()->cascadeOnDelete();
            $t->longText('mask_map_enc')->nullable();
            $t->foreignId('ai_run_id')->nullable()->constrained()->nullOnDelete();
            $t->string('job_context_id', 32)->nullable();
            $t->timestamps();
        });
        Schema::create('field_corrections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->constrained()->cascadeOnDelete();
            $t->foreignId('extraction_id')->constrained()->cascadeOnDelete();
            $t->string('path', 128);
            $t->longText('before_enc');
            $t->longText('after_enc');
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('source', 20);
            $t->text('reason_enc');
            $t->timestamps();
        });
        Schema::create('evaluation_keys', function (Blueprint $t) {
            $t->unsignedBigInteger('correction_revision')->default(0);
            $t->id();
            $t->string('idempotency_key', 64)->unique();
            $t->foreignId('score_id')->unique()->constrained()->cascadeOnDelete();
            $t->timestamps();
        });
        Schema::create('score_explanations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('score_id')->unique()->constrained()->cascadeOnDelete();
            $t->text('body_enc');
            $t->foreignId('ai_run_id')->constrained()->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['score_explanations', 'evaluation_keys', 'field_corrections', 'ai_extraction_contexts', 'ai_work_items', 'ai_consents', 'ai_budget_periods', 'ai_provider_states'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
