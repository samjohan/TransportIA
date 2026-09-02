<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Conversation state for the Telegram bot's step-by-step gasto flow.
    // Long-polling updates arrive one at a time with no memory of earlier
    // messages, so this is what remembers which step each chat is on.
    public function up(): void
    {
        Schema::create('telegram_sessions', function (Blueprint $table) {
            $table->string('chat_id')->primary();
            $table->string('estado')->default('inicio');
            $table->uuid('ruta_uuid')->nullable();
            $table->string('categoria')->nullable();
            $table->decimal('monto', 12, 2)->nullable();
            $table->text('nota')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_sessions');
    }
};
