<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportItemPricesRequest;
use App\Http\Requests\StoreItemPriceRequest;
use App\Http\Requests\UpdateItemPriceRequest;
use App\Http\Resources\ItemPriceResource;
use App\Models\ItemPrice;
use App\Services\ItemPriceService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemPriceController extends Controller
{
    use ApiResponse;

    public function __construct(protected ItemPriceService $itemPriceService) {}

    public function index(): JsonResponse
    {
        return $this->success(ItemPriceResource::collection($this->itemPriceService->list()));
    }

    public function store(StoreItemPriceRequest $request): JsonResponse
    {
        $itemPrice = $this->itemPriceService->create($request->validated());

        return $this->success(new ItemPriceResource($itemPrice), 'Price override saved.', 201);
    }

    public function update(UpdateItemPriceRequest $request, ItemPrice $itemPrice): JsonResponse
    {
        $itemPrice = $this->itemPriceService->update($itemPrice, $request->validated());

        return $this->success(new ItemPriceResource($itemPrice), 'Price override updated.');
    }

    public function destroy(ItemPrice $itemPrice): JsonResponse
    {
        $this->itemPriceService->delete($itemPrice);

        return $this->success(null, 'Price override removed.');
    }

    public function export(): StreamedResponse
    {
        return $this->itemPriceService->export();
    }

    public function import(ImportItemPricesRequest $request): JsonResponse
    {
        $summary = $this->itemPriceService->import($request->file('file'));

        return $this->success($summary, 'Import finished.');
    }
}
