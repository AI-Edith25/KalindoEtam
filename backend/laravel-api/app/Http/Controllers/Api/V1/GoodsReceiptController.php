<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexGoodsReceiptRequest;
use App\Http\Requests\StoreGoodsReceiptRequest;
use App\Http\Requests\UpdateGoodsReceiptRequest;
use App\Http\Resources\GoodsReceiptResource;
use App\Models\GoodsReceipt;
use App\Services\GoodsReceiptService;
use Illuminate\Http\JsonResponse;

class GoodsReceiptController extends Controller
{
    use ApiResponse;

    public function __construct(protected GoodsReceiptService $goodsReceiptService) {}

    public function index(IndexGoodsReceiptRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(GoodsReceiptResource::collection(
            $this->goodsReceiptService->list($filters, $perPage)
        ));
    }

    public function store(StoreGoodsReceiptRequest $request): JsonResponse
    {
        $goodsReceipt = $this->goodsReceiptService->create($request->validated());

        return $this->success(new GoodsReceiptResource($goodsReceipt), 'Goods Receipt created.', 201);
    }

    public function show(GoodsReceipt $goodsReceipt): JsonResponse
    {
        return $this->success(new GoodsReceiptResource($goodsReceipt->load(['supplier', 'warehouse', 'purchaseOrder', 'items'])));
    }

    public function update(UpdateGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        $goodsReceipt = $this->goodsReceiptService->update($goodsReceipt, $request->validated());

        return $this->success(new GoodsReceiptResource($goodsReceipt), 'Goods Receipt updated.');
    }

    public function destroy(GoodsReceipt $goodsReceipt): JsonResponse
    {
        $this->goodsReceiptService->delete($goodsReceipt);

        return $this->success(null, 'Goods Receipt deleted.');
    }

    /**
     * No cancel() action here, deliberately — see GoodsReceipt::cancel().
     */
    public function submit(GoodsReceipt $goodsReceipt): JsonResponse
    {
        $goodsReceipt = $this->goodsReceiptService->submit($goodsReceipt);

        return $this->success(new GoodsReceiptResource($goodsReceipt), 'Goods Receipt submitted.');
    }
}
