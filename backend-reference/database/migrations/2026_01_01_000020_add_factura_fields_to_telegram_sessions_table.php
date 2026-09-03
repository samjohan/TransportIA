<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Holds the OCR-detected invoice number and NIT between the /gasto
    // flow's photo step and the final save, same reasoning as monto_ocr
    // and impuestos_ocr on this table.
    public function up(): void
    {
        Schema::table('telegram_sessions', function (Blueprint $table) {
            $table->string('factura_numero_ocr')->nullable()->after('impuestos_ocr');
            $table->string('nit_ocr')->nullable()->after('factura_numero_ocr');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_sessions', function (Blueprint $table) {
            $table->dropColumn(['factura_numero_ocr', 'nit_ocr']);
        });
    }
};
