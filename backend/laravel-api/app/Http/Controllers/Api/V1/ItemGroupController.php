<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreItemGroupRequest;
use App\Http\Requests\UpdateItemGroupRequest;
use App\Http\Resources\ItemGroupResource;
use App\Models\ItemGroup;
use App\Services\ItemGroupService;
use Illuminate\Http\JsonResponse;

class ItemGroupController extends Controller
{
    use ApiResponse;

    public function __construct(protected ItemGroupService $itemGroupService) {}

    public function index(): JsonResponse
    {
        return $this->success(ItemGroupResource::collection($this->itemGroupService->list()));
    }

    public function store(StoreItemGroupRequest $request): JsonResponse
    {
        $itemGroup = $this->itemGroupService->create($request->validated());

        return $this->success(new ItemGroupResource($itemGroup), 'Item group created.', 201);
    }

    public function show(ItemGroup $itemGroup): JsonResponse
    {
        return $this->success(new ItemGroupResource($itemGroup));
    }

    public function update(UpdateItemGroupRequest $request, ItemGroup $itemGroup): JsonResponse
    {
        $itemGroup = $this->itemGroupService->update($itemGroup, $request->validated());

        return $this->success(new ItemGroupResource($itemGroup), 'Item group updated.');
    }

    public function destroy(ItemGroup $itemGroup): JsonResponse
    {
        $this->itemGroupService->delete($itemGroup);

        return $this->success(null, 'Item group deleted.');
    }
}
