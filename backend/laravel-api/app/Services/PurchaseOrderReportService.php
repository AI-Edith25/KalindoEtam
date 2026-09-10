<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Repositories\CompanyRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Services\Concerns\BuildsListingReportBlock;
use Illuminate\Database\Eloquent\Collection;

/**
 * Purchase > Purchase Orders listing export — Summary (one row per PO) and Detail (one header
 * row per PO plus a repeating item-label row and a sub-row per line item). Column layout,
 * header/footer block, and every per-column quirk below are modeled directly on the client's real
 * legacy-system export samples (xlsPurchaseOrderListing.xlsx / _Detail.xlsx, verified cell-by-cell),
 * not just the ticket's prose spec:
 *   - Money is real numeric cells (format code applied by StylesSalesReportSheet's
 *     numberFormatColumns), not Sales' pre-formatted-text trick — this schema's PO amounts are
 *     already clean floats, no locale-safety workaround needed.
 *   - DISC is always 0 — no discount field exists anywhere on PurchaseOrder/PurchaseOrderItem.
 *   - Detail's per-PO header row hardcodes DISC/TAX to 0 even though the PO has a real
 *     tax_amount — a genuine template quirk, not a bug, preserved as-is.
 *   - NOTES is PurchaseOrder.remarks — this schema has no reference_1/reference_2 columns;
 *     confirmed with the user to repurpose remarks as "NOTES" and drop the second reference
 *     column entirely rather than guess at a second source that doesn't exist.
 *   - Row 5's timestamp is always column D for both variants (verified) — see wrapReport() calls.
 */
class PurchaseOrderReportService
{
    use BuildsListingReportBlock;

    public function __construct(
        protected PurchaseOrderRepository $purchaseOrderRepository,
        protected CompanyRepository $companyRepository,
    ) {}

    /** @param array<string, mixed> $filters */
    public function rows(array $filters): Collection
    {
        return $this->purchaseOrderRepository->searchAll($filters);
    }

    /**
     * DATE, DOCUMENT#, SUPPLIER #, SUPPLIER NAME, CURRENCY, EXCL.TAX, DISC, TAX, INCL.TAX, NOTES,
     * plus a trailing "Total By Header" row (label one column before EXCL.TAX). Straight from the
     * PurchaseOrder header fields — no line-item summing needed, total_amount/tax_amount/
     * grand_total already exist per document (PurchaseOrderService computes them at save time).
     *
     * @return array<int, array<int, mixed>>
     */
    public function summaryRows(Collection $orders): array
    {
        $rows = $orders->map(fn (PurchaseOrder $po) => [
            $po->order_date?->format('d/m/Y'),
            $po->document_number,
            $po->supplier?->supplier_code,
            $po->supplier?->supplier_name,
            'RP - 1.00',
            (float) $po->total_amount,
            0.0,
            (float) $po->tax_amount,
            (float) $po->grand_total,
            $po->remarks,
        ])->all();

        $rows[] = [
            null, null, null, null, 'Total By Header',
            $this->formatTotal($orders->sum(fn (PurchaseOrder $po) => (float) $po->total_amount)),
            $this->formatTotal(0),
            $this->formatTotal($orders->sum(fn (PurchaseOrder $po) => (float) $po->tax_amount)),
            $this->formatTotal($orders->sum(fn (PurchaseOrder $po) => (float) $po->grand_total)),
            null,
        ];

        return $rows;
    }

    /**
     * Nested per-PO: a header-data row (DATE..NAME, then DISC/TAX hardcoded 0, T.CODE null,
     * AMOUNT=grand_total, NOTES=remarks), an item-label row, then that PO's item rows — repeated
     * for every PO in $orders. No trailing total row (Detail never has one in the reference file).
     *
     * @return array<int, array<int, mixed>>
     */
    public function detailRows(Collection $orders): array
    {
        $rows = [];

        foreach ($orders as $po) {
            /** @var PurchaseOrder $po */
            $rows[] = [
                $po->order_date?->format('d/m/Y'),
                $po->document_number,
                $po->supplier?->supplier_code,
                $po->supplier?->supplier_name,
                null, null, null,
                0.0,
                0.0,
                null,
                (float) $po->grand_total,
                $po->remarks,
            ];

            $rows[] = ['ITEM #', null, 'DESCRIPTION', null, 'UOM', 'QUANTITY', 'UNIT PRICE', 'DISC', 'TAX', 'T.CODE', 'LINE AMOUNT'];

            foreach ($po->items as $item) {
                $rows[] = [
                    $item->item?->item_code,
                    null,
                    $item->item?->item_name,
                    null,
                    $item->item?->uom?->name,
                    (float) $item->qty,
                    (float) $item->rate,
                    0.0,
                    (float) $item->tax_amount,
                    $item->tax?->code,
                    (float) $item->amount,
                ];
            }
        }

        return $rows;
    }

    /** @return array<int, mixed> */
    public function summaryHeadings(): array
    {
        return ['DATE', 'DOCUMENT#', 'SUPPLIER #', 'SUPPLIER NAME', 'CURRENCY', 'EXCL.TAX', 'DISC', 'TAX', 'INCL.TAX', 'NOTES'];
    }

    /** One-time top heading row (row 8 in the reference file) — each PO's own repeating item-label row is emitted inline by detailRows(). @return array<int, mixed> */
    public function detailHeadings(): array
    {
        return ['DATE', 'DOCUMENT #', 'SUPPLIER#', 'NAME', null, null, null, 'DISC', 'TAX', 'T.CODE', 'AMOUNT', 'NOTES'];
    }
}
