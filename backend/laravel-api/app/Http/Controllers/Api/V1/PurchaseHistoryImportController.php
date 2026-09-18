<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResolvePurchaseHistoryImportRequest;
use App\Http\Requests\StorePurchaseHistoryImportRequest;
use App\Http\Resources\ImportBatchResource;
use App\Jobs\ProcessPurchaseHistoryImportJob;
use App\Models\ImportBatch;
use App\Services\Import\PurchaseHistoryImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * One-click Purchase Report import — auto-detects Product Purchase Report vs. Purchase Order
 * Tracking (see PurchaseHistoryImportService), no manual column-mapping step. A resolve step only
 * ever appears when something genuinely needs a human decision (unmatched supplier/item, a
 * duplicate document/PO number) — store() runs the full parse synchronously (both source files
 * are small) and either dispatches the real job immediately (nothing to resolve) or holds the
 * batch and returns the resolve list (something does).
 */
class PurchaseHistoryImportController extends Controller
{
    use ApiResponse;

    public function __construct(protected PurchaseHistoryImportService $importService) {}

    public function store(StorePurchaseHistoryImportRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('imports', 'local');
        $absolutePath = Storage::disk('local')->path($path);
        $extension = $file->getClientOriginalExtension();

        $preflight = $this->importService->preflight($absolutePath, $extension);

        if (isset($preflight['error'])) {
            Storage::disk('local')->delete($path);
            abort(422, $preflight['error']);
        }

        $mapping = [
            'type' => $preflight['type'],
            'warehouse_id' => $request->string('warehouse_id')->value(),
            'placeholder_item_id' => $request->string('placeholder_item_id')->value(),
        ];

        $needsResolution = $preflight['needs_resolution'];

        $batch = ImportBatch::query()->create([
            'module' => 'purchase-history',
            'status' => $needsResolution === [] ? ImportBatchStatus::QUEUED : ImportBatchStatus::PREVIEWED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
            'mapping' => $mapping,
            'total_rows' => $preflight['total_rows'],
            'preview_summary' => $needsResolution !== [] ? ['needs_resolution' => $needsResolution, 'warnings' => $preflight['warnings']] : null,
            'queued_at' => $needsResolution === [] ? now() : null,
            'created_by' => Auth::id(),
        ]);

        if ($needsResolution === []) {
            ProcessPurchaseHistoryImportJob::dispatch($batch->id);
        }

        return $this->success(new ImportBatchResource($batch), $needsResolution === [] ? 'Import queued.' : 'Resolution needed.', 201);
    }

    public function resolve(ResolvePurchaseHistoryImportRequest $request, ImportBatch $batch): JsonResponse
    {
        abort_unless($batch->module === 'purchase-history', 404);
        abort_unless($batch->status === ImportBatchStatus::PREVIEWED, 422, 'This batch is not awaiting resolution.');

        $resolutions = ['supplier' => [], 'item' => [], 'duplicate' => []];

        foreach ($request->input('resolutions', []) as $entry) {
            $resolutions[$entry['category']][$entry['value']] = ['action' => $entry['action'], 'target_id' => $entry['target_id'] ?? null];
        }

        $batch->update([
            'fk_resolutions' => $resolutions,
            'status' => ImportBatchStatus::QUEUED,
            'queued_at' => now(),
        ]);

        ProcessPurchaseHistoryImportJob::dispatch($batch->id);

        return $this->success(new ImportBatchResource($batch), 'Import queued.', 201);
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        abort_unless($batch->module === 'purchase-history', 404);

        return $this->success(new ImportBatchResource($batch));
    }
}
