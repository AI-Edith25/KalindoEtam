<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSmartOpeningStockImportRequest;
use App\Http\Resources\ImportBatchResource;
use App\Models\ImportBatch;
use App\Services\AuditLogService;
use App\Services\Import\SmartOpeningStockImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * "Smart" Opening Stock import for raw legacy exports (FIFO Opening Quantity,
 * Stock Balance, ...) — a distinct pipeline from the strict-template
 * OpeningStockImportTemplate flow reachable at /inventory/opening-stock/quick-import,
 * which is completely untouched by this controller. See
 * SmartOpeningStockImportService's own docblock for why these are kept separate.
 *
 * store() always leaves the batch PREVIEWED — the pre-import summary (warehouses
 * detected, rows per warehouse, unmatched items/warehouses, price conflicts,
 * skipped rows) must be seen before anything commits. resolve() runs the actual
 * commit synchronously (these files are small) and returns the finished result
 * immediately — there is no queued/processing state to poll for here, unlike
 * every other importer in this app.
 */
class SmartOpeningStockImportController extends Controller
{
    use ApiResponse;

    private const MODULE = 'opening-stock-smart';

    public function __construct(protected SmartOpeningStockImportService $importService) {}

    public function store(StoreSmartOpeningStockImportRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('imports', 'local');
        $absolutePath = Storage::disk('local')->path($path);
        $extension = $file->getClientOriginalExtension();
        $metadataCutoffDate = $request->string('metadata_cutoff_date')->value() ?: null;

        $preflight = $this->importService->preflight($absolutePath, $extension, $metadataCutoffDate);

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
            'mapping' => ['metadata_cutoff_date' => $metadataCutoffDate],
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
        $metadataCutoffDate = $batch->mapping['metadata_cutoff_date'] ?? null;

        $result = $this->importService->commit($absolutePath, $extension, $metadataCutoffDate);

        $batch->update([
            // FAILED means "every group we tried to commit errored out" — zero documents
            // because there was simply nothing valid to commit (already visible in the
            // preview) is not a new failure, so that case stays COMPLETED.
            'status' => ($result['documents_created'] === 0 && $result['failures'] !== []) ? ImportBatchStatus::FAILED : ImportBatchStatus::COMPLETED,
            'success_rows' => $result['documents_created'],
            'failed_rows' => count($result['failures']),
            'preview_summary' => $result,
        ]);

        $auditLogService->record(
            'imported',
            'opening_stock',
            "Smart-imported Opening Stock: {$result['documents_created']} document(s) created across ".implode(', ', $result['warehouses']).'.',
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
