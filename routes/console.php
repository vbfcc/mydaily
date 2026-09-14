<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// یادآور ساعت ۱۲ شب به وقت ایران — «بیا گزارش امروز رو پر کن»
Schedule::command('bot:remind-midnight')->dailyAt('00:00')->timezone('Asia/Tehran');

// یادآوری ساعتی روتین‌ها — هر دقیقه چک می‌کند؛ مقایسه‌ی ساعت داخل خود کامند با Asia/Tehran است
Schedule::command('bot:remind-routine')->everyMinute();
