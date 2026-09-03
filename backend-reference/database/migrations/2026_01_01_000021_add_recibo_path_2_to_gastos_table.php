<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A second receipt photo (e.g. the reverse side) — currently only
    // captured by the Telegram bot's /gasto flow.
    public function up(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->string('recibo_path_2')->nullable()->after('recibo_path');
        });
    }

    public function down(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->dropColumn('recibo_path_2');
        });
    }
};
