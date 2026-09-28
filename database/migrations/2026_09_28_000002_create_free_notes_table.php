<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('free_notes', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->string('platform')->default('telegram'); // telegram | bale
            $table->text('body'); // توضیح آزاد بدون تایتل (اعتبارسنجی در سطح اپ: حداکثر ۲۰۰۰ کاراکتر)
            $table->timestamps();

            $table->index(['chat_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('free_notes');
    }
};
