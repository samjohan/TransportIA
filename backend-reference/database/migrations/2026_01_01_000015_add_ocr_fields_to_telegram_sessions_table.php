<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The /gasto flow now asks for the receipt photo as its first step and
    // OCRs it right away, so the chat needs somewhere to hold the photo
    // and the detected amount between that step and the ones (ruta,
    // categoría, monto) that follow it, all tracked in this same session
    // row.
    public function up(): void
    {
        Schema::table('telegram_sessions', function (Blueprint $table) {
            $table->string('recibo_path')->nullable()->after('nota');
            $table->decimal('monto_ocr', 12, 2)->nullable()->after('recibo_path');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_sessions', function (Blueprint $table) {
            $table->dropColumn(['recibo_path', 'monto_ocr']);
        });
    }
};
