<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The Telegram bot now links an account by matching the driver's
    // telefono instead of email+password, so two conductores sharing one
    // number would make that lookup ambiguous. Kept nullable (rather than
    // also enforcing NOT NULL here) since older rows may predate telefono
    // being required — a unique index still allows multiple NULLs.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique('telefono');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telefono']);
        });
    }
};
