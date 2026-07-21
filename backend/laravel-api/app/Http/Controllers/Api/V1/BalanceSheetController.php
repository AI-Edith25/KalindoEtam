<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexBalanceSheetRequest;
use App\Http\Resources\BalanceSheetLineResource;
use App\Services\BalanceSheetService;
use Illuminate\Http\JsonResponse;

/** Read-only — no store/update/destroy. See docs/BALANCE_SHEET_DESIGN.md. */
class BalanceSheetController extends Controller
{
    use ApiResponse;

    public function __construct(protected BalanceSheetService $balanceSheetService) {}

    public function summary(IndexBalanceSheetRequest $request): JsonResponse
    {
        $result = $this->balanceSheetService->summarize($request->validated());

        return $this->success([
            'as_of_date' => $result['as_of_date'],
            'sections' => array_map(fn (array $section) => [
                'key' => $section['key'],
                'label' => $section['label'],
                'lines' => BalanceSheetLineResource::collection($section['lines']),
                'subtotal' => $section['subtotal'],
            ], $result['sections']),
            'total_assets' => $result['total_assets'],
            'total_liabilities' => $result['total_liabilities'],
            'share_capital' => $result['share_capital'],
            'retained_earnings' => $result['retained_earnings'],
            'current_year_profit' => $result['current_year_profit'],
            'total_equity' => $result['total_equity'],
            'total_liabilities_and_equity' => $result['total_liabilities_and_equity'],
            'is_balanced' => $result['is_balanced'],
        ]);
    }
}
