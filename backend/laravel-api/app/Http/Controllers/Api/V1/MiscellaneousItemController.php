<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMiscellaneousItemRequest;
use App\Http\Requests\UpdateMiscellaneousItemRequest;
use App\Http\Resources\MiscellaneousItemResource;
use App\Models\MiscellaneousItem;
use App\Services\MiscellaneousItemService;
use Illuminate\Http\JsonResponse;

class MiscellaneousItemController extends Controller
{
    use ApiResponse;

    public function __construct(protected MiscellaneousItemService $miscellaneousItemService) {}

    public function index(): JsonResponse
    {
        return $this->success(MiscellaneousItemResource::collection($this->miscellaneousItemService->list()));
    }

    public function store(StoreMiscellaneousItemRequest $request): JsonResponse
    {
        $miscellaneousItem = $this->miscellaneousItemService->create($request->validated());

        return $this->success(new MiscellaneousItemResource($miscellaneousItem), 'Miscellaneous item created.', 201);
    }

    public function show(MiscellaneousItem $miscellaneousItem): JsonResponse
    {
        return $this->success(new MiscellaneousItemResource($miscellaneousItem));
    }

    public function update(UpdateMiscellaneousItemRequest $request, MiscellaneousItem $miscellaneousItem): JsonResponse
    {
        $miscellaneousItem = $this->miscellaneousItemService->update($miscellaneousItem, $request->validated());

        return $this->success(new MiscellaneousItemResource($miscellaneousItem), 'Miscellaneous item updated.');
    }

    public function destroy(MiscellaneousItem $miscellaneousItem): JsonResponse
    {
        $this->miscellaneousItemService->delete($miscellaneousItem);

        return $this->success(null, 'Miscellaneous item deleted.');
    }
}
