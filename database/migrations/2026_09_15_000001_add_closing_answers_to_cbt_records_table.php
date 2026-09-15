<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbt_records', function (Blueprint $table) {
            $table->json('closing_answers')->nullable()->after('best_friend'); // answers of 3 closing MCQ (letters A..D)
        });
    }

    public function down(): void
    {
        Schema::table('cbt_records', function (Blueprint $table) {
            $table->dropColumn('closing_answers');
        });
    }
};