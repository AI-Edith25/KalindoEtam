<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\DecideInvoiceChangeRequestRequest;
use App\Http\Requests\IndexInvoiceChangeRequestRequest;
use App\Http\Requests\RejectInvoiceChangeRequestRequest;
use App\Http\Requests\StoreInvoiceChangeRequestRequest;
use App\Http\Requests\UpdateInvoiceChangeRequestNominalRequest;
use App\Http\Resources\InvoiceChangeRequestResource;
use App\Http\Resources\InvoiceResource;
use App\Models\InvoiceChangeRequest;
use App\Services\InvoiceChangeRequestService;
use Illuminate\Http\JsonResponse;

class InvoiceChangeRequestController extends Controller
{
    use ApiResponse;

    public function __construct(protected InvoiceChangeRequestService $invoiceChangeRequestService) {}

    public function index(IndexInvoiceChangeRequestRequest $request): JsonResponse
    {
        return $this->success(InvoiceChangeRequestResource::collection(
            $this->invoiceChangeRequestService->historyFor($request->validated('invoice_id'))
        ));
    }

    public function store(StoreInvoiceChangeRequestRequest $request): JsonResponse
    {
        $changeRequest = $this->invoiceChangeRequestService->create($request->validated());

        return $this->success(new InvoiceChangeRequestResource($changeRequest), 'Change request submitted.', 201);
    }

    public function approve(DecideInvoiceChangeRequestRequest $request, InvoiceChangeRequest $invoiceChangeRequest): JsonResponse
    {
        $changeRequest = $this->invoiceChangeRequestService->approve($invoiceChangeRequest, $request->validated('remarks'));

        return $this->success(new InvoiceChangeRequestResource($changeRequest), 'Change request approved.');
    }

    public function reject(RejectInvoiceChangeRequestRequest $request, InvoiceChangeRequest $invoiceChangeRequest): JsonResponse
    {
        $changeRequest = $this->invoiceChangeRequestService->reject($invoiceChangeRequest, $request->validated('remarks'));

        return $this->success(new InvoiceChangeRequestResource($changeRequest), 'Change request rejected.');
    }

    public function applyNominal(UpdateInvoiceChangeRequestNominalRequest $request, InvoiceChangeRequest $invoiceChangeRequest): JsonResponse
    {
        $invoice = $this->invoiceChangeRequestService->applyNominal($invoiceChangeRequest, $request->validated('items'));

        return $this->success(new InvoiceResource($invoice), 'Invoice nominal updated.');
    }
}
