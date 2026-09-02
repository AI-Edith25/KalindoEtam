<?php

namespace App\Jobs;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Services\AuditLogService;
use App\Services\Import\ImportBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * First job in this codebase. Always the only commit path (no sync
 * alternative) — one code path instead of branching sync-vs-queued by file
 * size. All-or-nothing was already enforced before dispatch (see
 * ImportBatchService::commit()); this always processes chunk-by-chunk.
 */
class ProcessImportBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK_SIZE = 500;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $importBatchId) {}

    public function handle(ImportBatchService $service, AuditLogService $auditLogService): void
    {
        $batch = ImportBatch::query()->findOrFail($this->importBatchId);
        $batch->update(['status' => ImportBatchStatus::PROCESSING, 'started_at' => now()]);

        $template = $service->templateFor($batch->module);
        $modelClass = $template->model();
        $uniqueKey = $template->uniqueKeyField();

        $fkIdOverrides = $service->createMissingFkMasters($batch);
        $rows = $service->buildCleanedRows($batch, $fkIdOverrides);

        $failedHandle = fopen('php://temp', 'r+');
        $wroteFailedHeader = false;
        $success = 0;
        $failed = 0;

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $row) {
                if ($row['status'] === 'error') {
                    $failed++;
                    $this->appendFailedRow($failedHandle, $row, $wroteFailedHeader);

                    continue;
                }

                $keyValue = $row['data'][$uniqueKey];
                $exists = $modelClass::query()->where($uniqueKey, $keyValue)->exists();

                if ($batch->write_mode === 'insert_only' && $exists) {
                    $failed++;
                    $row['messages'][] = 'Already exists (insert-only mode).';
                    $this->appendFailedRow($failedHandle, $row, $wroteFailedHeader);

                    continue;
                }

                if ($batch->write_mode === 'update_only' && ! $exists) {
                    $failed++;
                    $row['messages'][] = 'Not found (update-only mode).';
                    $this->appendFailedRow($failedHandle, $row, $wroteFailedHeader);

                    continue;
                }

                try {
                    DB::transaction(fn () => $modelClass::query()->updateOrCreate([$uniqueKey => $keyValue], $row['data']));
                    $success++;
                } catch (Throwable $e) {
                    $failed++;
                    $row['messages'][] = $e->getMessage();
                    $this->appendFailedRow($failedHandle, $row, $wroteFailedHeader);
                }
            }

            $batch->increment('processed_rows', count($chunk));
            $batch->update(['success_rows' => $success, 'failed_rows' => $failed]);
        }

        rewind($failedHandle);
        $failedCsv = stream_get_contents($failedHandle);
        fclose($failedHandle);

        if ($failed > 0) {
            $errorReportPath = "imports/{$batch->id}-failed.csv";
            Storage::disk('local')->put($errorReportPath, $failedCsv);
            $batch->update(['error_report_path' => $errorReportPath]);
        }

        $batch->update(['status' => ImportBatchStatus::COMPLETED]);

        $auditLogService->record(
            'imported',
            $batch->module,
            "Imported {$batch->module}: {$success} succeeded, {$failed} failed.",
            userId: $batch->created_by,
        );
    }

    public function failed(Throwable $exception): void
    {
        ImportBatch::query()->find($this->importBatchId)?->update([
            'status' => ImportBatchStatus::FAILED,
            'failure_reason' => $exception->getMessage(),
        ]);
    }

    /** @param resource $handle */
    private function appendFailedRow($handle, array $row, bool &$wroteHeader): void
    {
        if (! $wroteHeader) {
            fputcsv($handle, [...array_keys($row['raw']), 'errors']);
            $wroteHeader = true;
        }

        fputcsv($handle, [...array_values($row['raw']), implode('; ', $row['messages'])]);
    }
}
