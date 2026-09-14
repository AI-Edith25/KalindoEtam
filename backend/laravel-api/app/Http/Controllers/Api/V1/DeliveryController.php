<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessException;
use App\Exports\DeliveryDetailExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Concerns\ExportsSalesList;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexDeliveryRequest;
use App\Http\Requests\StoreDeliveryRequest;
use App\Http\Requests\UpdateDeliveryRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Services\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DeliveryController extends Controller
{
    use ApiResponse, ExportsSalesList;

    public function __construct(protected DeliveryService $deliveryService) {}

    public function index(IndexDeliveryRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(DeliveryResource::collection(
            $this->deliveryService->list($filters, $perPage)
        ));
    }

    public function store(StoreDeliveryRequest $request): JsonResponse
    {
        $delivery = $this->deliveryService->create($request->validated());

        return $this->success(new DeliveryResource($delivery), 'Delivery created.', 201);
    }

    public function show(Delivery $delivery): JsonResponse
    {
        return $this->success(new DeliveryResource($delivery->load(['customer', 'warehouse', 'salesOrder.salesPerson', 'salesOrder.tax', 'items', 'invoices', 'termsOfPayment'])));
    }

    public function update(UpdateDeliveryRequest $request, Delivery $delivery): JsonResponse
    {
        $delivery = $this->deliveryService->update($delivery, $request->validated());

        return $this->success(new DeliveryResource($delivery), 'Delivery updated.');
    }

    public function destroy(Delivery $delivery): JsonResponse
    {
        $this->deliveryService->delete($delivery);

        return $this->success(null, 'Delivery deleted.');
    }

    /**
     * No cancel() action here, deliberately — see Delivery::cancel().
     */
    public function complete(Delivery $delivery): JsonResponse
    {
        $delivery = $this->deliveryService->complete($delivery);

        return $this->success(new DeliveryResource($delivery), 'Delivery completed.');
    }

    /**
     * Bulk export. mode=summary keeps the legacy-report-styled layout (same
     * contract as SalesOrderController::export()). mode=detail (the
     * default) is DeliveryDetailExport — one physical row per delivery
     * line, a fixed 25-column contract, streamed straight from a Builder
     * (see DeliveryService::detailExportQuery()) rather than a pre-fetched
     * Collection, so export size no longer depends on per_page/memory.
     */
    public function export(IndexDeliveryRequest $request): BinaryFileResponse
    {
        $extra = $request->validate([
            'format' => ['sometimes', Rule::in(['xlsx', 'csv'])],
            'mode' => ['sometimes', Rule::in(['detail', 'summary'])],
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['uuid'],
        ]);

        $filters = $request->validated();
        unset($filters['per_page']);
        $format = $extra['format'] ?? 'xlsx';
        $ids = $extra['ids'] ?? null;

        if (($extra['mode'] ?? 'detail') === 'summary') {
            return $this->exportSalesSummary(
                $this->deliveryService->summaryExportRows($filters, $ids),
                'DeliveryOrder',
                $filters['date_from'] ?? null,
                $filters['date_to'] ?? null,
                $format,
            );
        }

        $query = $this->deliveryService->detailExportQuery($filters, $ids);

        if (! $query->exists()) {
            throw new BusinessException('Tidak ada data untuk diekspor.');
        }

        $filename = sprintf(
            'DeliveryOrderListing_Detail_%s_%s.%s',
            $filters['date_from'] ?? now()->toDateString(),
            $filters['date_to'] ?? now()->toDateString(),
            $format,
        );

        return Excel::download(
            new DeliveryDetailExport($query, $this->deliveryService->detailExportMeta($filters, $ids), $format),
            $filename,
        );
    }
}
