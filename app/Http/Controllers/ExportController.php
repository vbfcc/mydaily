<?php

namespace App\Http\Controllers;

use App\Services\ExportService;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function __invoke(Request $request, ExportService $exportService)
    {
        $format = strtolower($request->query('format', 'json')); // json|excel|csv
        $chatId = $request->query('chat_id');
        $platform = $request->query('platform', 'telegram');
        $from = $request->query('from');
        $to = $request->query('to');

        // Fetch entries
        if ($chatId) {
            $entries = $exportService->getEntries($chatId, $platform, $from, $to);
        } else {
            $entries = $exportService->getAllEntries($from, $to);
        }

        if ($entries->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'No entries found', 'count' => 0], 404);
        }

        if ($format === 'excel' || $format === 'xlsx') {
            $fileName = 'mydaily-' . ($chatId ?? 'all') . '-' . now()->format('Y-m-d_His') . '.xlsx';
            $filePath = storage_path("app/exports/{$fileName}");
            $exportService->generateExcel($entries, $filePath);
            return response()->download($filePath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(false);
        }

        if ($format === 'csv') {
            $csv = $exportService->generateCsv($entries);
            $fileName = 'mydaily-' . ($chatId ?? 'all') . '-' . now()->format('Y-m-d_His') . '.csv';
            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            ]);
        }

        // Default JSON — AI-readable with Shamsi
        $data = $exportService->toArrayWithShamsi($entries);
        return response()->json([
            'ok' => true,
            'count' => $entries->count(),
            'generated_at' => now()->toIso8601String(),
            'generated_at_shamsi' => \App\Helpers\ShamsiDateHelper::fullDateTime(now()),
            'format' => 'json',
            'note' => 'تاریخ‌ها هم میلادی هم شمسی — ستون‌های date_miladi و date_shamsi / date_shamsi_with_day',
            'data' => $data,
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
