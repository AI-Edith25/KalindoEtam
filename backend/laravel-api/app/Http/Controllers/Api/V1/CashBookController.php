<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexCashBookRequest;
use App\Http\Resources\CashBookRowResource;
use App\Services\CashBookService;
use Illuminate\Http\JsonResponse;

/** Read-only — screen data for Journal List's Cash Book Transaction tab. Export lives on JournalListController (journal-line level, not this document-level shape). */
class CashBookController extends Controller
{
    use ApiResponse;

    public function __construct(protected CashBookService $cashBookService) {}

    public function index(IndexCashBookRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(CashBookRowResource::collection(
            $this->cashBookService->list($filters, $perPage)
        ));
    }
}
