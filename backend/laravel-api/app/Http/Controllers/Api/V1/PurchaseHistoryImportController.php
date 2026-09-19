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
 * One-click Purchase Report import — auto-detects Supplier Purchase Listing / Product Purchase
 * Report / Purchase Order Tracking (see PurchaseHistoryImportService), no manual column-mapping
 * step. store() runs the full parse synchronously (all 3 source files are small) and always
 * leaves the batch PREVIEWED with the mandatory pre-import summary (detected type, valid/skipped
 * counts, computed defaults, warnings, and — only when something needs a human decision — the
 * resolve list). resolve() is the single confirm-and-queue step, called whether or not there was
 * anything to resolve — the summary must be seen before anything is queued either way.
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

        // Always PREVIEWED, never auto-dispatched — the pre-import summary must be shown and
        // explicitly confirmed (via resolve()) before anything is queued, even when nothing needs
        // a human decision.
        $batch = ImportBatch::query()->create([
            'module' => 'purchase-history',
            'status' => ImportBatchStatus::PREVIEWED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
            'mapping' => $mapping,
            'total_rows' => $preflight['total_rows'],
            'preview_summary' => [
                'type_label' => $preflight['type_label'],
                'valid_count' => $preflight['valid_count'],
                'skipped_count' => $preflight['skipped_count'],
                'computed_defaults' => $preflight['computed_defaults'],
                'warnings' => $preflight['warnings'],
                'needs_resolution' => $preflight['needs_resolution'],
            ],
            'created_by' => Auth::id(),
        ]);

        return $this->success(new ImportBatchResource($batch), 'Menunggu konfirmasi import.', 201);
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
