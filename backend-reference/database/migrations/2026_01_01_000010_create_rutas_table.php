<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rutas', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignId('conductor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('planificador_id')->constrained('users')->cascadeOnDelete();
            $table->string('origen');
            $table->string('destino');
            $table->timestamp('fecha_salida')->nullable();
            $table->enum('estado', ['pendiente', 'en_curso', 'completada', 'cancelada'])
                ->default('pendiente');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rutas');
    }
};
