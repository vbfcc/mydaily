<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbt_records', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->string('platform')->default('telegram');
            $table->string('date_shamsi')->nullable(); // ShamsiDateHelper::fullDateTime(now()) e.g. 1405/06/23 20:52
            $table->text('event')->nullable();
            $table->text('thought')->nullable();
            $table->text('distortion')->nullable(); // imploded "فاجعه سازی، پیشگویی"
            $table->json('distortions')->nullable(); // ["فاجعه سازی","پیشگویی"]
            $table->text('feeling')->nullable();
            $table->string('score_thought')->nullable(); // 0-100 as string (allow "80%")
            $table->string('score_feeling')->nullable();
            // 7 Socratic questions
            $table->text('evidence_for')->nullable();
            $table->text('evidence_against')->nullable();
            $table->text('alternative_reasons')->nullable();
            $table->text('others_agree')->nullable();
            $table->text('pros_cons')->nullable();
            $table->text('testable')->nullable();
            $table->text('best_friend')->nullable();
            $table->string('score_thought_after')->nullable();
            $table->string('score_feeling_after')->nullable();
            $table->text('reaction')->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'platform']);
            $table->index(['platform', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbt_records');
    }
};
