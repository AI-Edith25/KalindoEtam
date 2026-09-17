<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportBatchRequest;
use App\Http\Resources\ImportBatchResource;
use App\Jobs\ProcessGeneralLedgerImportJob;
use App\Models\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * One-click Print Ledger (opening balance) import — upload immediately
 * queues ProcessGeneralLedgerImportJob; show() is polled the same way
 * PaymentVoucherImportController::show() is. Separate from the read-only
 * GeneralLedgerController on purpose — see that controller's own docblock.
 */
class GeneralLedgerImportController extends Controller
{
    use ApiResponse;

    public function store(StoreImportBatchRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('imports', 'local');

        $batch = ImportBatch::query()->create([
            'module' => 'general-ledger-opening-balance',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
            'queued_at' => now(),
            'created_by' => Auth::id(),
        ]);

        ProcessGeneralLedgerImportJob::dispatch($batch->id);

        return $this->success(new ImportBatchResource($batch), 'Import queued.', 201);
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        abort_unless($batch->module === 'general-ledger-opening-balance', 404);

        return $this->success(new ImportBatchResource($batch));
    }
}
