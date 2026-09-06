<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_entries', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->string('platform')->default('telegram'); // telegram | bale
            $table->date('entry_date');
            $table->string('sleep_time')->nullable(); // HH:MM e.g. 23:30
            $table->string('wake_time')->nullable();  // HH:MM e.g. 07:00
            $table->decimal('work_hours', 4, 1)->nullable();
            $table->boolean('gym')->nullable();
            $table->integer('gaming_minutes')->nullable()->default(0);
            $table->boolean('social')->nullable();
            $table->tinyInteger('mood')->nullable(); // 1-10
            $table->text('emotional_trigger')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'platform', 'entry_date'], 'daily_unique_per_day');
            $table->index(['chat_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_entries');
    }
};
