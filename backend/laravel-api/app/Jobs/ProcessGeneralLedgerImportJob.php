<?php

namespace App\Jobs;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Services\AuditLogService;
use App\Services\Import\PrintLedgerImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Thin queue wrapper — all parsing/matching/posting logic lives in PrintLedgerImportService, mirrors ProcessPaymentVoucherImportJob's shape. */
class ProcessGeneralLedgerImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $importBatchId) {}

    public function handle(PrintLedgerImportService $service, AuditLogService $auditLogService): void
    {
        $batch = ImportBatch::query()->findOrFail($this->importBatchId);

        $service->import($batch);

        $batch->refresh();
        $auditLogService->record(
            'imported',
            'journal_entry',
            "Imported Print Ledger Opening Balance: {$batch->success_rows} succeeded, {$batch->failed_rows} failed.",
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
