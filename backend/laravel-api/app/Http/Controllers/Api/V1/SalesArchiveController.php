<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Exports\SalesArchiveExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexSalesArchiveRequest;
use App\Http\Requests\StoreSalesArchiveRequest;
use App\Http\Resources\ImportBatchResource;
use App\Models\ImportBatch;
use App\Models\ProductSalesSnapshot;
use App\Models\SalesListingSnapshot;
use App\Services\AuditLogService;
use App\Services\Import\ProductSalesArchiveImportService;
use App\Services\Import\SalesListingArchiveImportService;
use App\Services\ProductSalesArchiveService;
use App\Services\SalesListingArchiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Sales Report's import archive -- 2 file types (Sales Listing / Product Sales Detail), never
 * merged (see the 2 import services), feeding 3 of the 4 Sales Report tabs. Unlike the AR/AP
 * archives, snapshots stack per period rather than "latest wins" -- see the import services'
 * commit() for the per-(period_start, period_end) replace rule. Orchestration only; see
 * SalesListingArchiveService/ProductSalesArchiveService for the actual query logic.
 */
class SalesArchiveController extends Controller
{
    use ApiResponse;

    private const MODULE = 'sales-archive';

    public function __construct(
        protected SalesListingArchiveService $salesListingArchive,
        protected ProductSalesArchiveService $productSalesArchive,
        protected SalesListingArchiveImportService $salesListingImport,
        protected ProductSalesArchiveImportService $productSalesImport,
    ) {}

    public function meta(): JsonResponse
    {
        $slPeriod = $this->salesListingArchive->combinedPeriod() ?? ['period_start' => null, 'period_end' => null];
        $psPeriod = $this->productSalesArchive->combinedPeriod() ?? ['period_start' => null, 'period_end' => null];

        return $this->success([
            'sales_listing' => ['has_snapshot' => $this->salesListingArchive->hasSnapshot(), ...$slPeriod],
            'product_sales_detail' => ['has_snapshot' => $this->productSalesArchive->hasSnapshot(), ...$psPeriod],
        ]);
    }

    public function history(): JsonResponse
    {
        $listing = SalesListingSnapshot::query()->get()->map(fn (SalesListingSnapshot $s) => [
            'id' => $s->id,
            'file_type' => 'sales_listing',
            'period_start' => $s->period_start,
            'period_end' => $s->period_end,
            'source_filename' => $s->source_filename,
            'total_rows' => $s->total_rows,
            'total_value' => (float) $s->grand_total_amount_incl_tax,
            'created_at' => $s->created_at,
        ]);

        $products = ProductSalesSnapshot::query()->get()->map(fn (ProductSalesSnapshot $s) => [
            'id' => $s->id,
            'file_type' => 'product_sales_detail',
            'period_start' => $s->period_start,
            'period_end' => $s->period_end,
            'source_filename' => $s->source_filename,
            'total_rows' => $s->total_rows,
            'total_value' => (float) $s->grand_total_amount_excl_tax,
            'created_at' => $s->created_at,
        ]);

        return $this->success($listing->concat($products)->sortByDesc('created_at')->values());
    }

    public function store(StoreSalesArchiveRequest $request): JsonResponse
    {
        $fileType = $request->validated()['file_type'];
        $file = $request->file('file');
        $path = $file->store('imports', 'local');
        $absolutePath = Storage::disk('local')->path($path);

        try {
            $preflight = $fileType === 'sales_listing'
                ? $this->salesListingImport->preflight($absolutePath, $file->getClientOriginalExtension())
                : $this->productSalesImport->preflight($absolutePath, $file->getClientOriginalExtension());
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
            // file_type stashed here (not a new ImportBatch column) so resolve() knows which
            // import service/table to commit to.
            'preview_summary' => ['file_type' => $fileType, ...$preflight],
            'created_by' => Auth::id(),
        ]);

