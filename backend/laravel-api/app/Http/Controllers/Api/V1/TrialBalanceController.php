<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexTrialBalanceRequest;
use App\Http\Resources\TrialBalanceRowResource;
use App\Services\TrialBalanceService;
use Illuminate\Http\JsonResponse;

/** Read-only — no store/update/destroy. See docs/TRIAL_BALANCE_DESIGN.md. */
class TrialBalanceController extends Controller
{
    use ApiResponse;

    public function __construct(protected TrialBalanceService $trialBalanceService) {}

    public function summary(IndexTrialBalanceRequest $request): JsonResponse
    {
        $result = $this->trialBalanceService->summarize($request->validated());

        return $this->success([
            'rows' => TrialBalanceRowResource::collection($result['rows']),
            'total_debit' => $result['total_debit'],
            'total_credit' => $result['total_credit'],
            'is_balanced' => $result['is_balanced'],
        ]);
    }
}
