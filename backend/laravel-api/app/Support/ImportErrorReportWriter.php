<?php

namespace App\Support;

use App\Models\ImportBatch;
use Illuminate\Support\Facades\Storage;

/**
 * Small-batch counterpart to ProcessImportBatchJob's streamed failed-row CSV (that one chunks
 * ~170k-row master-data files; these 4 document importers process at most a few hundred rows per
 * the class docblocks, so building the whole string in memory is simpler and fine). Reuses the
 * same error_report_path column + ImportController::failedRows() download endpoint — nothing new
 * needed there, just populating it from these services too.
 */
class ImportErrorReportWriter
{
    /** @param  array<int, array<string, scalar|null>>  $rows */
    public static function toCsv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $handle = fopen('php://temp', 'r+');
        $headers = array_keys($rows[0]);
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($header) => $row[$header] ?? '', $headers));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Writes every non-'success' row in $report to a CSV and attaches it to the batch — a no-op
     * if everything succeeded. $report rows must include a 'status' key.
     *
     * @param  array<int, array<string, scalar|null>>  $report
     */
    public static function attachRejectedRows(ImportBatch $batch, array $report): void
    {
        $rejected = array_values(array_filter($report, fn ($row) => ($row['status'] ?? null) !== 'success'));

        if ($rejected === []) {
            return;
        }

        $path = "imports/{$batch->id}-rejected.csv";
        Storage::disk($batch->disk)->put($path, self::toCsv($rejected));
        $batch->update(['error_report_path' => $path]);
    }
}
