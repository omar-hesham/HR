<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->constrained('candidates')->restrictOnDelete();
        });
        Schema::create('duplicate_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('existing_candidate_id')->constrained('candidates')->restrictOnDelete();
            $table->foreignId('incoming_candidate_id')->constrained('candidates')->restrictOnDelete();
            $table->string('fingerprint', 64)->unique();
            $table->string('action', 24);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('reason_enc');
            $table->longText('links_enc');
            $table->timestamp('created_at');
        });
        Schema::create('review_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('recruitment_job_id')->constrained()->restrictOnDelete();
            $table->string('format', 4);
            $table->string('path');
            $table->boolean('identified');
            $table->json('application_ids');
            $table->text('purpose_enc');
            $table->timestamp('expires_at')->index();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
        });
        Schema::create('weekly_pilot_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('week_start');
            $table->unsignedInteger('minutes');
            $table->string('unit', 16)->default('minutes');
            $table->timestamps();
            $table->unique(['user_id', 'week_start']);
        });
        Schema::create('review_kpi_definitions', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('unit', 32);
        });
        foreach (['intake' => 'messages', 'files' => 'files', 'candidates' => 'candidates', 'applications' => 'applications', 'duplicates' => 'files', 'duplicate_resolutions' => 'resolutions', 'reviewed' => 'applications', 'scored' => 'applications', 'decisions' => 'applications', 'visibility' => 'seconds', 'exceptions' => 'applications', 'active_users' => 'users', 'cloud' => 'egp', 'pilot_time' => 'minutes', 'backup' => 'runs'] as $key => $unit) {
            DB::table('review_kpi_definitions')->insert(['key' => $key, 'unit' => $unit]);
        }
    }

    public function down(): void
    {
        foreach (['review_kpi_definitions', 'weekly_pilot_logs', 'review_exports', 'duplicate_resolutions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('candidates', fn (Blueprint $table) => $table->dropConstrainedForeignId('merged_into_id'));
    }
};
