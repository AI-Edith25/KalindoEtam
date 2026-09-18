<?php

namespace App\Jobs;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Services\AuditLogService;
use App\Services\Import\SalesPurchaseJournalImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Thin queue wrapper — all parsing/posting logic lives in
 * SalesPurchaseJournalImportService, mirrors ProcessCashBookImportJob's
 * shape. A much longer timeout than that job's 600s: the real Sales Journal
 * Invoice export is on the order of 60-100k transaction groups, each posted
 * through the ordinary JournalEntryService::create()+post() (naming-series
 * lock + DocumentTimeline write per entry, deliberately not bypassed — see
 * the service's own docblock) — a first real run on a file that size is
 * expected to take tens of minutes, not seconds. The production queue
 * worker's own timeout must be raised to match.
 */
class ProcessSalesPurchaseJournalImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(public string $importBatchId) {}

    public function handle(SalesPurchaseJournalImportService $service, AuditLogService $auditLogService): void
    {
        $batch = ImportBatch::query()->findOrFail($this->importBatchId);

        $service->import($batch);

        $batch->refresh();
        $auditLogService->record(
            'imported',
            'sales_purchase_journal',
            "Imported {$batch->module}: {$batch->success_rows} succeeded, {$batch->failed_rows} failed.",
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
}
