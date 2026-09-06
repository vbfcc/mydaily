<?php

namespace App\Console\Commands;

use App\Services\ExportService;
use Illuminate\Console\Command;

class DailyExportCommand extends Command
{
    protected $signature = 'daily:export
        {--chat_id= : Filter by chat_id (if not set, exports all)}
        {--platform=telegram : Filter by platform (telegram|bale)}
        {--format=excel : Output format: excel|csv|json}
        {--from= : From date Y-m-d (miladi)}
        {--to= : To date Y-m-d (miladi)}
        {--output= : Output file path (default: storage/app/exports/...)}';

    protected $description = 'Export MyDaily entries with Shamsi dates (Excel/CSV/JSON)';

    public function handle(ExportService $exportService): int
    {
        $chatId = $this->option('chat_id');
        $platform = $this->option('platform') ?? 'telegram';
        $format = strtolower($this->option('format') ?? 'excel');
        $from = $this->option('from');
        $to = $this->option('to');
        $output = $this->option('output');

        $entries = $chatId
            ? $exportService->getEntries($chatId, $platform, $from, $to)
            : $exportService->getAllEntries($from, $to);

        if ($entries->isEmpty()) {
            $this->warn('No entries found.');
            return 1;
        }

        $this->info("Found {$entries->count()} entries. Format: {$format} — Shamsi via ShamsiDateHelper");

        if ($format === 'json') {
            $data = $exportService->toArrayWithShamsi($entries);
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($output) {
                file_put_contents($output, $json);
                $this->info("JSON saved to: {$output}");
            } else {
                $this->line($json);
            }
            return 0;
        }

        if ($format === 'csv') {
            $csv = $exportService->generateCsv($entries);
            $path = $output ?? storage_path('app/exports/mydaily-' . ($chatId ?? 'all') . '-' . now()->format('Y-m-d_His') . '.csv');
            $dir = dirname($path);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($path, $csv);
            $this->info("CSV saved to: {$path}");
            return 0;
        }

        // excel
        $path = $output ?? storage_path('app/exports/mydaily-' . ($chatId ?? 'all') . '-' . now()->format('Y-m-d_His') . '.xlsx');
        $exportService->generateExcel($entries, $path);
        $this->info("Excel saved to: {$path}");
        $this->line("Columns: تاریخ شمسی | روز هفته (ShamsiDateHelper::dateWithDay) | تاریخ میلادی | ... — readable by Excel and AI");
        return 0;
    }
}
