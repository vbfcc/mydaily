<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->string('platform')->default('telegram'); // telegram | bale
            $table->string('title', 100); // e.g. ChatGPT Plus
            $table->date('ordered_on'); // تاریخ ثبت سفارش/خرید
            $table->integer('duration_days')->default(30); // مدت اشتراک به روز
            $table->date('ends_on'); // ordered_on + duration_days
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['chat_id', 'platform', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_accounts');
    }
};
