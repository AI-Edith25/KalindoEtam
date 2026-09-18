<?php

namespace App\Jobs;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Services\AuditLogService;
use App\Services\Import\TrialBalanceImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Thin queue wrapper — all parsing/matching/posting logic lives in TrialBalanceImportService, mirrors ProcessGeneralLedgerImportJob's shape. Default timeout: the file is one row per Chart of Account (dozens to low hundreds), nowhere near Sales Journal's ~170k-row scale. */
class ProcessTrialBalanceImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $importBatchId) {}

    public function handle(TrialBalanceImportService $service, AuditLogService $auditLogService): void
    {
        $batch = ImportBatch::query()->findOrFail($this->importBatchId);

        $service->import($batch);

        $batch->refresh();
        $auditLogService->record(
            'imported',
            'trial_balance',
            "Imported Trial Balance: {$batch->success_rows} accounts matched, {$batch->failed_rows} unmatched.",
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
