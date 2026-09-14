<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routines', function (Blueprint $table) {
            // یادآوری سایلنت (بدون صدا) یا با صدا — disable_notification در تلگرام
            $table->boolean('silent_remind')->default(false)->after('remind_at');
        });
    }

    public function down(): void
    {
        Schema::table('routines', function (Blueprint $table) {
            $table->dropColumn('silent_remind');
        });
    }
};
