<?php

namespace App\Services;

use App\Helpers\ShamsiDateHelper;
use App\Models\DailyEntry;
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
                'created_at' => $e->created_at ? $e->created_at->toIso8601String() : null,
                'created_at_shamsi' => $e->created_at ? ShamsiDateHelper::fullDateTime($e->created_at) : null,
            ];
        })->toArray();
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
            'پلتفرم',
            'چت آی‌دی',
        ];

        // Header row style
        $sheet->fromArray($headers, null, 'A1');
        $headerStyle = $sheet->getStyle('A1:M1');
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
            $sheet->setCellValue("L{$row}", $e->platform);
            $sheet->setCellValue("M{$row}", $e->chat_id);
            // Center align numeric columns
            $sheet->getStyle("F{$row}:J{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        // Make trigger column wider
        $sheet->getColumnDimension('K')->setWidth(35);
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
            $sheet->getStyle("A1:M{$lastRow}")->applyFromArray($styleArray);
        }

        // Freeze header
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:M{$lastRow}");

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
        $headers = ['تاریخ شمسی','روز هفته','تاریخ میلادی','ساعت خواب','ساعت بیداری','کار مفید (ساعت)','باشگاه','گیم (دقیقه)','تعامل اجتماعی','حال (۱-۱۰)','محرک احساسی','پلتفرم','چت آی‌دی'];
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
                $e->platform,
                $e->chat_id,
            ];
            $lines[] = implode(',', array_map(fn($v) => '"' . str_replace('"','""',$v) . '"', $row));
        }
        // BOM for Excel to recognize UTF-8
        return "\xEF\xBB\xBF" . implode("\n", $lines);
    }
}
