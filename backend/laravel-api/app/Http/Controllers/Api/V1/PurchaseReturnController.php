<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\PurchaseReturnExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPurchaseReturnRequest;
use App\Http\Requests\StorePurchaseReturnRequest;
use App\Http\Requests\UpdatePurchaseReturnRequest;
use App\Http\Resources\PurchaseReturnResource;
use App\Models\PurchaseReturn;
use App\Services\PurchaseReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PurchaseReturnController extends Controller
{
    use ApiResponse;

    protected const EAGER = ['purchaseInvoice.goodsReceipt', 'supplier', 'items.purchaseInvoiceItem', 'items.item'];

    public function __construct(protected PurchaseReturnService $purchaseReturnService) {}

    public function index(IndexPurchaseReturnRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(PurchaseReturnResource::collection(
            $this->purchaseReturnService->list($filters, $perPage)
        ));
    }

    public function store(StorePurchaseReturnRequest $request): JsonResponse
    {
        $purchaseReturn = $this->purchaseReturnService->create($request->validated());

        return $this->success(new PurchaseReturnResource($purchaseReturn), 'Purchase Return created.', 201);
    }

    public function show(PurchaseReturn $purchaseReturn): JsonResponse
    {
        return $this->success(new PurchaseReturnResource($purchaseReturn->load(self::EAGER)));
    }

    public function update(UpdatePurchaseReturnRequest $request, PurchaseReturn $purchaseReturn): JsonResponse
    {
        $purchaseReturn = $this->purchaseReturnService->update($purchaseReturn, $request->validated());

        return $this->success(new PurchaseReturnResource($purchaseReturn), 'Purchase Return updated.');
    }

    public function destroy(PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->purchaseReturnService->delete($purchaseReturn);

        return $this->success(null, 'Purchase Return deleted.');
    }

    public function submit(PurchaseReturn $purchaseReturn): JsonResponse
    {
        $purchaseReturn = $this->purchaseReturnService->submit($purchaseReturn);

        return $this->success(new PurchaseReturnResource($purchaseReturn), 'Purchase Return submitted.');
    }

    public function reverse(PurchaseReturn $purchaseReturn): JsonResponse
    {
        $purchaseReturn = $this->purchaseReturnService->reverse($purchaseReturn);

        return $this->success(new PurchaseReturnResource($purchaseReturn), 'Purchase Return reversed.');
    }

    /** Same filters as index(), unpaginated — XLSX/CSV export. */
    public function export(IndexPurchaseReturnRequest $request): BinaryFileResponse
    {
        $format = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'csv'])]])['format'] ?? 'xlsx';
        $filters = $request->validated();
        unset($filters['per_page']);

        $rows = $this->purchaseReturnService->listAll($filters);

        return Excel::download(new PurchaseReturnExport($rows), "purchase-returns.{$format}");
    }
}
