<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexDebitNoteRequest;
use App\Http\Requests\StoreDebitNoteRequest;
use App\Http\Requests\UpdateDebitNoteRequest;
use App\Http\Resources\DebitNoteResource;
use App\Models\DebitNote;
use App\Services\DebitNoteService;
use Illuminate\Http\JsonResponse;

class DebitNoteController extends Controller
{
    use ApiResponse;

    protected const EAGER = ['invoice', 'customer', 'items.invoiceItem', 'items.item'];

    public function __construct(protected DebitNoteService $debitNoteService) {}

    public function index(IndexDebitNoteRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(DebitNoteResource::collection(
            $this->debitNoteService->list($filters, $perPage)
        ));
    }

    public function store(StoreDebitNoteRequest $request): JsonResponse
    {
        $debitNote = $this->debitNoteService->create($request->validated());

        return $this->success(new DebitNoteResource($debitNote), 'Debit Note created.', 201);
    }

    public function show(DebitNote $debitNote): JsonResponse
    {
        return $this->success(new DebitNoteResource($debitNote->load(self::EAGER)));
    }

    public function update(UpdateDebitNoteRequest $request, DebitNote $debitNote): JsonResponse
    {
        $debitNote = $this->debitNoteService->update($debitNote, $request->validated());

        return $this->success(new DebitNoteResource($debitNote), 'Debit Note updated.');
    }

    public function destroy(DebitNote $debitNote): JsonResponse
    {
        $this->debitNoteService->delete($debitNote);

        return $this->success(null, 'Debit Note deleted.');
    }

    public function submit(DebitNote $debitNote): JsonResponse
    {
        $debitNote = $this->debitNoteService->submit($debitNote);

        return $this->success(new DebitNoteResource($debitNote), 'Debit Note submitted.');
    }

    public function reverse(DebitNote $debitNote): JsonResponse
    {
        $debitNote = $this->debitNoteService->reverse($debitNote);

        return $this->success(new DebitNoteResource($debitNote), 'Debit Note reversed.');
    }
}
