<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presupuestos', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('ruta_uuid')->constrained('rutas', 'uuid')->cascadeOnDelete();
            $table->decimal('monto_asignado', 12, 2);
            // App exclusiva para operación en Colombia: siempre COP.
            $table->string('moneda', 3)->default('COP');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presupuestos');
    }
};
