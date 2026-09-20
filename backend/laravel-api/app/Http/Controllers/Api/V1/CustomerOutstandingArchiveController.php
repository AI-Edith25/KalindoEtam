<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Exports\CustomerOutstandingArchiveExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShowCustomerOutstandingArchiveRequest;
use App\Http\Requests\StoreCustomerOutstandingArchiveRequest;
use App\Http\Resources\ImportBatchResource;
use App\Models\CustomerOutstandingSnapshot;
use App\Models\ImportBatch;
use App\Services\AuditLogService;
use App\Services\CustomerOutstandingArchiveService;
use App\Services\Import\CustomerOutstandingArchiveImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "Customer Outstanding Bills" -- a standalone reference notebook of imported legacy AR export
 * snapshots, not connected to the live Sales/Invoice/Customer/AR module in any way. See
 * CustomerOutstandingArchiveImportService (parsing/write) and CustomerOutstandingArchiveService
 * (query/read) for where the actual logic lives; this controller is orchestration only.
 *
 * store() always leaves the batch PREVIEWED -- rows parsed, customers, totals, any row that
 * failed to parse or a subtotal/Grand Total mismatch must be shown before anything commits.
 * resolve() runs the actual commit synchronously (these files are small) and returns the
 * finished snapshot immediately.
 */
class CustomerOutstandingArchiveController extends Controller
{
    use ApiResponse;

    private const MODULE = 'customer-outstanding-archive';

    public function __construct(
        protected CustomerOutstandingArchiveService $archiveService,
        protected CustomerOutstandingArchiveImportService $importService,
    ) {}

    public function snapshots(): JsonResponse
    {
        return $this->success($this->archiveService->snapshots());
    }

    public function store(StoreCustomerOutstandingArchiveRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('imports', 'local');
        $absolutePath = Storage::disk('local')->path($path);

        try {
            $preflight = $this->importService->preflight($absolutePath, $file->getClientOriginalExtension());
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
            'customer_outstanding_archive',
            "Imported Customer Outstanding Bills snapshot: {$snapshot->total_customers} customers, {$snapshot->total_rows} rows.",
            userId: $batch->created_by,
        );

        return $this->success($snapshot, 'Snapshot berhasil diimpor.', 201);
    }

    public function show(ShowCustomerOutstandingArchiveRequest $request, CustomerOutstandingSnapshot $snapshot): JsonResponse
    {
        return $this->success([
            'snapshot' => $snapshot->load('importer:id,name'),
            // Unfiltered header KPIs -- same "static, doesn't react to table filters" convention
            // as AP Detail's own summary cards.
            'summary' => $this->archiveService->snapshotSummary($snapshot),
            ...$this->archiveService->groupedDetail($snapshot->id, $request->validated()),
        ]);
    }

    public function export(ShowCustomerOutstandingArchiveRequest $request, CustomerOutstandingSnapshot $snapshot): BinaryFileResponse
    {
        $format = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'csv'])]])['format'] ?? 'xlsx';
        $detail = $this->archiveService->groupedDetail($snapshot->id, $request->validated());

        $export = new CustomerOutstandingArchiveExport($detail['customers'], $detail['grand_total_unpaid'], $detail['grand_total_overdue'], $snapshot->snapshot_as_of_date);

        return Excel::download($export, "PiutangCustomerArsip.{$format}");
    }
}
