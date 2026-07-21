<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexCreditNoteRequest;
use App\Http\Requests\StoreCreditNoteRequest;
use App\Http\Requests\UpdateCreditNoteRequest;
use App\Http\Resources\CreditNoteResource;
use App\Models\CreditNote;
use App\Services\CreditNoteService;
use Illuminate\Http\JsonResponse;

class CreditNoteController extends Controller
{
    use ApiResponse;

    protected const EAGER = ['invoice.delivery', 'customer', 'items.invoiceItem', 'items.item'];

    public function __construct(protected CreditNoteService $creditNoteService) {}

    public function index(IndexCreditNoteRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(CreditNoteResource::collection(
            $this->creditNoteService->list($filters, $perPage)
        ));
    }

    public function store(StoreCreditNoteRequest $request): JsonResponse
    {
        $creditNote = $this->creditNoteService->create($request->validated());

        return $this->success(new CreditNoteResource($creditNote), 'Credit Note created.', 201);
    }

    public function show(CreditNote $creditNote): JsonResponse
    {
        return $this->success(new CreditNoteResource($creditNote->load(self::EAGER)));
    }

    public function update(UpdateCreditNoteRequest $request, CreditNote $creditNote): JsonResponse
    {
        $creditNote = $this->creditNoteService->update($creditNote, $request->validated());

        return $this->success(new CreditNoteResource($creditNote), 'Credit Note updated.');
    }

    public function destroy(CreditNote $creditNote): JsonResponse
    {
        $this->creditNoteService->delete($creditNote);

        return $this->success(null, 'Credit Note deleted.');
    }

    public function submit(CreditNote $creditNote): JsonResponse
    {
        $creditNote = $this->creditNoteService->submit($creditNote);

        return $this->success(new CreditNoteResource($creditNote), 'Credit Note submitted.');
    }

    public function reverse(CreditNote $creditNote): JsonResponse
    {
        $creditNote = $this->creditNoteService->reverse($creditNote);

        return $this->success(new CreditNoteResource($creditNote), 'Credit Note reversed.');
    }
}
