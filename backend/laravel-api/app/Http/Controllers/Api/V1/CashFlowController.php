<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexCashFlowRequest;
use App\Http\Resources\CashFlowLineResource;
use App\Services\CashFlowService;
use Illuminate\Http\JsonResponse;

/** Read-only — no store/update/destroy. See docs/CASH_FLOW_DESIGN.md. */
class CashFlowController extends Controller
{
    use ApiResponse;

    public function __construct(protected CashFlowService $cashFlowService) {}

    public function summary(IndexCashFlowRequest $request): JsonResponse
    {
        $result = $this->cashFlowService->summarize($request->validated());

        $activitySection = fn (array $section) => [
            'key' => $section['key'],
            'label' => $section['label'],
            'lines' => CashFlowLineResource::collection($section['lines']),
            'net_cash' => $section['net_cash'],
        ];

        return $this->success([
            'net_profit' => $result['net_profit'],
            'operating' => $activitySection($result['operating']),
            'investing' => $activitySection($result['investing']),
            'financing' => $activitySection($result['financing']),
            'net_cash_movement' => $result['net_cash_movement'],
            'opening_cash' => $result['opening_cash'],
            'closing_cash' => $result['closing_cash'],
            'is_balanced' => $result['is_balanced'],
        ]);
    }
}