        return $this->success(new ImportBatchResource($batch), 'Menunggu konfirmasi import.', 201);
    }

    public function resolve(ImportBatch $batch, AuditLogService $auditLogService): JsonResponse
    {
        abort_unless($batch->module === self::MODULE, 404);
        abort_unless($batch->status === ImportBatchStatus::PREVIEWED, 422, 'This batch is not awaiting resolution.');

        $fileType = $batch->preview_summary['file_type'] ?? null;
        abort_if($fileType === null, 422, 'Batch is missing its file type.');

        $absolutePath = Storage::disk($batch->disk)->path($batch->file_path);
        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);

        try {
            $snapshot = $fileType === 'sales_listing'
                ? $this->salesListingImport->commit($absolutePath, $extension, $batch->original_filename, $batch->created_by)
                : $this->productSalesImport->commit($absolutePath, $extension, $batch->original_filename, $batch->created_by);
        } finally {
            Storage::disk($batch->disk)->delete($batch->file_path);
        }

        $batch->update(['status' => ImportBatchStatus::COMPLETED, 'success_rows' => $snapshot->total_rows]);

        $auditLogService->record(
            'imported',
            'sales_archive',
            "Imported Sales Archive ({$fileType}) snapshot: period {$snapshot->period_start} - {$snapshot->period_end}, {$snapshot->total_rows} rows.",
            userId: $batch->created_by,
        );

        return $this->success($snapshot, 'Snapshot berhasil diimpor.', 201);
    }

    public function salesListing(IndexSalesArchiveRequest $request): JsonResponse
    {
        [$filters, $page, $perPage] = $this->pagedFilters($request);
        $result = $this->salesListingArchive->salesListing($filters, $page, $perPage);

        return $this->success($result['data'], extraMeta: $this->pageMeta($page, $perPage, $result));
    }

    public function customerSales(IndexSalesArchiveRequest $request): JsonResponse
    {
        [$filters, $page, $perPage] = $this->pagedFilters($request);
        $result = $this->salesListingArchive->customerSales($filters, $page, $perPage);

        return $this->success($result['data'], extraMeta: $this->pageMeta($page, $perPage, $result));
    }

    public function productSales(IndexSalesArchiveRequest $request): JsonResponse
    {
        [$filters, $page, $perPage] = $this->pagedFilters($request);
        $group = $filters['group'] ?? 'item';
        $result = $this->productSalesArchive->productSales($filters, $group, $page, $perPage);

        return $this->success($result['data'], extraMeta: $this->pageMeta($page, $perPage, $result));
    }

    public function productSalesCustomers(string $itemCode, IndexSalesArchiveRequest $request): JsonResponse
    {
        return $this->success($this->productSalesArchive->customersForItem($itemCode, $request->validated()));
    }

    public function exportSalesListing(IndexSalesArchiveRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $rows = $this->salesListingArchive->salesListing($filters, 1, PHP_INT_MAX)['data'];

        $export = new SalesArchiveExport(
            ['Date', 'Document', 'Reference SO', 'Reference DO', 'Customer Code', 'Customer Name', 'Type', 'Amount Excl. Tax', 'Disc Adjustment', 'Tax', 'Amount Incl. Tax', 'Payment Status', 'Outstanding AR'],
            array_map(fn ($r) => [
                $r['date'], $r['document_number'], $r['reference_so_number'], $r['reference_do_number'], $r['customer_code'],
                $r['customer_name'], $r['type'], $r['amount'], $r['discount'], $r['tax'], $r['amount_incl_tax'],
                $r['payment_status'], $r['outstanding_ar'],
            ], $rows),
            'Sumber: import manual -- bukan data live.',
        );

        return Excel::download($export, 'SalesListingArsip.'.($filters['format'] ?? 'xlsx'));
    }

    public function exportCustomerSales(IndexSalesArchiveRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $rows = $this->salesListingArchive->customerSales($filters, 1, PHP_INT_MAX)['data'];

        $export = new SalesArchiveExport(
            ['Customer Code', 'Customer Name', 'Transactions', 'Amount Excl. Tax', 'Tax', 'Amount Incl. Tax', 'Last Transaction'],
            array_map(fn ($r) => [$r['customer_code'], $r['customer_name'], $r['transaction_count'], $r['amount'], $r['tax_amount'], $r['amount_incl_tax'], $r['last_transaction_date']], $rows),
            'Sumber: import manual -- bukan data live.',
        );

        return Excel::download($export, 'CustomerSalesArsip.'.($filters['format'] ?? 'xlsx'));
    }

    public function exportProductSales(IndexSalesArchiveRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $group = $filters['group'] ?? 'item';
        $rows = $this->productSalesArchive->productSales($filters, $group, 1, PHP_INT_MAX)['data'];

        $export = new SalesArchiveExport(
            ['Item Code', 'Description', 'Item Group', 'Qty', 'Amount Excl. Tax', 'Tax', 'Amount Incl. Tax'],
            array_map(fn ($r) => [$r['item_code'], $r['item_name'], $r['item_group_name'], $r['qty'], $r['amount'], $r['tax_amount'], $r['amount_incl_tax']], $rows),
            'Sumber: import manual -- bukan data live.',
        );

        return Excel::download($export, 'ProductSalesArsip.'.($filters['format'] ?? 'xlsx'));
    }

    /** @return array{0: array, 1: int, 2: int} [filters, page, perPage] */
    private function pagedFilters(IndexSalesArchiveRequest $request): array
    {
        $filters = $request->validated();

        return [$filters, $filters['page'] ?? 1, $filters['per_page'] ?? 25];
    }

    private function pageMeta(int $page, int $perPage, array $result): array
    {
        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $result['total'],
            'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
            'kpis' => $result['kpis'],
        ];
    }
}
