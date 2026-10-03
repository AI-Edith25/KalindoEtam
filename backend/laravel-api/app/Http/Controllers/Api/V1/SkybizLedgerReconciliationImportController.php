<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportBatchRequest;
use App\Http\Resources\ImportBatchResource;
use App\Jobs\ProcessSkybizLedgerReconciliationImportJob;
use App\Models\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Skybiz Customer Ledger reconciliation import — unlike the other "smart
 * one-click" importers, this one has a mandatory preview gate (this is a
 * one-time rewrite of AR payment history across ~16k invoices): store()
 * only ever previews (dry-run, status -> PREVIEWED, nothing written),
 * confirm() is the explicit, separate step that actually posts Official
 * Receipts. Both are queued — see ProcessSkybizLedgerReconciliationImportJob.
 */
class SkybizLedgerReconciliationImportController extends Controller
{
    use ApiResponse;

    private const MODULE = 'skybiz-ledger-reconciliation';

    public function store(StoreImportBatchRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('imports', 'local');

        $batch = ImportBatch::query()->create([
            'module' => self::MODULE,
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
            'queued_at' => now(),
            'created_by' => Auth::id(),
        ]);

        ProcessSkybizLedgerReconciliationImportJob::dispatch($batch->id, commit: false);

        return $this->success(new ImportBatchResource($batch), 'Preview queued.', 201);
    }

    public function confirm(ImportBatch $batch): JsonResponse
    {
        abort_unless($batch->module === self::MODULE, 404);
        abort_unless($batch->status === ImportBatchStatus::PREVIEWED, 409, 'Jalankan preview terlebih dahulu.');

        $batch->update(['status' => ImportBatchStatus::QUEUED]);
        ProcessSkybizLedgerReconciliationImportJob::dispatch($batch->id, commit: true);

        return $this->success(new ImportBatchResource($batch), 'Import queued.');
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        abort_unless($batch->module === self::MODULE, 404);

        return $this->success(new ImportBatchResource($batch));
    }
}
