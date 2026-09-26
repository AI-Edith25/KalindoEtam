<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexReceiptEntryRequest;
use App\Http\Requests\StoreReceiptEntryRequest;
use App\Http\Requests\UpdateReceiptEntryRequest;
use App\Http\Resources\ReceiptEntryResource;
use App\Models\ReceiptEntry;
use App\Services\BankStatement\BankReconciliationService;
use App\Services\ReceiptEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ReceiptEntryController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ReceiptEntryService $receiptEntryService,
        protected BankReconciliationService $bankReconciliationService,
    ) {}

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
        return $this->success(new ReceiptEntryResource($receiptEntry->load(['customer', 'cashAccount', 'items.accountsReceivable.invoice', 'items.accountsReceivable.delivery'])));
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

        // Best-effort: a stale bank reconciliation summary is fixable with "Re-run
        // reconciliation" later, so this must never fail an already-successful submit.
        try {
            $this->bankReconciliationService->recomputeIfTracked($receiptEntry->cash_account_id, $receiptEntry->receipt_date->format('Y-m-d'));
        } catch (\Throwable $e) {
            Log::warning('Bank reconciliation recompute failed after Receipt Entry submit', ['receipt_entry_id' => $receiptEntry->id, 'error' => $e->getMessage()]);
        }

        return $this->success(new ReceiptEntryResource($receiptEntry), 'Receipt Entry submitted.');
    }
}
