<?php

use App\Support\Schema\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §9.3: candidates, documents, extractions.
 * Name/contact/search columns are plaintext by design (spec §9.3, SEC-P0-02);
 * CV files and CV-derived text are encrypted at the application level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('phone_original', 64)->nullable();
            Columns::binary($table, 'email_hmac', 64)->nullable()->index();
            Columns::binary($table, 'phone_hmac', 64)->nullable()->index();
            $table->text('search_normalized')->nullable();
            // Points to documents.id; no FK because of the circular reference.
            $table->unsignedBigInteger('active_document_id')->nullable()->index();
            $table->timestamp('received_at');
            $table->timestamp('retention_hard_due_at')->nullable()->index();
            $table->timestamp('retention_notice_at')->nullable();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('candidate_id')->nullable()->constrained()->restrictOnDelete();
            // Path on the private disk (identifiers only, never a person's name).
            $table->string('path')->nullable();
            Columns::binary($table, 'sha256', 64)->unique();
            $table->string('mime', 128);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size');
            $table->string('kind', 32)->default('cv');
            $table->string('status', 32)->default('pending');
            $table->string('review_reason', 64)->nullable();
            $table->boolean('encrypted')->default(true);
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->json('quality_flags')->nullable();
            $table->decimal('quality_score', 5, 4)->nullable();
            $table->timestamps();
            $table->index(['status']);
        });

        Schema::create('extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('extractor', 32);
            $table->string('extractor_version', 16);
            $table->string('status', 32);
            $table->string('error_code', 64)->nullable();
            $table->longText('raw_text_enc')->nullable();
            $table->longText('normalized_text_enc')->nullable();
            $table->longText('offset_map_enc')->nullable();
            // Structured fields from the AI engine (Milestone 3); null until then.
            $table->longText('fields_json_enc')->nullable();
            $table->string('schema_version', 16)->nullable();
            $table->unsignedInteger('char_count')->nullable();
            $table->unsignedInteger('presentation_forms_replaced')->default(0);
            $table->json('quality_metrics')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'extractor_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extractions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('candidates');
    }
};
