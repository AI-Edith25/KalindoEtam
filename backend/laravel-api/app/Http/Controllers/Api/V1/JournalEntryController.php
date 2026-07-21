<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexJournalEntryRequest;
use App\Http\Requests\StoreJournalEntryRequest;
use App\Http\Requests\UpdateJournalEntryRequest;
use App\Http\Resources\ApprovalFlowResource;
use App\Http\Resources\JournalEntryResource;
use App\Models\JournalEntry;
use App\Services\ApprovalService;
use App\Services\JournalEntryService;
use Illuminate\Http\JsonResponse;

class JournalEntryController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected ApprovalService $approvalService,
    ) {}

    public function index(IndexJournalEntryRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(JournalEntryResource::collection(
            $this->journalEntryService->list($filters, $perPage)
        ));
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $journalEntry = $this->journalEntryService->create($request->validated());

        return $this->success(new JournalEntryResource($journalEntry), 'Journal Entry created.', 201);
    }

    public function show(JournalEntry $journalEntry): JsonResponse
    {
        return $this->success(new JournalEntryResource($journalEntry->load([
            'lines.chartOfAccount', 'lines.branch', 'referenceDocument', 'reverses', 'reversedBy', 'creator', 'approvalFlows.approver',
        ])));
    }

    public function update(UpdateJournalEntryRequest $request, JournalEntry $journalEntry): JsonResponse
    {
        $journalEntry = $this->journalEntryService->update($journalEntry, $request->validated());

        return $this->success(new JournalEntryResource($journalEntry), 'Journal Entry updated.');
    }

    public function destroy(JournalEntry $journalEntry): JsonResponse
    {
        $this->journalEntryService->delete($journalEntry);

        return $this->success(null, 'Journal Entry deleted.');
    }

    public function post(JournalEntry $journalEntry): JsonResponse
    {
        $journalEntry = $this->journalEntryService->post($journalEntry);

        return $this->success(new JournalEntryResource($journalEntry), 'Journal Entry posted.');
    }

    public function reverse(JournalEntry $journalEntry): JsonResponse
    {
        $reversal = $this->journalEntryService->reverse($journalEntry);

        return $this->success(new JournalEntryResource($reversal), 'Journal Entry reversed.');
    }

    public function requestApproval(JournalEntry $journalEntry): JsonResponse
    {
        $flow = $this->approvalService->requestApproval($journalEntry);

        return $this->success(new ApprovalFlowResource($flow), 'Approval requested.', 201);
    }
}
