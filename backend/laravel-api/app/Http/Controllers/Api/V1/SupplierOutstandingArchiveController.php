<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Exports\SupplierOutstandingArchiveExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShowSupplierOutstandingArchiveRequest;
use App\Http\Requests\StoreSupplierOutstandingArchiveRequest;
use App\Http\Resources\ImportBatchResource;
use App\Models\ImportBatch;
use App\Models\SupplierOutstandingSnapshot;
use App\Services\AuditLogService;
use App\Services\Import\SupplierOutstandingArchiveImportService;
use App\Services\SupplierOutstandingArchiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** AP mirror of CustomerOutstandingArchiveController -- see that class's own docblock. */
class SupplierOutstandingArchiveController extends Controller
{
    use ApiResponse;

    private const MODULE = 'supplier-outstanding-archive';

    public function __construct(
        protected SupplierOutstandingArchiveService $archiveService,
        protected SupplierOutstandingArchiveImportService $importService,
    ) {}

    public function snapshots(): JsonResponse
    {
        return $this->success($this->archiveService->snapshots());
    }

    public function store(StoreSupplierOutstandingArchiveRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('imports', 'local');
        $absolutePath = Storage::disk('local')->path($path);

        try {
            $preflight = $this->importService->preflight($absolutePath, $file->getClientOriginalExtension(), $file->getClientOriginalName());
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        $batch = ImportBatch::query()->create([
            'module' => self::MODULE,
            'status' => ImportBatchStatus::PREVIEWED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
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

        try {
            $snapshot = $this->importService->commit($absolutePath, $extension, $batch->original_filename, $batch->created_by);
        } finally {
            Storage::disk($batch->disk)->delete($batch->file_path);
        }

        $batch->update(['status' => ImportBatchStatus::COMPLETED, 'success_rows' => $snapshot->total_rows]);

        $auditLogService->record(
            'imported',
            'supplier_outstanding_archive',
            "Imported Supplier Outstanding Bills snapshot: {$snapshot->total_suppliers} suppliers, {$snapshot->total_rows} rows.",
            userId: $batch->created_by,
        );

        return $this->success($snapshot, 'Snapshot berhasil diimpor.', 201);
    }

    public function show(ShowSupplierOutstandingArchiveRequest $request, SupplierOutstandingSnapshot $snapshot): JsonResponse
    {
        return $this->success([
            'snapshot' => $snapshot->load('importer:id,name'),
            'summary' => $this->archiveService->snapshotSummary($snapshot),
            ...$this->archiveService->groupedDetail($snapshot->id, $request->validated()),
        ]);
    }

    public function export(ShowSupplierOutstandingArchiveRequest $request, SupplierOutstandingSnapshot $snapshot): BinaryFileResponse
    {
        $format = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'csv'])]])['format'] ?? 'xlsx';
        $detail = $this->archiveService->groupedDetail($snapshot->id, $request->validated());

        $export = new SupplierOutstandingArchiveExport($detail['suppliers'], $detail['grand_total_unpaid'], $detail['grand_total_overdue'], $snapshot->snapshot_as_of_date);

        return Excel::download($export, "HutangSupplierArsip.{$format}");
    }
}
