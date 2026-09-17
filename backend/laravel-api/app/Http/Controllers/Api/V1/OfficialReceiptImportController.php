<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportBatchRequest;
use App\Http\Resources\ImportBatchResource;
use App\Jobs\ProcessOfficialReceiptImportJob;
use App\Models\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * One-click Official Receipt import — the AR mirror of
 * PaymentVoucherImportController. Upload immediately queues
 * ProcessOfficialReceiptImportJob; show() is polled the same way.
 */
class OfficialReceiptImportController extends Controller
{
    use ApiResponse;

    public function store(StoreImportBatchRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('imports', 'local');

        $batch = ImportBatch::query()->create([
            'module' => 'official-receipts',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
            'queued_at' => now(),
            'created_by' => Auth::id(),
        ]);

        ProcessOfficialReceiptImportJob::dispatch($batch->id);

        return $this->success(new ImportBatchResource($batch), 'Import queued.', 201);
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        abort_unless($batch->module === 'official-receipts', 404);

        return $this->success(new ImportBatchResource($batch));
    }
}
