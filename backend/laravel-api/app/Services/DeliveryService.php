<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\DiscountType;
use App\Enums\SalesOrderStatus;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Exceptions\BusinessException;
use App\Exports\Concerns\BuildsSalesSummaryReport;
use App\Models\Delivery;
use App\Models\InvoiceItem;
use App\Models\SalesOrderItem;
use App\Repositories\CompanyRepository;
use App\Repositories\DeliveryItemRepository;
use App\Repositories\DeliveryRepository;
use App\Repositories\ItemRepository;
use App\Repositories\SalesOrderItemRepository;
use App\Repositories\SalesOrderRepository;
use App\Repositories\TaxRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
        protected FifoLayerService $fifoLayerService,
        protected AuditLogService $auditLogService,
        protected TaxService $taxService,
        protected DiscountService $discountService,
        protected CompanyRepository $companyRepository,
        protected QtyCategoryValidator $qtyCategoryValidator,
        protected TaxRepository $taxRepository,
        protected DocumentTimelineService $documentTimelineService,
        protected ItemRepository $itemRepository,
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

    /** The "Detail" export's data source — see DeliveryDetailExport. Unevaluated Builder, streamed by the caller. */
    public function detailExportQuery(array $filters = [], ?array $ids = null): Builder
    {
        return $this->deliveryRepository->detailExportQuery($filters, $ids);
    }

    /**
     * The export's date range for its A2 period label / filename (see
     * DeliveryDetailExport / DeliveryController::export()) — the explicit
     * date_from/date_to filter when the caller gave one, else the actual
     * min/max delivery_date within the filtered set. $ids (checked-rows
     * export) ignores date_from/date_to entirely, same as
     * detailExportQuery()'s own "checked rows win outright" contract —
     * those filters were never applied to the query in that case either.
     *
     * @return array{from: ?\Carbon\Carbon, to: ?\Carbon\Carbon}
     */
    public function detailExportPeriod(array $filters, ?array $ids): array
    {
        $dateFrom = empty($ids) ? ($filters['date_from'] ?? null) : null;
        $dateTo = empty($ids) ? ($filters['date_to'] ?? null) : null;

        if (! $dateFrom && ! $dateTo) {
            $bounds = $this->deliveryRepository->detailExportDateBounds($filters, $ids);
            $dateFrom = $bounds['from'];
            $dateTo = $bounds['to'];
        }

        return [
            'from' => $dateFrom ? \Carbon\Carbon::parse($dateFrom) : null,
            'to' => $dateTo ? \Carbon\Carbon::parse($dateTo) : null,
        ];
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
            $qty = (float) $delivery->items->sum('qty');
            $excl = (float) $delivery->items->sum('amount');
            $tax = round((float) $delivery->items->sum('tax_amount'), 2);

            return [
                $this->summaryExcelDate($delivery->delivery_date),
                $delivery->document_number,
                $delivery->customer?->customer_code,
                $delivery->customer?->customer_name,
                $qty,
                $excl,
                0.0,
                $tax,
                round($excl + $tax, 2),
                $delivery->salesOrder?->document_number,
            ];
        })->all();

        $sumQty = (float) $deliveries->sum(fn (Delivery $d) => (float) $d->items->sum('qty'));
        $sumExcl = round($deliveries->sum(fn (Delivery $d) => (float) $d->items->sum('amount')), 2);
        $sumTax = round($deliveries->sum(fn (Delivery $d) => (float) $d->items->sum('tax_amount')), 2);

        $bodyRows[] = [null, null, null, 'Total By Header', $sumQty, $sumExcl, 0.0, $sumTax, round($sumExcl + $sumTax, 2), null];

        $taxGroups = $this->groupTaxSummary($deliveries, fn (Delivery $d) => $d->items->map(fn ($item) => [
            $item->tax?->code, (float) ($item->tax?->rate ?? 0), (float) $item->amount, (float) $item->tax_amount,
        ])->all());

        return $this->buildSalesSummaryReport(
            title: 'DELIVERY ORDER LISTING - SUMMARY',
            periodLabel: $this->summaryPeriodLabel($filters, $deliveries, 'delivery_date'),
            companyName: $this->companyRepository->defaultOrById(null)?->name ?? 'PT. KALINDO ETAM',
            headingRow: ['Date', 'Document', 'Customer', 'Customer Name', 'Qty', 'Excl.Tax', 'Disc', 'Tax', 'Incl.Tax', 'Reference'],
            bodyRows: $bodyRows,
            taxGroups: $taxGroups,
            printedBy: Auth::user()?->name ?? 'System',
            lastColumn: 'J',
            numberFormatColumns: ['E', 'F', 'G', 'H', 'I'],
        );
    }

    public function create(array $data): Delivery
    {
        return DB::transaction(function () use ($data) {
            $salesOrderIds = $this->resolveRequestedSalesOrderIds($data);

            if (empty($salesOrderIds)) {
                return $this->createDirect($data);
            }

            // Deterministic anchor regardless of selection order — same tie-break as
            // InvoiceService::createGoods()'s $deliveries->sortBy(delivery_date, id).
            $salesOrders = collect($salesOrderIds)
                ->map(fn (string $id) => $this->salesOrderRepository->findOrFail($id))
                ->sortBy(fn ($salesOrder) => [$salesOrder->order_date, $salesOrder->id])
                ->values();

            foreach ($salesOrders as $salesOrder) {
                if ($salesOrder->status !== SalesOrderStatus::APPROVED) {
                    throw new BusinessException("Sales Order {$salesOrder->document_number} must be approved before a Delivery can be created against it.");
                }
            }

            if ($salesOrders->pluck('customer_id')->unique()->count() > 1) {
                throw new BusinessException('All selected Sales Orders must belong to the same Customer.');
            }

            if ($salesOrders->pluck('warehouse_id')->unique()->count() > 1) {
                throw new BusinessException('All selected Sales Orders must belong to the same Warehouse.');
            }

            $anchor = $salesOrders->first();
            $salesOrderIds = $salesOrders->pluck('id')->all();

            $delivery = $this->deliveryRepository->create([
                'sales_order_id' => $anchor->id,
                'customer_id' => $anchor->customer_id,
                'warehouse_id' => $data['warehouse_id'],
                'delivery_date' => $data['delivery_date'],
                'due_date' => $data['due_date'],
                'terms_of_payment_id' => $data['terms_of_payment_id'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'fleet' => $data['fleet'] ?? null,
                'driver' => $data['driver'] ?? null,
            ]);

            foreach ($data['items'] as $line) {
                $this->addLine($delivery, $salesOrderIds, $line['sales_order_item_id'], $line['qty']);
            }

            $delivery->salesOrders()->sync($salesOrderIds);

            $delivery = $delivery->fresh(['customer', 'warehouse', 'salesOrder', 'salesOrders', 'items', 'termsOfPayment']);
            $this->auditLogService->record('created', 'delivery', "Created Delivery \"{$delivery->document_number}\".");

            return $delivery;
        });
    }

    /**
     * `sales_order_ids` (array, one or more) is the current shape — mirrors InvoiceService's
     * own `delivery_ids`. The older singular `sales_order_id` is still accepted so every
     * existing direct-service caller (tests, imports, seeders) keeps working unchanged.
     *
     * @return string[]
     */
    protected function resolveRequestedSalesOrderIds(array $data): array
    {
        if (! empty($data['sales_order_ids'])) {
            return $data['sales_order_ids'];
        }

        return ! empty($data['sales_order_id']) ? [$data['sales_order_id']] : [];
    }

    /**
     * Standalone delivery with no source Sales Order — items are typed
     * directly (item/qty/rate/tax) instead of copied from a Sales Order
     * line, so there's no assertWithinOutstanding()/incrementDeliveredQty()
     * to run (nothing to check against). Same shape as
     * GoodsReceiptService::createDirect(). Only reachable from create(),
     * always inside its transaction.
     */
    protected function createDirect(array $data): Delivery
    {
        $delivery = $this->deliveryRepository->create([
            'sales_order_id' => null,
            'customer_id' => $data['customer_id'],
            'warehouse_id' => $data['warehouse_id'],
            'delivery_date' => $data['delivery_date'],
            'due_date' => $data['due_date'],
            'terms_of_payment_id' => $data['terms_of_payment_id'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'fleet' => $data['fleet'] ?? null,
            'driver' => $data['driver'] ?? null,
        ]);

        foreach ($data['items'] as $line) {
            $this->addDirectLine($delivery, $line);
        }

        $delivery = $delivery->fresh(['customer', 'warehouse', 'items', 'termsOfPayment']);
        $this->auditLogService->record('created', 'delivery', "Created Delivery \"{$delivery->document_number}\".");

        return $delivery;
    }

    protected function addDirectLine(Delivery $delivery, array $line): void
    {
        $this->deliveryItemRepository->create($this->buildDirectDeliveryLineAttributes($delivery, $line));
    }

    /**
     * Mirrors buildDeliveryLineAttributes(), but for a line with no Sales
     * Order item behind it — item/rate/tax all come straight from the
     * request. tax_id resolution deliberately passes item: null (same as
     * GoodsReceiptService::createDirectLine()) so a Direct Delivery line's
     * tax is purely manual, never silently defaulted from the Item's own
     * sales_tax_id.
     */
    protected function buildDirectDeliveryLineAttributes(Delivery $delivery, array $line): array
    {
        $item = $this->itemRepository->findOrFail($line['item_id']);
        $this->qtyCategoryValidator->assertValid($item, $line['qty']);
        $qty = $this->qtyCategoryValidator->round($item, $line['qty']);
        $rate = (float) ($line['rate'] ?? 0);
        $grossAmount = $qty * $rate;
        [$discountType, $discountValue, $discountAmount, $netAmount] = $this->discountService->resolveLineDiscount($line, $grossAmount);
        [$taxId, $taxAmount] = $this->taxService->resolveLineTax($line, null, '', $netAmount);

        return [
            'delivery_id' => $delivery->id,
            'sales_order_item_id' => null,
            'item_id' => $item->id,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
            'uom' => $item->uom->name,
            'uom_factor' => 1,
            'rate' => $rate,
            'qty' => $qty,
            'qty_category' => $item->qty_category,
            'amount' => round($grossAmount, 2),
            'discount_type' => $discountType->value,
            'discount_value' => $discountValue,
            'discount_amount' => $discountAmount,
            'net_amount' => $netAmount,
            'tax_id' => $taxId,
            'tax_amount' => round($taxAmount, 2),
        ];
    }

    /** Pending: only header fields + full item replace (unchanged). Complete: see updateComplete(). */
    public function update(Delivery $delivery, array $data): Delivery
    {
        if ($delivery->status === DeliveryStatus::COMPLETE) {
            return $this->updateComplete($delivery, $data);
        }

        return DB::transaction(function () use ($delivery, $data) {
            $this->assertDraft($delivery, 'updated');

            $headerData = collect($data)->except('items')->all();

            if (isset($data['items'])) {
                $delivery->items()->delete();

                $salesOrderIds = $delivery->sales_order_id !== null ? $delivery->salesOrders()->pluck('sales_order_id')->all() : [];

                foreach ($data['items'] as $line) {
                    if ($delivery->sales_order_id === null) {
                        $this->addDirectLine($delivery, $line);
                    } else {
                        $this->addLine($delivery, $salesOrderIds, $line['sales_order_item_id'], $line['qty']);
                    }
                }
            }

            $this->deliveryRepository->update($delivery, $headerData);

            $delivery = $delivery->fresh(['customer', 'warehouse', 'salesOrder', 'items', 'termsOfPayment']);
            $this->auditLogService->record('updated', 'delivery', "Updated Delivery \"{$delivery->document_number}\".");

            return $delivery;
        });
    }

    /**
     * A stakeholder-driven relaxation, same posture as PurchaseOrderService::updateSubmitted()/
     * InvoiceService::updateSubmitted(): a Complete Delivery used to be fully locked. Header
     * fields (Customer, Location, Sales Person, dates, Terms, Attn/Tel/Fax, Fleet, Driver, Notes)
     * stay freely editable. Item rows may have Qty/Rate/Tax changed, and rows may be added
     * (any Sales Order item not yet on this Delivery, within its own remaining outstanding) or
     * removed — except a row already referenced by an Invoice (invoice_items.delivery_item_id is
     * restrictOnDelete; this catches it at the app layer with a clear message before the DB
     * would). Since Delivery has no field-level lock the way PO/SO do (unlike those, an
     * already-invoiced DO row's Qty/Rate are explicitly still editable per the ticket — the
     * Invoice already snapshotted its own item_code/item_name/uom/rate/qty/amount at
     * invoice-creation time and never re-reads the Delivery afterward, so this never touches an
     * already-issued Invoice), the whole item set is reverse-then-reposted: this Delivery's
     * entire current stock effect is undone (reverseDeliveryStock(), mirroring
     * GoodsReceiptService::reverseReceiptStock()), the new line set is written, then stock is
     * reposted fresh for it (postDeliveryStock(), the same posting logic complete() itself uses).
     * One transaction — a mid-way failure (insufficient stock for the new qty, outstanding
     * exceeded, etc.) rolls back stock/delivered_qty/rows together, never partially applied.
     */
    protected function updateComplete(Delivery $delivery, array $data): Delivery
    {
        return DB::transaction(function () use ($delivery, $data) {
            $delivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('lock_version', $data) && (int) $data['lock_version'] !== $delivery->lock_version) {
                throw new BusinessException('Dokumen ini sudah diubah oleh pengguna lain. Muat ulang halaman dan coba lagi.', 409);
            }

            $editableFields = ['customer_id', 'warehouse_id', 'sales_person_id', 'delivery_date', 'due_date', 'terms_of_payment_id', 'attention', 'tel', 'fax', 'fleet', 'driver', 'remarks'];
            $before = $delivery->only($editableFields);
            $headerData = collect($data)->only($editableFields)->all();

            if (isset($data['items'])) {
                $delivery->load(['items.salesOrderItem.item', 'items.salesOrderItem.tax']);

                foreach ($delivery->items as $line) {
                    if (! collect($data['items'])->contains(fn ($incoming) => ($incoming['id'] ?? null) === $line->id)) {
                        if (InvoiceItem::query()->where('delivery_item_id', $line->id)->exists()) {
                            throw new BusinessException("Baris item \"{$line->item_name}\" sudah di-invoice dan tidak bisa dihapus.");
                        }
                    }
                }

                $this->reverseDeliveryStock($delivery);

                $keepIds = collect($data['items'])->pluck('id')->filter();
                $delivery->items()->whereNotIn('id', $keepIds)->delete();

                $existingById = $delivery->items->keyBy('id');
                $salesOrderIds = $delivery->sales_order_id !== null ? $delivery->salesOrders()->pluck('sales_order_id')->all() : [];

                foreach ($data['items'] as $line) {
                    if ($delivery->sales_order_id === null) {
                        $attributes = $this->buildDirectDeliveryLineAttributes($delivery, $line);
                    } else {
                        $soItem = $this->resolveSalesOrderItem($salesOrderIds, $line['sales_order_item_id']);
                        $this->assertWithinOutstanding($soItem, $line['qty']);

                        $attributes = $this->buildDeliveryLineAttributes($delivery, $soItem, $line['qty'], $line['rate'] ?? null, $line['tax_id'] ?? null);
                    }

                    if (! empty($line['id']) && $existingById->has($line['id'])) {
                        $this->deliveryItemRepository->update($existingById[$line['id']], $attributes);
                    } else {
                        $this->deliveryItemRepository->create($attributes);
                    }
                }

                $delivery = $delivery->fresh(['items.salesOrderItem', 'salesOrder']);
                $this->postDeliveryStock($delivery);
            }

            $headerData['lock_version'] = $delivery->lock_version + 1;
            $this->deliveryRepository->update($delivery, $headerData);

            $delivery = $delivery->fresh(['customer', 'warehouse', 'salesOrder', 'items.invoiceItem', 'termsOfPayment', 'salesPerson', 'updater']);
            $this->auditLogService->recordChanges('updated', 'delivery', $delivery, $before, $headerData, "Updated Complete Delivery \"{$delivery->document_number}\".");
            $this->documentTimelineService->record($delivery, 'updated');

            return $delivery;
        });
    }

    /**
     * Undoes exactly what postDeliveryStock() posted for this Delivery's *current* items —
     * mirrors GoodsReceiptService::reverseReceiptStock() and InvoiceService::
     * reverseDirectGoodsStock() exactly: a compensating IN Stock Ledger entry per line,
     * FifoLayerService::reverseConsumption() (generic — same method Direct-Goods-Invoice reversal
     * already uses, just a different StockVoucherType), and the Sales Order line's delivered_qty
     * decremented back down. Must run before this Delivery's items/qty are changed, since it
     * relies on them still holding what was actually posted at complete()/the last edit.
     */
    protected function reverseDeliveryStock(Delivery $delivery): void
    {
        foreach ($delivery->items as $line) {
            $this->stockLedgerService->record(
                itemId: $line->item_id,
                warehouseId: $delivery->warehouse_id,
                transactionType: StockTransactionType::IN,
                voucherType: StockVoucherType::DELIVERY,
                voucherId: $delivery->id,
                qtyChange: $line->baseQty(),
                postingDatetime: $delivery->delivery_date,
                referenceNo: $delivery->document_number,
                remarks: "Correction of Delivery {$delivery->document_number}",
            );

            if ($line->salesOrderItem !== null) {
                $this->salesOrderItemRepository->incrementDeliveredQty($line->salesOrderItem, -$line->qty);
            }
        }

        $this->fifoLayerService->reverseConsumption(StockVoucherType::DELIVERY, $delivery->id);
    }

    /** Shared by complete() (first time) and updateComplete() (repost after reverseDeliveryStock()). */
    protected function postDeliveryStock(Delivery $delivery): void
    {
        foreach ($delivery->items as $line) {
            if ($line->salesOrderItem !== null) {
                $this->assertWithinOutstanding($line->salesOrderItem, $line->qty);
            }
            $this->assertSufficientStock($delivery->warehouse_id, $line->item_id, $line->baseQty());
        }

        foreach ($delivery->items as $line) {
            $this->stockLedgerService->record(
                itemId: $line->item_id,
                warehouseId: $delivery->warehouse_id,
                transactionType: StockTransactionType::OUT,
                voucherType: StockVoucherType::DELIVERY,
                voucherId: $delivery->id,
                qtyChange: -$line->baseQty(),
                postingDatetime: $delivery->delivery_date,
                referenceNo: $delivery->document_number,
                remarks: "Delivery {$delivery->document_number}",
            );

            $this->fifoLayerService->consume(
                itemId: $line->item_id,
                warehouseId: $delivery->warehouse_id,
                qty: $line->baseQty(),
                sourceType: StockVoucherType::DELIVERY,
                sourceId: $delivery->id,
            );

            if ($line->salesOrderItem !== null) {
                $this->salesOrderItemRepository->incrementDeliveredQty($line->salesOrderItem, $line->qty);
            }
        }
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
            $delivery->load(['items.salesOrderItem', 'salesOrder', 'salesOrders']);

            foreach ($delivery->salesOrders as $salesOrder) {
                if ($salesOrder->status !== SalesOrderStatus::APPROVED) {
                    throw new BusinessException("Sales Order {$salesOrder->document_number} is no longer approved; cannot deliver against it.");
                }
            }

            $this->postDeliveryStock($delivery);

            $delivery->submit();

            $delivery = $delivery->fresh(['customer', 'warehouse', 'salesOrder', 'items', 'termsOfPayment']);
            $this->auditLogService->record('completed', 'delivery', "Completed Delivery \"{$delivery->document_number}\".");

            return $delivery;
        });
    }

    /**
     * Only Pending Deliveries can be cancelled — nothing was posted to stock or delivered_qty yet,
     * so there's nothing to reverse. The record is kept (status = cancelled) for audit.
     */
    public function cancel(Delivery $delivery): Delivery
    {
        return DB::transaction(function () use ($delivery) {
            $this->assertDraft($delivery, 'cancelled');
            $documentNumber = $delivery->document_number;
            $delivery = $delivery->cancel();
            $this->auditLogService->record('cancelled', 'delivery', "Cancelled Delivery \"{$documentNumber}\".");

            return $delivery;
        });
    }

    protected function addLine(Delivery $delivery, array $salesOrderIds, string $salesOrderItemId, int|float $qty): void
    {
        $soItem = $this->resolveSalesOrderItem($salesOrderIds, $salesOrderItemId);
        $this->assertWithinOutstanding($soItem, $qty);

        $this->deliveryItemRepository->create($this->buildDeliveryLineAttributes($delivery, $soItem, $qty, null, null));
    }

    /**
     * Shared by addLine() (create/Pending-update, rate/tax always inherited from the Sales Order
     * line) and updateComplete() (Complete-edit, rate/tax may be overridden per the ticket's
     * "Rate dan Tax bisa diedit" rule). $rateOverride/$taxIdOverride null = inherit the SO line's
     * own value, same as today's behavior.
     */
    protected function buildDeliveryLineAttributes(Delivery $delivery, SalesOrderItem $soItem, int|float $qty, int|float|null $rateOverride, ?string $taxIdOverride): array
    {
        $item = $soItem->item;
        $this->qtyCategoryValidator->assertValid($item, $qty);
        $qty = $this->qtyCategoryValidator->round($item, $qty);

        $rate = $rateOverride ?? $soItem->rate;
        $grossAmount = $qty * $rate;

        // Discount is derived from the SO line, never re-entered here — a percentage carries over
        // as-is (it scales naturally against this DO's own, possibly partial, gross amount); a
        // nominal (Rp) discount is pro-rated by this DO's share of the SO line's total qty, so
        // several partial Deliveries against the same SO line never sum to more than its own
        // discount_amount (small rounding dust aside — each line rounds independently, same
        // discipline tax_amount already uses everywhere in this codebase).
        $soDiscountType = DiscountType::from($soItem->discount_type);
        $soDiscountValue = $soDiscountType === DiscountType::PERCENTAGE
            ? (float) $soItem->discount_value
            : round((float) $soItem->discount_amount * ($qty / $soItem->qty), 2);

        ['discount_amount' => $discountAmount, 'net_amount' => $netAmount] = $this->discountService->calculate($grossAmount, $soDiscountType, $soDiscountValue);

        // tax_id carries forward as-is (or the caller's override); tax_amount is recomputed
        // against this delivery line's own (possibly partial, possibly overridden) net amount,
        // not simply copied — same "rate inherited, amount recomputed against the real quantity"
        // rule the old header-level inheritance used, now applied per line, now against net.
        $taxId = $taxIdOverride ?? $soItem->tax_id;
        $tax = $taxId !== null ? $this->taxRepository->findOrFail($taxId) : null;
        $taxAmount = $tax !== null ? $this->taxService->calculate($netAmount, $tax)['tax_amount'] : 0.0;

        return [
            'delivery_id' => $delivery->id,
            'sales_order_item_id' => $soItem->id,
            'item_id' => $item->id,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
            // The SO line's UOM (qty/rate are in it) + its factor to the item's base UOM —
            // complete() converts to base qty for the Stock Ledger and FIFO.
            'uom' => $soItem->uom?->name ?? $item->uom->name,
            'uom_factor' => $soItem->uom_factor,
            'rate' => $rate,
            'qty' => $qty,
            'qty_category' => $item->qty_category,
            'amount' => round($grossAmount, 2),
            'discount_type' => $soDiscountType->value,
            'discount_value' => $soDiscountValue,
            'discount_amount' => $discountAmount,
            'net_amount' => $netAmount,
            'tax_id' => $taxId,
            'tax_amount' => round($taxAmount, 2),
        ];
    }

    protected function resolveSalesOrderItem(array $salesOrderIds, string $salesOrderItemId): SalesOrderItem
    {
        $soItem = $this->salesOrderItemRepository->findOrFail($salesOrderItemId);

        if (! in_array($soItem->sales_order_id, $salesOrderIds, true)) {
            throw new BusinessException('Sales Order item does not belong to any of the selected Sales Orders.');
        }

        return $soItem;
    }

    protected function assertWithinOutstanding(SalesOrderItem $soItem, int|float $qty): void
    {
        $outstanding = $soItem->qty - $soItem->delivered_qty;

        if ($qty > $outstanding) {
            throw new BusinessException("Delivered qty ({$qty}) exceeds outstanding qty ({$outstanding}) for item {$soItem->item->item_code}.");
        }
    }

    protected function assertSufficientStock(string $warehouseId, string $itemId, int|float $qty): void
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
