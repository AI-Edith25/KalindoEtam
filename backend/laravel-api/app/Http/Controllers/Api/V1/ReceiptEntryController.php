<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexReceiptEntryRequest;
use App\Http\Requests\StoreReceiptEntryRequest;
use App\Http\Requests\UpdateReceiptEntryRequest;
use App\Http\Resources\ReceiptEntryResource;
use App\Models\ReceiptEntry;
use App\Services\ReceiptEntryService;
use Illuminate\Http\JsonResponse;

class ReceiptEntryController extends Controller
{
    use ApiResponse;

    public function __construct(protected ReceiptEntryService $receiptEntryService) {}

    public function index(IndexReceiptEntryRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(ReceiptEntryResource::collection($this->receiptEntryService->list($filters, $perPage)));
    }

    public function store(StoreReceiptEntryRequest $request): JsonResponse
    {
        $receiptEntry = $this->receiptEntryService->create($request->validated());

        return $this->success(new ReceiptEntryResource($receiptEntry), 'Receipt Entry created.', 201);
    }

    public function show(ReceiptEntry $receiptEntry): JsonResponse
    {
        return $this->success(new ReceiptEntryResource($receiptEntry->load(['customer', 'items.accountsReceivable.invoice', 'items.accountsReceivable.delivery'])));
    }

    public function update(UpdateReceiptEntryRequest $request, ReceiptEntry $receiptEntry): JsonResponse
    {
        $receiptEntry = $this->receiptEntryService->update($receiptEntry, $request->validated());

        return $this->success(new ReceiptEntryResource($receiptEntry), 'Receipt Entry updated.');
    }

    public function destroy(ReceiptEntry $receiptEntry): JsonResponse
    {
        $this->receiptEntryService->delete($receiptEntry);

        return $this->success(null, 'Receipt Entry deleted.');
    }

    /**
     * No cancel() action here, deliberately — see ReceiptEntry::cancel().
     */
    public function submit(ReceiptEntry $receiptEntry): JsonResponse
    {
        $receiptEntry = $this->receiptEntryService->submit($receiptEntry);

        return $this->success(new ReceiptEntryResource($receiptEntry), 'Receipt Entry submitted.');
    }
}
