<?php

namespace App\Services;

use App\Helpers\ShamsiDateHelper;
use App\Models\DailyEntry;
use App\Models\FreeNote;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ExportService
{
    /**
     * Get entries for a chat (optionally filtered by date range).
     */
    public function getEntries(string $chatId, string $platform, ?string $from = null, ?string $to = null): Collection
    {
        $q = DailyEntry::where('chat_id', $chatId)
            ->where('platform', $platform)
            ->orderBy('entry_date', 'asc');

        if ($from) {
            $q->whereDate('entry_date', '>=', $from);
        }
        if ($to) {
            $q->whereDate('entry_date', '<=', $to);
        }

        return $q->get();
    }

    /**
     * Get free descriptions (توضیحات آزاد — بدون تایتل) for a chat, optionally filtered by date range.
     * Range filter uses DATE(created_at) so a free note falls in the same بازه as daily entries.
     */
    public function getFreeNotes(string $chatId, string $platform, ?string $from = null, ?string $to = null): Collection
    {
        $q = FreeNote::where('chat_id', $chatId)
            ->where('platform', $platform)
            ->orderBy('created_at', 'asc');

        if ($from) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $q->whereDate('created_at', '<=', $to);
        }

        return $q->get();
    }

    /**
     * Convert free notes to AI-readable array with Shamsi dates.
     */
    public function freeNotesToArrayWithShamsi(Collection $notes): array
    {
        return $notes->map(function (FreeNote $n) {
            return [
                'id' => $n->id,
                'text' => $n->body,
                'created_at' => $n->created_at ? $n->created_at->toIso8601String() : null,
                'created_at_shamsi' => $n->created_at ? ShamsiDateHelper::dateOnly($n->created_at) : null,
                'created_at_shamsi_with_day' => $n->created_at ? ShamsiDateHelper::dateWithDay($n->created_at) : null,
                'created_at_shamsi_datetime' => $n->created_at ? ShamsiDateHelper::fullDateTime($n->created_at) : null,
            ];
        })->toArray();
    }

    /**
     * Get all entries grouped (for admin export) — not used by bot (bot exports per chat).
     */
    public function getAllEntries(?string $from = null, ?string $to = null): Collection
    {
        $q = DailyEntry::orderBy('entry_date', 'asc')->orderBy('chat_id');
        if ($from) $q->whereDate('entry_date', '>=', $from);
        if ($to) $q->whereDate('entry_date', '<=', $to);
        return $q->get();
    }

    /**
     * Convert entries to AI-readable array with Shamsi dates.
     */
    public function toArrayWithShamsi(Collection $entries): array
    {
        return $entries->map(function (DailyEntry $e) {
            $miladi = Carbon::parse($e->entry_date)->format('Y-m-d');
            return [
                'chat_id' => $e->chat_id,
                'platform' => $e->platform,
                'date_miladi' => $miladi,
                'date_shamsi' => ShamsiDateHelper::dateOnly($e->entry_date),
                'date_shamsi_with_day' => ShamsiDateHelper::dateWithDay($e->entry_date),
                'date_shamsi_fancy' => ShamsiDateHelper::dateWithMonth($e->entry_date),
                'sleep_time' => $e->sleep_time,
                'wake_time' => $e->wake_time,
                'work_hours' => $e->work_hours !== null ? (float)$e->work_hours : null,
                'gym' => $e->gym ? 'بله' : 'خیر',
                'gym_bool' => (bool)$e->gym,
                'gaming_minutes' => (int)$e->gaming_minutes,
                'social' => $e->social ? 'بله' : 'خیر',
                'social_bool' => (bool)$e->social,
                'mood' => $e->mood !== null ? (int)$e->mood : null,
                'emotional_trigger' => $e->emotional_trigger,
                'positive_trigger' => $e->positive_trigger,
                'positive_intensity' => $e->positive_intensity !== null ? (int)$e->positive_intensity : null,
                'routines' => $this->routineLogsFor($e),
                'created_at' => $e->created_at ? $e->created_at->toIso8601String() : null,
                'created_at_shamsi' => $e->created_at ? ShamsiDateHelper::fullDateTime($e->created_at) : null,
            ];
        })->toArray();
    }

    /**
     * لاگ روتین‌های یک رکورد روزانه: [{title, done, done_bool, note}]
     */
    public function routineLogsFor(DailyEntry $e): array
    {
        return \App\Models\RoutineLog::with('routine')
            ->where('chat_id', $e->chat_id)
            ->where('platform', $e->platform)
            ->whereDate('entry_date', Carbon::parse($e->entry_date)->toDateString())
            ->get()
            ->map(fn (\App\Models\RoutineLog $log) => [
                'title' => $log->routine?->title,
                'done' => $log->done === null ? null : ($log->done ? 'بله' : 'خیر'),
                'done_bool' => $log->done === null ? null : (bool) $log->done,
                'note' => $log->note,
            ])->toArray();
    }

    /**
     * Generate Excel (XLSX) file and return path.
     */
    public function generateExcel(Collection $entries, string $filePath): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('MyDaily');
        $sheet->setRightToLeft(true);

        // Headers — Persian
        $headers = [
            'تاریخ شمسی',
            'روز هفته',
            'تاریخ میلادی',
            'ساعت خواب',
            'ساعت بیداری',
            'کار مفید (ساعت)',
            'باشگاه',
            'گیم (دقیقه)',
            'تعامل اجتماعی',
            'حال (۱-۱۰)',
            'محرک احساسی',
            'عامل مثبت',
            'شدت اثر مثبت (۱-۱۰)',
            'پلتفرم',
            'چت آی‌دی',
        ];

        // Header row style
        $sheet->fromArray($headers, null, 'A1');
        $headerStyle = $sheet->getStyle('A1:O1');
        $headerStyle->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4A5568');
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Data rows
        $row = 2;
        foreach ($entries as $e) {
            $sheet->setCellValue("A{$row}", ShamsiDateHelper::dateOnly($e->entry_date));
            $sheet->setCellValue("B{$row}", ShamsiDateHelper::dateWithDay($e->entry_date));
            $sheet->setCellValue("C{$row}", Carbon::parse($e->entry_date)->format('Y-m-d'));
            $sheet->setCellValue("D{$row}", $e->sleep_time ?? '-');
            $sheet->setCellValue("E{$row}", $e->wake_time ?? '-');
            $sheet->setCellValue("F{$row}", $e->work_hours !== null ? (float)$e->work_hours : '-');
            $sheet->setCellValue("G{$row}", $e->gym ? 'بله' : 'خیر');
            $sheet->setCellValue("H{$row}", (int)$e->gaming_minutes);
            $sheet->setCellValue("I{$row}", $e->social ? 'بله' : 'خیر');
            $sheet->setCellValue("J{$row}", $e->mood !== null ? (int)$e->mood : '-');
            $sheet->setCellValue("K{$row}", $e->emotional_trigger ?? '-');
            $sheet->setCellValue("L{$row}", $e->positive_trigger ?? '-');
            $sheet->setCellValue("M{$row}", $e->positive_intensity !== null ? (int)$e->positive_intensity : '-');
            $sheet->setCellValue("N{$row}", $e->platform);
            $sheet->setCellValue("O{$row}", $e->chat_id);
            // Center align numeric columns
            $sheet->getStyle("F{$row}:J{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'O') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        // Make trigger column wider
        $sheet->getColumnDimension('K')->setWidth(35);
        $sheet->getColumnDimension('L')->setWidth(35);
        $sheet->getColumnDimension('B')->setWidth(28);

        // Borders
        $lastRow = $row - 1;
        if ($lastRow >= 1) {
            $styleArray = [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['argb' => 'FFCBD5E0'],
                    ],
                ],
            ];
            $sheet->getStyle("A1:O{$lastRow}")->applyFromArray($styleArray);
        }

        // Freeze header
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:O{$lastRow}");

        $writer = new Xlsx($spreadsheet);
        $dir = dirname($filePath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $writer->save($filePath);

        return $filePath;
    }

    /**
     * Generate CSV string (UTF-8 BOM for Excel).
     */
    public function generateCsv(Collection $entries): string
    {
        $headers = ['تاریخ شمسی','روز هفته','تاریخ میلادی','ساعت خواب','ساعت بیداری','کار مفید (ساعت)','باشگاه','گیم (دقیقه)','تعامل اجتماعی','حال (۱-۱۰)','محرک احساسی','عامل مثبت','شدت اثر مثبت (۱-۱۰)','پلتفرم','چت آی‌دی'];
        $lines = [];
        $lines[] = implode(',', array_map(fn($h) => '"' . str_replace('"','""',$h) . '"', $headers));
        foreach ($entries as $e) {
            $row = [
                ShamsiDateHelper::dateOnly($e->entry_date),
                ShamsiDateHelper::dateWithDay($e->entry_date),
                Carbon::parse($e->entry_date)->format('Y-m-d'),
                $e->sleep_time ?? '-',
                $e->wake_time ?? '-',
                $e->work_hours !== null ? (string)(float)$e->work_hours : '-',
                $e->gym ? 'بله' : 'خیر',
                (string)(int)$e->gaming_minutes,
                $e->social ? 'بله' : 'خیر',
                $e->mood !== null ? (string)(int)$e->mood : '-',
                $e->emotional_trigger ?? '-',
                $e->positive_trigger ?? '-',
                $e->positive_intensity !== null ? (string)(int)$e->positive_intensity : '-',
                $e->platform,
                $e->chat_id,
            ];
            $lines[] = implode(',', array_map(fn($v) => '"' . str_replace('"','""',$v) . '"', $row));
        }
        // BOM for Excel to recognize UTF-8
        return "\xEF\xBB\xBF" . implode("\n", $lines);
    }
}
