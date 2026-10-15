<?php

use App\Support\Schema\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §9.3: mailboxes, intake_messages (raw message first, kickoff §5.2),
 * applications, document_sources (channel-agnostic, kickoff §5.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32); // m365 | google | imap
            $table->string('address');
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('encryption', 16)->nullable();
            $table->string('auth_type', 16); // oauth2 | password
            // Name of the .env credential set; secrets are never stored in the database.
            $table->string('credential_ref', 64);
            $table->string('cursor')->nullable();
            $table->string('uidvalidity', 64)->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_error', 128)->nullable();
            // "No mail received" alert after this many hours (Milestone 2).
            $table->unsignedSmallInteger('silence_alert_hours')->default(48);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('intake_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 20)->default('email');
            Columns::binary($table, 'external_id', 191);
            $table->string('message_id_header', 512)->nullable();
            // Raw message (.eml) stored encrypted with its hash before any parsing (kickoff §5.2).
            $table->string('raw_path')->nullable();
            Columns::binary($table, 'raw_sha256', 64)->nullable()->index();
            $table->unsignedBigInteger('raw_size')->nullable();
            $table->text('subject_enc')->nullable();
            Columns::binary($table, 'sender_hmac', 64)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('visible_at')->nullable();
            $table->string('outcome', 32)->default('pending'); // processed | ignored_with_reason | needs_review
            $table->string('reason', 64)->nullable();
            $table->foreignId('job_hint_id')->nullable()->constrained('recruitment_jobs')->nullOnDelete();
            $table->string('last_error', 128)->nullable();
            $table->timestamps();
            $table->unique(['mailbox_id', 'external_id']);
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            // Nullable: an application may wait for job assignment (spec §9.3).
            $table->foreignId('recruitment_job_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 20);
            $table->string('external_reference')->nullable();
            $table->string('status', 32)->default('received');
            $table->string('review_reason', 64)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('last_activity_at');
            $table->timestamp('final_decision_at')->nullable();
            $table->timestamp('retention_due_at')->nullable()->index();
            // Kickoff §5.5 two retention tracks.
            $table->string('retention_track', 20)->default('applicant');
            $table->timestamp('handed_over_at')->nullable();
            // Points to scores.id; no FK because of the circular reference.
            $table->unsignedBigInteger('current_score_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['recruitment_job_id', 'status']);
            $table->index(['candidate_id', 'recruitment_job_id']);
        });

        Schema::create('document_sources', function (Blueprint $table) {
            $table->id();
            // Null for an excluded or rejected item that has no stored file.
            $table->foreignId('document_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('intake_message_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 20);
            $table->string('external_reference')->nullable();
            // Unique per intake message (attachment part id) or per upload batch + index.
            Columns::binary($table, 'attachment_key', 191);
            $table->string('attachment_kind', 32)->default('cv');
            $table->string('disposition', 32)->default('pending');
            // Original file name may contain a person's name: encrypted cast.
            $table->text('original_filename_enc')->nullable();
            $table->string('reported_mime', 128)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            Columns::binary($table, 'upload_batch', 36)->nullable()->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            // Needs-review state (D-32).
            $table->string('review_reason', 64)->nullable();
            $table->timestamp('review_opened_at')->nullable();
            $table->timestamp('review_resolved_at')->nullable();
            $table->foreignId('review_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note_enc')->nullable();
            $table->timestamps();
            $table->unique(['intake_message_id', 'attachment_key']);
            $table->index(['review_reason', 'review_resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sources');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('intake_messages');
        Schema::dropIfExists('mailboxes');
    }
};
