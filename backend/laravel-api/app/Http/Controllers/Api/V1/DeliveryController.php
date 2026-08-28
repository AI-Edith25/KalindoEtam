<?php

namespace App\Http\Controllers\Api\V1;

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
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DeliveryController extends Controller
{
    use ApiResponse, ExportsSalesList;

    /** Ordered [columnKey => label] — mirrors DeliveryListPage.tsx's own table columns. */
    protected const COLUMNS = [
        'delivery_date' => 'Date',
        'document_number' => 'Document',
        'reference' => 'Reference',
        'customer_name' => 'Customer Name',
        'amount' => 'Amount',
        'status' => 'Status',
    ];

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

    /** Bulk export — same contract as SalesOrderController::export(). */
    public function export(IndexDeliveryRequest $request): BinaryFileResponse
    {
        $extra = $request->validate([
            'format' => ['sometimes', Rule::in(['xlsx', 'csv'])],
            'mode' => ['sometimes', Rule::in(['detail', 'summary'])],
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['uuid'],
            'columns' => ['sometimes', 'array'],
            'columns.*' => [Rule::in(array_keys(self::COLUMNS))],
        ]);

        $filters = $request->validated();
        unset($filters['per_page']);

        if (($extra['mode'] ?? 'detail') === 'summary') {
            return $this->exportSalesSummary(
                $this->deliveryService->summaryExportRows($filters, $extra['ids'] ?? null),
                'DeliveryOrder',
                $filters['date_from'] ?? null,
                $filters['date_to'] ?? null,
                $extra['format'] ?? 'xlsx',
            );
        }

        $rows = $this->deliveryService->listAll($filters, $extra['ids'] ?? null);

        return $this->exportSalesList(
            $rows,
            self::COLUMNS,
            $extra['columns'] ?? null,
            fn (Delivery $row, string $key) => match ($key) {
                'delivery_date' => $row->delivery_date?->format('Y-m-d'),
                'document_number' => $row->document_number,
                'reference' => $row->salesOrder?->document_number,
                'customer_name' => $row->customer?->customer_name,
                'amount' => (float) $row->items->sum('amount'),
                'status' => ucfirst($row->status?->value ?? ''),
                default => null,
            },
            'deliveries',
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $extra['format'] ?? 'xlsx',
        );
    }
}
