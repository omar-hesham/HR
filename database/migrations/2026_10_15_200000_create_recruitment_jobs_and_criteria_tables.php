<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §9.3: recruitment_jobs, criteria_versions, criteria (+ job scope pivot).
 * Named recruitment_jobs to avoid Laravel's queue "jobs" table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('title_ar');
            $table->string('title_en');
            // Approved title aliases used by mail intake to identify the job (M1-P0-02).
            $table->json('aliases')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('name_blind_review')->default(false);
            // Points to criteria_versions.id; no FK because of the circular reference.
            $table->unsignedBigInteger('current_criteria_version_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('recruitment_job_user', function (Blueprint $table) {
            $table->foreignId('recruitment_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['recruitment_job_id', 'user_id']);
        });

        Schema::create('criteria_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_job_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            // Band thresholds and confidence threshold for this version (SC-P0-03).
            $table->json('thresholds')->nullable();
            $table->foreignId('based_on_version_id')->nullable()->constrained('criteria_versions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('change_note')->nullable();
            $table->timestamps();
            $table->unique(['recruitment_job_id', 'version']);
            $table->index(['recruitment_job_id', 'status']);
        });

        Schema::create('criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('criteria_version_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('field', 64);
            $table->string('operator', 32);
            $table->json('target_json')->nullable();
            $table->unsignedSmallInteger('weight')->nullable();
            $table->string('label_ar');
            $table->string('label_en');
            // Job-relevance justification (M4-P0-01) and legal basis where required (D-52/D-53).
            $table->text('justification');
            $table->text('legal_basis_note')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('criteria');
        Schema::dropIfExists('criteria_versions');
        Schema::dropIfExists('recruitment_job_user');
        Schema::dropIfExists('recruitment_jobs');
    }
};
