<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routine_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_id')->constrained('routines')->cascadeOnDelete();
            $table->string('chat_id');
            $table->string('platform')->default('telegram');
            $table->date('entry_date');
            $table->boolean('done')->nullable(); // true = انجام شد, false = انجام نشد
            $table->text('note')->nullable(); // توضیح توصیفی (اختیاری)
            $table->timestamps();

            $table->unique(['routine_id', 'entry_date'], 'routine_log_unique');
            $table->index(['chat_id', 'platform', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_logs');
    }
};
