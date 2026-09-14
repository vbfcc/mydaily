<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routines', function (Blueprint $table) {
            // ساعت یادآوری روزانه به وقت تهران (HH:MM:SS) — null یعنی بدون یادآوری ساعتی
            $table->time('remind_at')->nullable()->after('ends_on');
        });
    }

    public function down(): void
    {
        Schema::table('routines', function (Blueprint $table) {
            $table->dropColumn('remind_at');
        });
    }
};
