<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Holds a second receipt photo (e.g. the reverse side) between the
    // /gasto flow's photo steps and the final save, same reasoning as
    // recibo_path on this table.
    public function up(): void
    {
        Schema::table('telegram_sessions', function (Blueprint $table) {
            $table->string('recibo_path_2')->nullable()->after('recibo_path');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_sessions', function (Blueprint $table) {
            $table->dropColumn('recibo_path_2');
        });
    }
};
