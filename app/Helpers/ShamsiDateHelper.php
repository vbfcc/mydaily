<?php

namespace App\Helpers;

use Carbon\Carbon;
use Morilog\Jalali\Jalalian;

class ShamsiDateHelper
{
    /**
     * تاریخ کامل - مثال: ۱۴۰۳/۰۶/۱۷ ۱۴:۳۰.
     */
    public static function fullDateTime($date)
    {
        if (! $date) {
            return null;
        }

        // تبدیل به Carbon با تایم‌زون ایران
        $carbonDate = Carbon::parse($date)->setTimezone('Asia/Tehran');

        return Jalalian::forge($carbonDate)->format('Y/m/d H:i');
    }

    /**
     * فقط تاریخ - مثال: ۱۴۰۳/۰۶/۱۷.
     */
    public static function dateOnly($date)
    {
        if (! $date) {
            return null;
        }

        return Jalalian::forge($date)->format('Y/m/d');
    }

    /**
     * فقط زمان - مثال: ۱۴:۳۰.
     */
    public static function timeOnly($date)
    {
        if (! $date) {
            return null;
        }

        return Jalalian::forge($date)->format('H:i');
    }

    /**
     * تاریخ با کلمه - مثال: ۱۷ شهریور ۱۴۰۳.
     */
    public static function dateWithMonth($date)
    {
        if (! $date) {
            return null;
        }

        return Jalalian::forge($date)->format('d F Y');
    }

    /**
     * تاریخ کوتاه - مثال: ۱۷ شهریور.
     */
    public static function shortDate($date)
    {
        if (! $date) {
            return null;
        }

        return Jalalian::forge($date)->format('d F');
    }

    /**
     * تاریخ با روز هفته - مثال: یکشنبه ۱۷ شهریور ۱۴۰۳.
     */
    public static function dateWithDay($date)
    {
        if (! $date) {
            return null;
        }

        return Jalalian::forge($date)->format('l d F Y');
    }

    /**
     * نمایش relative - مثال: ۲ ساعت پیش، دیروز، الان.
     */
    public static function timeAgo($date)
    {
        if (! $date) {
            return null;
        }

        $carbon = Carbon::parse($date);
        $diffInMinutes = $carbon->diffInMinutes(now());

        if ($diffInMinutes < 1) {
            return 'الان';
        }
        if ($diffInMinutes < 60) {
            return $diffInMinutes.' دقیقه پیش';
        }

        $diffInHours = $carbon->diffInHours(now());
        if ($diffInHours < 24) {
            return $diffInHours.' ساعت پیش';
        }

        $diffInDays = $carbon->diffInDays(now());
        if ($diffInDays == 1) {
            return 'دیروز';
        }
        if ($diffInDays < 7) {
            return $diffInDays.' روز پیش';
        }

        // بیشتر از یک هفته باشه، تاریخ شمسی نشون بده
        return self::dateOnly($date);
    }

    /**
     * فقط سال شمسی - مثال: ۱۴۰۳.
     */
    public static function yearOnly($date)
    {
        if (! $date) {
            return null;
        }

        return Jalalian::forge($date)->format('Y');
    }

    /**
     * ماه و سال - مثال: شهریور ۱۴۰۳.
     */
    public static function monthYear($date)
    {
        if (! $date) {
            return null;
        }

        return Jalalian::forge($date)->format('F Y');
    }

    /**
     * تاریخ با ساعت کوتاه - مثال: ۱۷ شهریور ساعت ۸.
     */
    public static function dateWithHour($date)
    {
        if (! $date) {
            return null;
        }

        // تبدیل به Carbon با تایم‌زون ایران
        $carbonDate = Carbon::parse($date)->setTimezone('Asia/Tehran');
        $jalali = Jalalian::forge($carbonDate);
        $day = $jalali->format('d');
        $month = $jalali->format('F');
        $hour = $jalali->format('G'); // G gives hour without leading zero

        return $day.' '.$month.' ساعت '.$hour;
    }
}
