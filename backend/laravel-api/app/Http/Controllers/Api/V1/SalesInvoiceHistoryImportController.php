<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveSalesInvoiceHistoryImportRequest;
use App\Http\Requests\StoreSalesInvoiceHistoryImportRequest;
use App\Http\Resources\ImportBatchResource;
use App\Jobs\ProcessSalesInvoiceHistoryImportJob;
use App\Models\ImportBatch;
use App\Services\Import\SalesInvoiceImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * One file shape only (unlike PurchaseHistoryImportController's 3 auto-detected types), so no
 * expected_type check — see SalesInvoiceHistoryParser/SalesInvoiceImportService. store() always
 * leaves the batch PREVIEWED with the pre-import summary; resolve() is the single confirm-and-
 * queue step, called whether or not anything needed a human decision.
 */
class SalesInvoiceHistoryImportController extends Controller
{
    use ApiResponse;

    public function __construct(protected SalesInvoiceImportService $importService) {}

    public function store(StoreSalesInvoiceHistoryImportRequest $request): JsonResponse
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

        $batch = ImportBatch::query()->create([
            'module' => 'sales-invoice-history',
            'status' => ImportBatchStatus::PREVIEWED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
            'mapping' => ['warehouse_id' => $request->string('warehouse_id')->value()],
            'total_rows' => $preflight['total_rows'],
            'preview_summary' => [
                'valid_count' => $preflight['valid_count'],
                'skipped_count' => $preflight['skipped_count'],
                'warnings' => $preflight['warnings'],
                'needs_resolution' => $preflight['needs_resolution'],
            ],
            'created_by' => Auth::id(),
        ]);

        return $this->success(new ImportBatchResource($batch), 'Menunggu konfirmasi import.', 201);
    }

    public function resolve(ResolveSalesInvoiceHistoryImportRequest $request, ImportBatch $batch): JsonResponse
    {
        abort_unless($batch->module === 'sales-invoice-history', 404);
        abort_unless($batch->status === ImportBatchStatus::PREVIEWED, 422, 'This batch is not awaiting resolution.');

        $resolutions = ['customer' => [], 'item' => [], 'duplicate' => []];

        foreach ($request->input('resolutions', []) as $entry) {
            $resolutions[$entry['category']][$entry['value']] = ['action' => $entry['action'], 'target_id' => $entry['target_id'] ?? null];
        }

        $batch->update([
            'fk_resolutions' => $resolutions,
            'status' => ImportBatchStatus::QUEUED,
            'queued_at' => now(),
        ]);

        ProcessSalesInvoiceHistoryImportJob::dispatch($batch->id);

        return $this->success(new ImportBatchResource($batch), 'Import queued.', 201);
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        abort_unless($batch->module === 'sales-invoice-history', 404);

        return $this->success(new ImportBatchResource($batch));
    }
}
