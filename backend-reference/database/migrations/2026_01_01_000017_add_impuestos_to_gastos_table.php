<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tracks the tax portion of a gasto separately from its total (monto),
    // so it can be reported on its own instead of accountants having to
    // back it out of the total by hand.
    public function up(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->decimal('impuestos', 12, 2)->nullable()->after('monto');
        });
    }

    public function down(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->dropColumn('impuestos');
        });
    }
};
