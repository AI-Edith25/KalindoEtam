<?php

namespace App\Jobs;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Services\AuditLogService;
use App\Services\Import\SkybizLedgerReconciliationImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Thin queue wrapper, mirrors ProcessOfficialReceiptImportJob's shape — all parsing/matching/
 * writing logic lives in SkybizLedgerReconciliationImportService. Dispatched twice per batch:
 * once with $commit=false (preview, status -> PREVIEWED) and once with $commit=true after the
 * user confirms (status -> COMPLETED) — see SkybizLedgerReconciliationImportController.
 *
 * Longer timeout than the other "smart import" jobs — this file is an order of magnitude bigger
 * (55k+ ledger rows vs a few hundred vouchers).
 */
class ProcessSkybizLedgerReconciliationImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public string $importBatchId, public bool $commit) {}

    public function handle(SkybizLedgerReconciliationImportService $service, AuditLogService $auditLogService): void
    {
        $batch = ImportBatch::query()->findOrFail($this->importBatchId);

        $service->run($batch, $this->commit);

        if ($this->commit) {
            $batch->refresh();
            $auditLogService->record(
                'imported',
                'receipt_entry',
                "Skybiz ledger reconciliation: {$batch->success_rows} Official Receipts created, {$batch->failed_rows} failed.",
                userId: $batch->created_by,
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        ImportBatch::query()->find($this->importBatchId)?->update([
            'status' => ImportBatchStatus::FAILED,
            'failure_reason' => $exception->getMessage(),
        ]);
    }
}
