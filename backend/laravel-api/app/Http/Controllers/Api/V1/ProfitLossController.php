<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexProfitLossRequest;
use App\Http\Resources\ProfitLossLineResource;
use App\Services\ProfitLossService;
use Illuminate\Http\JsonResponse;

/** Read-only — no store/update/destroy. See docs/PROFIT_LOSS_DESIGN.md. */
class ProfitLossController extends Controller
{
    use ApiResponse;

    public function __construct(protected ProfitLossService $profitLossService) {}

    public function summary(IndexProfitLossRequest $request): JsonResponse
    {
        $result = $this->profitLossService->summarize($request->validated());

        return $this->success([
            'sections' => array_map(fn (array $section) => [
                'key' => $section['key'],
                'label' => $section['label'],
                'lines' => ProfitLossLineResource::collection($section['lines']),
                'subtotal' => $section['subtotal'],
            ], $result['sections']),
            'gross_profit' => $result['gross_profit'],
            'operating_income' => $result['operating_income'],
            'net_profit_before_tax' => $result['net_profit_before_tax'],
            'tax' => $result['tax'],
            'net_profit' => $result['net_profit'],
        ]);
    }
}
