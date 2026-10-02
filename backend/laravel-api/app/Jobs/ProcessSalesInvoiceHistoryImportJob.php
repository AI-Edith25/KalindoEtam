<?php

namespace App\Jobs;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Services\AuditLogService;
use App\Services\Import\SalesInvoiceImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Thin queue wrapper — all parsing/matching/document-creation logic lives in SalesInvoiceImportService. */
class ProcessSalesInvoiceHistoryImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // A 16k-row file (6000+ invoices) measured ~8600 rows processed in the old 1200s budget
    // (~7.2 rows/sec) before being killed — same ceiling ProcessSalesPurchaseJournalImportJob
    // already uses for its own large exports.
    public int $timeout = 7200;

    public function __construct(public string $importBatchId) {}

    public function handle(SalesInvoiceImportService $service, AuditLogService $auditLogService): void
    {
        $batch = ImportBatch::query()->findOrFail($this->importBatchId);

        $service->import($batch);

        $batch->refresh();
        $auditLogService->record(
            'imported',
            'sales_invoice_history',
            "Imported Sales Invoice History: {$batch->success_rows} succeeded, {$batch->failed_rows} failed.",
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
