<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSmartStockAdjustmentImportRequest;
use App\Http\Resources\ImportBatchResource;
use App\Models\ImportBatch;
use App\Services\AuditLogService;
use App\Services\Import\SmartStockAdjustmentImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * "Smart" Stock Adjustment import — same raw-legacy-export pipeline shape as
 * SmartOpeningStockImportController, reconciling live stock to a counted balance instead of
 * opening a brand-new balance. See SmartStockAdjustmentImportService's own docblock for when to
 * use this instead of Opening Stock's smart import.
 */
class SmartStockAdjustmentImportController extends Controller
{
    use ApiResponse;

    private const MODULE = 'stock-adjustment-smart';

    public function __construct(protected SmartStockAdjustmentImportService $importService) {}

    public function store(StoreSmartStockAdjustmentImportRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('imports', 'local');
        $absolutePath = Storage::disk('local')->path($path);
        $extension = $file->getClientOriginalExtension();
        $metadataDate = $request->string('metadata_adjustment_date')->value() ?: null;

        $preflight = $this->importService->preflight($absolutePath, $extension, $metadataDate);

        if (isset($preflight['error'])) {
            Storage::disk('local')->delete($path);
            abort(422, $preflight['error']);
        }

        $batch = ImportBatch::query()->create([
            'module' => self::MODULE,
            'status' => ImportBatchStatus::PREVIEWED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
            'mapping' => ['metadata_adjustment_date' => $metadataDate],
            'total_rows' => $preflight['total_rows'],
            'preview_summary' => $preflight,
            'created_by' => Auth::id(),
        ]);

        return $this->success(new ImportBatchResource($batch), 'Menunggu konfirmasi import.', 201);
    }

    public function resolve(ImportBatch $batch, AuditLogService $auditLogService): JsonResponse
    {
        abort_unless($batch->module === self::MODULE, 404);
        abort_unless($batch->status === ImportBatchStatus::PREVIEWED, 422, 'This batch is not awaiting resolution.');

        $absolutePath = Storage::disk($batch->disk)->path($batch->file_path);
        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);
        $metadataDate = $batch->mapping['metadata_adjustment_date'] ?? null;

        $result = $this->importService->commit($absolutePath, $extension, $metadataDate);

        $batch->update([
            'status' => ($result['documents_created'] === 0 && $result['failures'] !== []) ? ImportBatchStatus::FAILED : ImportBatchStatus::COMPLETED,
            'success_rows' => $result['documents_created'],
            'failed_rows' => count($result['failures']),
            'preview_summary' => $result,
        ]);

        $auditLogService->record(
            'imported',
            'stock_adjustment',
            "Smart-imported Stock Adjustment: {$result['documents_created']} document(s) created across ".implode(', ', $result['warehouses']).'.',
            userId: $batch->created_by,
        );

        return $this->success(new ImportBatchResource($batch->fresh()), 'Import selesai.');
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        abort_unless($batch->module === self::MODULE, 404);

        return $this->success(new ImportBatchResource($batch));
    }
}
