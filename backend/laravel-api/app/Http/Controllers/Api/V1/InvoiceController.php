<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Concerns\ExportsSalesList;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexInvoiceRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceBranchRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InvoiceController extends Controller
{
    use ApiResponse, ExportsSalesList;

    /** Ordered [columnKey => label] — mirrors InvoiceListPage.tsx's own table columns. */
    protected const COLUMNS = [
        'invoice_date' => 'Date',
        'document_number' => 'Document',
        'reference' => 'Reference',
        'attention' => 'Attention',
        'customer_name' => 'Customer Name',
        'subtotal' => 'Gross Amount',
        'tax_amount' => 'Tax',
        'grand_total' => 'Amount',
        'status' => 'Status',
    ];

    public function __construct(protected InvoiceService $invoiceService) {}

    public function index(IndexInvoiceRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(InvoiceResource::collection(
            $this->invoiceService->list($filters, $perPage)
        ));
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->invoiceService->create($request->validated());

        return $this->success(new InvoiceResource($invoice), 'Invoice created.', 201);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return $this->success(new InvoiceResource($invoice->load([
            'customer', 'salesPerson', 'salesOrder.salesPerson', 'salesOrder.branch', 'salesOrders', 'branch', 'delivery.warehouse', 'deliveries', 'items.deliveryItem.delivery.salesOrder.salesPerson', 'termsOfPayment', 'accountsReceivable.receiptEntryItems.receiptEntry.cashAccount', 'creditNotes', 'debitNotes',
        ])));
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $invoice = $this->invoiceService->update($invoice, $request->validated());

        return $this->success(new InvoiceResource($invoice), 'Invoice updated.');
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->invoiceService->delete($invoice);

        return $this->success(null, 'Invoice deleted.');
    }

    public function submit(Invoice $invoice): JsonResponse
    {
        $invoice = $this->invoiceService->submit($invoice);

        return $this->success(new InvoiceResource($invoice), 'Invoice submitted.');
    }

    public function cancel(Invoice $invoice): JsonResponse
    {
        $invoice = $this->invoiceService->cancel($invoice);

        return $this->success(new InvoiceResource($invoice), 'Invoice cancelled.');
    }

    public function updateBranch(UpdateInvoiceBranchRequest $request, Invoice $invoice): JsonResponse
    {
        $invoice = $this->invoiceService->updateBranch($invoice, $request->validated()['branch_id']);

        return $this->success(new InvoiceResource($invoice), 'Branch updated.');
    }

    /**
     * Plain list bulk export — same contract as SalesOrderController::export(). Distinct from
     * the existing invoices/export/sales-report (SalesReportController), which stays as-is for
     * its own Summary/Detail report shape; this is the generic "export what's on screen" action.
     */
    public function export(IndexInvoiceRequest $request): BinaryFileResponse
    {
        $extra = $request->validate([
            'format' => ['sometimes', Rule::in(['xlsx', 'csv'])],
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['uuid'],
            'columns' => ['sometimes', 'array'],
            'columns.*' => [Rule::in(array_keys(self::COLUMNS))],
        ]);

        $filters = $request->validated();
        unset($filters['per_page']);

        $rows = $this->invoiceService->listAll($filters, $extra['ids'] ?? null);

        return $this->exportSalesList(
            $rows,
            self::COLUMNS,
            $extra['columns'] ?? null,
            fn (Invoice $row, string $key) => match ($key) {
                'invoice_date' => $row->invoice_date?->format('Y-m-d'),
                'document_number' => $row->document_number,
                'reference' => $row->deliveries->pluck('document_number')->filter()->implode(', '),
                'attention' => $row->salesOrder?->attention,
                'customer_name' => $row->customer?->customer_name,
                'subtotal' => (float) $row->subtotal,
                'tax_amount' => (float) $row->tax_amount,
                'grand_total' => (float) $row->grand_total,
                'status' => ucfirst($row->status?->value ?? ''),
                default => null,
            },
            'invoices',
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $extra['format'] ?? 'xlsx',
        );
    }
}
