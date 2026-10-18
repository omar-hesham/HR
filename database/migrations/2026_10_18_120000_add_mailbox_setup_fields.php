<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->string('display_name')->nullable();
            // Null keeps the existing configured folder for previously provisioned mailboxes.
            $table->string('folder')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', fn (Blueprint $table) => $table->dropColumn(['display_name', 'folder']));
    }
};
