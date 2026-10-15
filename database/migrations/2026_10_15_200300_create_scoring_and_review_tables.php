<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §9.3: scores, criterion_results, overrides, notes, status_events.
 * Tables only in Milestone 1; logic arrives in Milestones 3–4. History is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('criteria_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('extraction_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('score', 6, 2)->nullable();
            $table->string('band', 20)->nullable();
            $table->decimal('coverage', 6, 2)->nullable();
            $table->text('reason_enc')->nullable();
            $table->string('rules_version', 16);
            $table->string('schema_version', 16)->nullable();
            $table->string('prompt_version', 32)->nullable();
            $table->string('model_id', 128)->nullable();
            $table->string('driver', 16)->nullable();
            $table->timestamps();
        });

        Schema::create('criterion_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('score_id')->constrained()->cascadeOnDelete();
            $table->foreignId('criterion_id')->constrained('criteria')->restrictOnDelete();
            $table->string('result', 20); // met | not_met | not_mentioned | unclear
            $table->longText('evidence_enc')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('source', 20)->default('model'); // model | human_correction | human_answer
            $table->timestamps();
        });

        Schema::create('overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('score_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('old_value', 64)->nullable();
            $table->string('new_value', 64)->nullable();
            $table->text('reason_enc');
            $table->timestamps();
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body_enc');
            $table->timestamps();
        });

        Schema::create('status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            // Null = background worker; mandatory for human decisions (WF-P0-02).
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reason_code', 64)->nullable();
            $table->text('reason_enc')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_events');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('overrides');
        Schema::dropIfExists('criterion_results');
        Schema::dropIfExists('scores');
    }
};
