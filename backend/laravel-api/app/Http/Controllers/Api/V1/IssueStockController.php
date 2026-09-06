<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexIssueStockRequest;
use App\Http\Requests\PreviewIssueStockCostRequest;
use App\Http\Requests\StoreIssueStockRequest;
use App\Http\Requests\UpdateIssueStockRequest;
use App\Http\Resources\IssueStockResource;
use App\Models\IssueStock;
use App\Services\IssueStockService;
use Illuminate\Http\JsonResponse;

class IssueStockController extends Controller
{
    use ApiResponse;

    public function __construct(protected IssueStockService $issueStockService) {}

    public function index(IndexIssueStockRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(IssueStockResource::collection(
            $this->issueStockService->list($filters, $perPage)
        ));
    }

    public function store(StoreIssueStockRequest $request): JsonResponse
    {
        $issueStock = $this->issueStockService->create($request->validated());

        return $this->success(new IssueStockResource($issueStock), 'Issue Stock created.', 201);
    }

    public function show(IssueStock $issueStock): JsonResponse
    {
        return $this->success(new IssueStockResource($issueStock->load(['warehouse', 'items'])));
    }

    public function update(UpdateIssueStockRequest $request, IssueStock $issueStock): JsonResponse
    {
        $issueStock = $this->issueStockService->update($issueStock, $request->validated());

        return $this->success(new IssueStockResource($issueStock), 'Issue Stock updated.');
    }

    public function destroy(IssueStock $issueStock): JsonResponse
    {
        $this->issueStockService->delete($issueStock);

        return $this->success(null, 'Issue Stock deleted.');
    }

    public function submit(IssueStock $issueStock): JsonResponse
    {
        $issueStock = $this->issueStockService->submit($issueStock);

        return $this->success(new IssueStockResource($issueStock), 'Issue Stock submitted.');
    }

    public function cancel(IssueStock $issueStock): JsonResponse
    {
        $issueStock = $this->issueStockService->cancel($issueStock);

        return $this->success(new IssueStockResource($issueStock), 'Issue Stock cancelled.');
    }

    /** Live FIFO-computed Unit Cost for the editor's read-only column, before Submit. */
    public function previewCost(PreviewIssueStockCostRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->success(
            $this->issueStockService->previewCost($data['item_id'], $data['warehouse_id'], (float) $data['qty'])
        );
    }
}
