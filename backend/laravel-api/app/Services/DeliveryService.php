<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Exceptions\BusinessException;
use App\Exports\Concerns\BuildsSalesSummaryReport;
use App\Models\Delivery;
use App\Models\SalesOrderItem;
use App\Repositories\CompanyRepository;
use App\Repositories\DeliveryItemRepository;
use App\Repositories\DeliveryRepository;
use App\Repositories\SalesOrderItemRepository;
use App\Repositories\SalesOrderRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DeliveryService
{
    use BuildsSalesSummaryReport;

    public function __construct(
        protected DeliveryRepository $deliveryRepository,
        protected DeliveryItemRepository $deliveryItemRepository,
        protected SalesOrderRepository $salesOrderRepository,
        protected SalesOrderItemRepository $salesOrderItemRepository,
        protected StockLedgerService $stockLedgerService,
        protected AuditLogService $auditLogService,
        protected TaxService $taxService,
        protected CompanyRepository $companyRepository,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->deliveryRepository->search($filters, $perPage);
    }

    /** Unpaginated, for bulk export/print — same filters as list(), plus an optional $ids override. */
    public function listAll(array $filters = [], ?array $ids = null): Collection
    {
        return $this->deliveryRepository->searchAll($filters, $ids);
    }

    /**
     * The "Summary" export variant — see BuildsSalesSummaryReport. Tax is
     * grouped per line item (each item's own tax, already eager-loaded via
     * items.tax) — a Delivery has no single header-level tax the way a
     * Sales Order does.
     *
     * @return array{rows: array, meta: array}
     */
    public function summaryExportRows(array $filters, ?array $ids = null): array
    {
        $deliveries = $this->deliveryRepository->searchAll($filters, $ids);

        $bodyRows = $deliveries->map(function (Delivery $delivery) {
            $excl = (float) $delivery->items->sum('amount');
            $tax = round((float) $delivery->items->sum('tax_amount'), 2);

            return [
                $this->summaryExcelDate($delivery->delivery_date),
                $delivery->document_number,
                $delivery->customer?->customer_code,
                $delivery->customer?->customer_name,
                $excl,
                0.0,
                $tax,
                round($excl + $tax, 2),
                $delivery->salesOrder?->document_number,
            ];
        })->all();

        $sumExcl = round($deliveries->sum(fn (Delivery $d) => (float) $d->items->sum('amount')), 2);
        $sumTax = round($deliveries->sum(fn (Delivery $d) => (float) $d->items->sum('tax_amount')), 2);

        $bodyRows[] = [null, null, null, 'Total By Header', $sumExcl, 0.0, $sumTax, round($sumExcl + $sumTax, 2), null];

        $taxGroups = $this->groupTaxSummary($deliveries, fn (Delivery $d) => $d->items->map(fn ($item) => [
            $item->tax?->code, (float) ($item->tax?->rate ?? 0), (float) $item->amount, (float) $item->tax_amount,
        ])->all());

        return $this->buildSalesSummaryReport(
            title: 'DELIVERY ORDER LISTING - SUMMARY',
            periodLabel: $this->summaryPeriodLabel($filters, $deliveries, 'delivery_date'),
            companyName: $this->companyRepository->defaultOrById(null)?->name ?? 'PT. KALINDO ETAM',
            headingRow: ['Date', 'Document', 'Customer', 'Customer Name', 'Excl.Tax', 'Disc', 'Tax', 'Incl.Tax', 'Reference'],
            bodyRows: $bodyRows,
            taxGroups: $taxGroups,
            printedBy: Auth::user()?->name ?? 'System',
            lastColumn: 'I',
            numberFormatColumns: ['E', 'F', 'G', 'H'],
        );
    }

    public function create(array $data): Delivery
    {
        return DB::transaction(function () use ($data) {
            $salesOrder = $this->salesOrderRepository->findOrFail($data['sales_order_id']);

            if ($salesOrder->status !== SalesOrderStatus::APPROVED) {
                throw new BusinessException('Sales Order must be approved before a Delivery can be created against it.');
            }

            $delivery = $this->deliveryRepository->create([
                'sales_order_id' => $salesOrder->id,
                'customer_id' => $salesOrder->customer_id,
                'warehouse_id' => $data['warehouse_id'],
                'delivery_date' => $data['delivery_date'],
                'due_date' => $data['due_date'],
                'terms_of_payment_id' => $data['terms_of_payment_id'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'fleet' => $data['fleet'] ?? null,
                'driver' => $data['driver'] ?? null,
            ]);

            foreach ($data['items'] as $line) {
                $this->addLine($delivery, $salesOrder->id, $line['sales_order_item_id'], $line['qty']);
            }

            $delivery = $delivery->fresh(['customer', 'warehouse', 'salesOrder', 'items', 'termsOfPayment']);
            $this->auditLogService->record('created', 'delivery', "Created Delivery \"{$delivery->document_number}\".");

            return $delivery;
        });
    }

    public function update(Delivery $delivery, array $data): Delivery
    {
        return DB::transaction(function () use ($delivery, $data) {
            $this->assertDraft($delivery, 'updated');

            $headerData = collect($data)->except('items')->all();

            if (isset($data['items'])) {
                $delivery->items()->delete();

                foreach ($data['items'] as $line) {
                    $this->addLine($delivery, $delivery->sales_order_id, $line['sales_order_item_id'], $line['qty']);
                }
            }

            $this->deliveryRepository->update($delivery, $headerData);

            $delivery = $delivery->fresh(['customer', 'warehouse', 'salesOrder', 'items', 'termsOfPayment']);
            $this->auditLogService->record('updated', 'delivery', "Updated Delivery \"{$delivery->document_number}\".");

            return $delivery;
        });
    }

    public function delete(Delivery $delivery): void
    {
        DB::transaction(function () use ($delivery) {
            $this->assertDraft($delivery, 'deleted');
            $documentNumber = $delivery->document_number;
            $this->deliveryRepository->delete($delivery);
            $this->auditLogService->record('deleted', 'delivery', "Deleted Delivery \"{$documentNumber}\".");
        });
    }

    /**
     * The workflow: validate SO, validate outstanding + physical stock,
     * move stock out (StockLedgerService only), advance the SO's
     * delivered_qty, then flip status via Documentable — Pending -> Complete
     * is the one point stock actually moves. Accounts Receivable is no
     * longer created here — it is created by InvoiceService::submit() once
     * an Invoice exists for this Delivery.
     */
    public function complete(Delivery $delivery): Delivery
    {
        return DB::transaction(function () use ($delivery) {
            $delivery->load(['items.salesOrderItem', 'salesOrder']);

            if ($delivery->salesOrder->status !== SalesOrderStatus::APPROVED) {
                throw new BusinessException('Sales Order is no longer approved; cannot deliver against it.');
            }

            foreach ($delivery->items as $line) {
                $this->assertWithinOutstanding($line->salesOrderItem, $line->qty);
                $this->assertSufficientStock($delivery->warehouse_id, $line->item_id, $line->qty);
            }

            foreach ($delivery->items as $line) {
                $this->stockLedgerService->record(
                    itemId: $line->item_id,
                    warehouseId: $delivery->warehouse_id,
                    transactionType: StockTransactionType::OUT,
                    voucherType: StockVoucherType::DELIVERY,
                    voucherId: $delivery->id,
                    qtyChange: -$line->qty,
                    postingDatetime: now(),
                    referenceNo: $delivery->document_number,
                    remarks: "Delivery {$delivery->document_number}",
                );

                $this->salesOrderItemRepository->incrementDeliveredQty($line->salesOrderItem, $line->qty);
            }

            $delivery->submit();

            $delivery = $delivery->fresh(['customer', 'warehouse', 'salesOrder', 'items', 'termsOfPayment']);
            $this->auditLogService->record('completed', 'delivery', "Completed Delivery \"{$delivery->document_number}\".");

            return $delivery;
        });
    }

    protected function addLine(Delivery $delivery, string $salesOrderId, string $salesOrderItemId, int $qty): void
    {
        $soItem = $this->resolveSalesOrderItem($salesOrderId, $salesOrderItemId);
        $this->assertWithinOutstanding($soItem, $qty);

        $item = $soItem->item;
        $lineAmount = $qty * $soItem->rate;
        // tax_id carries forward as-is; tax_amount is recomputed against this delivery
        // line's own (possibly partial) qty, not simply copied — same "rate inherited,
        // amount recomputed against the real quantity" rule the old header-level
        // inheritance used, now applied per line.
        $taxAmount = $this->taxService->calculate($lineAmount, $soItem->tax)['tax_amount'];

        $this->deliveryItemRepository->create([
            'delivery_id' => $delivery->id,
            'sales_order_item_id' => $soItem->id,
            'item_id' => $item->id,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
            'uom' => $item->uom->name,
            'rate' => $soItem->rate,
            'qty' => $qty,
            'amount' => $lineAmount,
            'tax_id' => $soItem->tax_id,
            'tax_amount' => $taxAmount,
        ]);
    }

    protected function resolveSalesOrderItem(string $salesOrderId, string $salesOrderItemId): SalesOrderItem
    {
        $soItem = $this->salesOrderItemRepository->findOrFail($salesOrderItemId);

        if ($soItem->sales_order_id !== $salesOrderId) {
            throw new BusinessException('Sales Order item does not belong to the specified Sales Order.');
        }

        return $soItem;
    }

    protected function assertWithinOutstanding(SalesOrderItem $soItem, int $qty): void
    {
        $outstanding = $soItem->qty - $soItem->delivered_qty;

        if ($qty > $outstanding) {
            throw new BusinessException("Delivered qty ({$qty}) exceeds outstanding qty ({$outstanding}) for item {$soItem->item->item_code}.");
        }
    }

    protected function assertSufficientStock(string $warehouseId, string $itemId, int $qty): void
    {
        $available = $this->stockLedgerService->getCurrentBalance($itemId, $warehouseId);

        if ($qty > $available) {
            throw new BusinessException("Insufficient stock: requested {$qty}, available {$available} in this warehouse.");
        }
    }

    protected function assertDraft(Delivery $delivery, string $action): void
    {
        if ($delivery->status !== DeliveryStatus::PENDING) {
            throw new BusinessException("Only pending Deliveries can be {$action}.");
        }
    }
}
