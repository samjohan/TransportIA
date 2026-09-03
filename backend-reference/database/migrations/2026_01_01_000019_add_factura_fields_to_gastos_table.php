<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The vendor's invoice number and NIT (Colombian tax ID) off the
    // receipt — useful for matching a gasto back to its DIAN electronic
    // invoice during a tax audit, without the accountant having to open
    // every receipt photo by hand.
    public function up(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->string('factura_numero')->nullable()->after('impuestos');
            $table->string('nit')->nullable()->after('factura_numero');
        });
    }

    public function down(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->dropColumn(['factura_numero', 'nit']);
        });
    }
};
