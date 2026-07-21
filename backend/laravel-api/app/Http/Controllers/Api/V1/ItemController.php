<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use App\Services\ItemService;
use Illuminate\Http\JsonResponse;

class ItemController extends Controller
{
    use ApiResponse;

    public function __construct(protected ItemService $itemService) {}

    public function index(): JsonResponse
    {
        return $this->success(ItemResource::collection($this->itemService->list()));
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = $this->itemService->create($request->validated());

        return $this->success(new ItemResource($item), 'Item created.', 201);
    }

    public function show(Item $item): JsonResponse
    {
        return $this->success(new ItemResource($item->load(['itemGroup', 'uom'])));
    }

    public function update(UpdateItemRequest $request, Item $item): JsonResponse
    {
        $item = $this->itemService->update($item, $request->validated());

        return $this->success(new ItemResource($item->load(['itemGroup', 'uom'])), 'Item updated.');
    }

    public function destroy(Item $item): JsonResponse
    {
        $this->itemService->delete($item);

        return $this->success(null, 'Item deleted.');
    }
}
