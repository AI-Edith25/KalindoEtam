<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\DecideApprovalRequest;
use App\Http\Requests\IndexApprovalFlowRequest;
use App\Http\Requests\RejectApprovalRequest;
use App\Http\Resources\ApprovalFlowResource;
use App\Models\ApprovalFlow;
use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;

/**
 * The one controller every approvable document type shares — see
 * docs/APPROVAL_WORKFLOW_DESIGN.md §1. approve()/reject() serve Sales
 * Order, Purchase Order, and manual Journal Entry alike; permission is
 * checked inside ApprovalService (module-dependent, not a static route).
 */
class ApprovalController extends Controller
{
    use ApiResponse;

    public function __construct(protected ApprovalService $approvalService) {}

    public function index(IndexApprovalFlowRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->success(ApprovalFlowResource::collection(
            $this->approvalService->historyFor($data['approvable_type'], $data['approvable_id'])
        ));
    }

    public function approve(DecideApprovalRequest $request, ApprovalFlow $approvalFlow): JsonResponse
    {
        $approvalFlow = $this->approvalService->approve($approvalFlow, $request->validated('remarks'));

        return $this->success(new ApprovalFlowResource($approvalFlow->load('approver')), 'Approved.');
    }

    public function reject(RejectApprovalRequest $request, ApprovalFlow $approvalFlow): JsonResponse
    {
        $approvalFlow = $this->approvalService->reject($approvalFlow, $request->validated('remarks'));

        return $this->success(new ApprovalFlowResource($approvalFlow->load('approver')), 'Rejected.');
    }
}
