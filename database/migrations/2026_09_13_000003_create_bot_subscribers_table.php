<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * همه‌ی چت‌هایی که تا حالا به ربات پیام داده‌اند — برای یادآور ساعت ۱۲ شب.
     */
    public function up(): void
    {
        Schema::create('bot_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->string('platform')->default('telegram');
            $table->string('username')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'platform'], 'bot_subscriber_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_subscribers');
    }
};
