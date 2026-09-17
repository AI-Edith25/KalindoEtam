<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportBatchRequest;
use App\Http\Resources\ImportBatchResource;
use App\Jobs\ProcessPaymentVoucherImportJob;
use App\Models\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * One-click Payment Voucher import — upload immediately queues
 * ProcessPaymentVoucherImportJob; show() is polled the same way
 * ImportController::show()/ImportStepCommit is, but this module never goes
 * through the generic mapping/preview wizard (see PaymentVoucherImportService).
 */
class PaymentVoucherImportController extends Controller
{
    use ApiResponse;

    public function store(StoreImportBatchRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('imports', 'local');

        $batch = ImportBatch::query()->create([
            'module' => 'payment-vouchers',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
            'queued_at' => now(),
            'created_by' => Auth::id(),
        ]);

        ProcessPaymentVoucherImportJob::dispatch($batch->id);

        return $this->success(new ImportBatchResource($batch), 'Import queued.', 201);
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        abort_unless($batch->module === 'payment-vouchers', 404);

        return $this->success(new ImportBatchResource($batch));
    }
}
