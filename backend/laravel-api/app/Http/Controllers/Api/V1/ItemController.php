<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkSetSyncToMainWhRequest;
use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use App\Services\ItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    use ApiResponse;

    public function __construct(protected ItemService $itemService) {}

    /**
     * `warehouse_id`/`search`/`item_group_id`/`item_ids` are all optional — callers that omit them
     * get today's response unchanged. `item_ids` is Sales Order's own "re-fetch available_qty for
     * my already-selected lines" call when the header Warehouse changes — see
     * SalesOrderLineItemTable's warehouse-change effect.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->success(ItemResource::collection($this->itemService->list(
            perPage: (int) ($request->query('per_page') ?? 15),
            warehouseId: $request->query('warehouse_id'),
            search: $request->query('search'),
            itemGroupId: $request->query('item_group_id'),
            itemIds: $request->query('item_ids'),
        )));
    }

    public function bulkSyncToMainWh(BulkSetSyncToMainWhRequest $request): JsonResponse
    {
        $this->itemService->bulkSetSyncToMainWh($request->validated('item_ids'), $request->validated('value'));

        return $this->success(null, 'Updated.');
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = $this->itemService->create($request->validated());

        return $this->success(new ItemResource($item), 'Item created.', 201);
    }

    public function show(Item $item): JsonResponse
    {
        return $this->success(new ItemResource($item->load(['itemGroup', 'uom', 'purchaseTax', 'salesTax'])));
    }

    public function update(UpdateItemRequest $request, Item $item): JsonResponse
    {
        $item = $this->itemService->update($item, $request->validated());

        return $this->success(new ItemResource($item->load(['itemGroup', 'uom', 'purchaseTax', 'salesTax'])), 'Item updated.');
    }

    public function destroy(Item $item): JsonResponse
    {
        $this->itemService->delete($item);

        return $this->success(null, 'Item deleted.');
    }
}
