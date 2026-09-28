<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coaching_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->string('platform')->default('telegram'); // telegram | bale
            $table->longText('profile_json'); // پرونده واحد کوچینگ (JSON)
            $table->timestamps();

            $table->unique(['chat_id', 'platform'], 'coaching_unique_per_chat');
            $table->index(['chat_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coaching_profiles');
    }
};
