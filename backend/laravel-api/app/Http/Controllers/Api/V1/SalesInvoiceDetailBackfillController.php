<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesInvoiceDetailBackfillRequest;
use App\Http\Resources\ImportBatchResource;
use App\Models\ImportBatch;
use App\Services\AuditLogService;
use App\Services\Import\SalesInvoiceDetailBackfillService;
use App\Support\ImportErrorReportWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fills in real item-level detail on Invoices CustomerOutstandingArchiveImportService already
 * created — see SalesInvoiceDetailBackfillService's own docblock. Same preflight/resolve shape
 * as CustomerOutstandingArchiveController: store() always leaves the batch PREVIEWED, resolve()
 * commits synchronously (these files are small, same posture as that sibling controller).
 */
class SalesInvoiceDetailBackfillController extends Controller
{
    use ApiResponse;

    private const MODULE = 'sales-invoice-detail-backfill';

    public function __construct(protected SalesInvoiceDetailBackfillService $service) {}

    public function store(StoreSalesInvoiceDetailBackfillRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('imports', 'local');
        $absolutePath = Storage::disk('local')->path($path);

        try {
            $preflight = $this->service->preflight($absolutePath, $file->getClientOriginalExtension());
        } catch (Throwable $e) {
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

        ImportErrorReportWriter::attachRejectedRows($batch, $this->rejectedRowsReport($preflight));

        return $this->success(new ImportBatchResource($batch), 'Menunggu konfirmasi import.', 201);
    }

    /**
     * Flattens preflight()'s 5 skip categories into one CSV-ready report — lets the operator
     * download exactly which invoices won't be updated, and why, from the preview step.
     *
     * @param  array{skipped_not_found: array, skipped_customer_mismatch: array, skipped_type_mismatch: array, skipped_item_unresolved: array, skipped_amount_mismatch: array}  $preflight
     */
    private function rejectedRowsReport(array $preflight): array
    {
        $reasonByOutcome = [
            'skipped_not_found' => 'Nomor dokumen tidak ditemukan pada Invoice hasil import Customer Outstanding Bills',
            'skipped_customer_mismatch' => 'Customer pada file tidak cocok dengan Invoice yang ditemukan',
            'skipped_type_mismatch' => 'Jenis dokumen (Goods/Transportation) tidak cocok dengan Invoice yang ditemukan',
            'skipped_item_unresolved' => 'Ada kode ITEM yang tidak ditemukan di master Item maupun Miscellaneous Item',
            'skipped_amount_mismatch' => 'Total hasil hitung ulang dari file berbeda dari grand_total Invoice yang sudah ada',
        ];

        $report = [];
        foreach ($reasonByOutcome as $key => $reason) {
            foreach ($preflight[$key] as $row) {
                $report[] = [...$row, 'status' => $key, 'reason' => $reason];
            }
        }

        return $report;
    }

    public function resolve(ImportBatch $batch, AuditLogService $auditLogService): JsonResponse
    {
        abort_unless($batch->module === self::MODULE, 404);
        abort_unless($batch->status === ImportBatchStatus::PREVIEWED, 422, 'This batch is not awaiting resolution.');

        $absolutePath = Storage::disk($batch->disk)->path($batch->file_path);
        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);

        try {
            $result = $this->service->commit($absolutePath, $extension);
        } finally {
            Storage::disk($batch->disk)->delete($batch->file_path);
        }

        $batch->update(['status' => ImportBatchStatus::COMPLETED, 'success_rows' => $result['updated']]);

        $auditLogService->record(
            'imported',
            'sales_invoice_detail_backfill',
            "Updated item detail on {$result['updated']} Invoice(s) from Sales Invoice Listing - Detail.",
            userId: $batch->created_by,
        );

        return $this->success($result, 'Detail invoice berhasil disinkronkan.', 201);
    }
}
