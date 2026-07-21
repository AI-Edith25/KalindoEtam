<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexInvoiceRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;

class InvoiceController extends Controller
{
    use ApiResponse;

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
            'customer', 'salesOrder', 'delivery', 'items', 'accountsReceivable.receiptEntryItems.receiptEntry', 'creditNotes', 'debitNotes',
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
}
