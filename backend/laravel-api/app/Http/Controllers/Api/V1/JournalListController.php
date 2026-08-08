<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexJournalListRequest;
use App\Http\Resources\JournalListLineResource;
use App\Services\JournalListService;
use Illuminate\Http\JsonResponse;

/** Read-only — no store/update/destroy. Cash/bank journal lines grouped by transaction type, with a subtotal per group. */
class JournalListController extends Controller
{
    use ApiResponse;

    public function __construct(protected JournalListService $journalListService) {}

    public function index(IndexJournalListRequest $request): JsonResponse
    {
        $result = $this->journalListService->summarize($request->validated());

        return $this->success([
            'groups' => array_map(fn (array $group) => [
                'key' => $group['key'],
                'label' => $group['label'],
                'rows' => JournalListLineResource::collection($group['rows']),
                'subtotal' => $group['subtotal'],
            ], $result['groups']),
            'grand_total' => $result['grand_total'],
        ]);
    }
}
