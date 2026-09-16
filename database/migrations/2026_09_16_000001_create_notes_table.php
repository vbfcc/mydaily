<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->string('platform')->default('telegram'); // telegram | bale
            $table->string('title', 100);
            $table->text('body'); // حداکثر ۱۵۰۰ کاراکتر (اعتبارسنجی در سطح اپ)
            $table->timestamps();

            $table->index(['chat_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
