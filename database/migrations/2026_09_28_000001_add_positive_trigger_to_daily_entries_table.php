<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_entries', function (Blueprint $table) {
            $table->text('positive_trigger')->nullable()->after('emotional_trigger');
            $table->tinyInteger('positive_intensity')->nullable()->after('positive_trigger'); // 1-10
        });
    }

    public function down(): void
    {
        Schema::table('daily_entries', function (Blueprint $table) {
            $table->dropColumn(['positive_trigger', 'positive_intensity']);
        });
    }
};
