<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 2 (mail intake): encrypted sender address for privacy notices (D-115),
 * per-attachment outcome reason (M1-P0-03), silence-alert state (brief §7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_messages', function (Blueprint $table) {
            // From address (encrypted); sender_hmac stays the lookup key.
            $table->text('sender_enc')->nullable()->after('sender_hmac');
        });

        Schema::table('document_sources', function (Blueprint $table) {
            // Outcome reason for excluded / ignored attachments without a review item.
            $table->string('reason', 64)->nullable()->after('disposition');
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->timestamp('last_silence_alert_at')->nullable()->after('last_error');
            $table->text('credentials')->nullable();
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            // Null falls back to the intake.silence_alert_hours setting (D-113).
            $table->unsignedSmallInteger('silence_alert_hours')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('mailboxes')->whereNull('silence_alert_hours')->update(['silence_alert_hours' => 48]);

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->unsignedSmallInteger('silence_alert_hours')->nullable(false)->default(48)->change();
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn(['last_silence_alert_at', 'credentials']);
        });

        Schema::table('document_sources', function (Blueprint $table) {
            $table->dropColumn('reason');
        });

        Schema::table('intake_messages', function (Blueprint $table) {
            $table->dropColumn('sender_enc');
        });
    }
};
