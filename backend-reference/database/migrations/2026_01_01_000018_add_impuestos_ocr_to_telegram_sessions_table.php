<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Holds the OCR-detected tax amount between the /gasto flow's photo
    // step and the final save, same reasoning as monto_ocr on this table.
    public function up(): void
    {
        Schema::table('telegram_sessions', function (Blueprint $table) {
            $table->decimal('impuestos_ocr', 12, 2)->nullable()->after('monto_ocr');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_sessions', function (Blueprint $table) {
            $table->dropColumn('impuestos_ocr');
        });
    }
};
