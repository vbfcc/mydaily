<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_states', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->string('platform')->default('telegram');
            $table->string('state')->nullable(); // waiting_sleep, waiting_wake, etc or null = idle
            $table->json('data')->nullable(); // accumulated answers
            $table->timestamps();

            $table->unique(['chat_id', 'platform'], 'bot_state_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_states');
    }
};
