<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\PurchaseInvoiceExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPurchaseInvoiceRequest;
use App\Http\Requests\StorePurchaseInvoiceRequest;
use App\Http\Requests\UpdatePurchaseInvoiceRequest;
use App\Http\Resources\PurchaseInvoiceResource;
use App\Models\PurchaseInvoice;
use App\Services\PurchaseInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PurchaseInvoiceController extends Controller
{
    use ApiResponse;

    protected const EAGER = ['supplier', 'purchaseOrder', 'purchaseOrders', 'goodsReceipt.warehouse', 'goodsReceipts', 'items.item', 'accountsPayable', 'purchaseReturns'];

    public function __construct(protected PurchaseInvoiceService $purchaseInvoiceService) {}

    public function index(IndexPurchaseInvoiceRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(PurchaseInvoiceResource::collection(
            $this->purchaseInvoiceService->list($filters, $perPage)
        ));
    }

    public function store(StorePurchaseInvoiceRequest $request): JsonResponse
    {
        $purchaseInvoice = $this->purchaseInvoiceService->create($request->validated());

        return $this->success(new PurchaseInvoiceResource($purchaseInvoice), 'Purchase Invoice created.', 201);
    }

    public function show(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        return $this->success(new PurchaseInvoiceResource($purchaseInvoice->load(self::EAGER)));
    }

    public function update(UpdatePurchaseInvoiceRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $purchaseInvoice = $this->purchaseInvoiceService->update($purchaseInvoice, $request->validated());

        return $this->success(new PurchaseInvoiceResource($purchaseInvoice), 'Purchase Invoice updated.');
    }

    public function destroy(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->purchaseInvoiceService->delete($purchaseInvoice);

        return $this->success(null, 'Purchase Invoice deleted.');
    }

    public function submit(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $purchaseInvoice = $this->purchaseInvoiceService->submit($purchaseInvoice);

        return $this->success(new PurchaseInvoiceResource($purchaseInvoice), 'Purchase Invoice submitted.');
    }

    public function cancel(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $purchaseInvoice = $this->purchaseInvoiceService->cancel($purchaseInvoice);

        return $this->success(new PurchaseInvoiceResource($purchaseInvoice), 'Purchase Invoice cancelled.');
    }

    /** Same filters as index(), unpaginated — XLSX/CSV export. */
    public function export(IndexPurchaseInvoiceRequest $request): BinaryFileResponse
    {
        $format = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'csv'])]])['format'] ?? 'xlsx';
        $filters = $request->validated();
        unset($filters['per_page']);

        $rows = $this->purchaseInvoiceService->listAll($filters);

        return Excel::download(new PurchaseInvoiceExport($rows), "purchase-invoices.{$format}");
    }
}
