<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gastos', function (Blueprint $table) {
            // uuid primary key: generated client-side (offline) by the driver app
            $table->uuid('uuid')->primary();
            $table->foreignUuid('ruta_uuid')->constrained('rutas', 'uuid')->cascadeOnDelete();
            $table->foreignId('conductor_id')->constrained('users')->cascadeOnDelete();

            $table->decimal('monto', 12, 2);
            $table->enum('categoria', ['combustible', 'peaje', 'comida', 'hospedaje', 'mantenimiento', 'otro'])
                ->default('otro');
            $table->text('nota')->nullable();

            // OCR-related fields
            $table->decimal('monto_ocr', 12, 2)->nullable(); // what on-device Tesseract.js read
            $table->decimal('monto_ocr_servidor', 12, 2)->nullable(); // second-pass cloud OCR result
            $table->boolean('ocr_discrepancia')->default(false); // flagged for accountant review

            $table->string('recibo_path')->nullable(); // stored receipt photo

            // Offline sync metadata
            $table->timestamp('creado_offline_en')->nullable();
            $table->timestamps();

            $table->index(['ruta_uuid', 'conductor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gastos');
    }
};
