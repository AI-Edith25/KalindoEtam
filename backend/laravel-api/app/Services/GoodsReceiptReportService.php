<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Repositories\CompanyRepository;
use App\Repositories\GoodsReceiptRepository;
use App\Services\Concerns\BuildsListingReportBlock;
use Illuminate\Database\Eloquent\Collection;

/**
 * Purchase > Goods Receipts listing export — Summary (one row per GR) and Detail (one header row
 * per GR plus a repeating item-label row and a sub-row per line item). Column layout and every
 * per-column quirk below are modeled directly on the client's real legacy-system export samples
 * (xlsGoodsReceiveNotesListing_Summary.xlsx / _Detail.xlsx, verified cell-by-cell):
 *   - No "- Base Currency" suffix on the date-range line, unlike Purchase Order's own export.
 *   - Row 8 headers are NOT all-caps ("Date", "Document#", ...) — verified, deliberately kept
 *     as-is rather than "corrected" to match PO's all-caps convention.
 *   - Row 5's timestamp column differs by variant: Summary puts it at column I, Detail at R (its
 *     actual last column) — neither matches PO's fixed column D. Both verified against the files,
 *     not derived from any general rule (there isn't a consistent one across all 4 templates).
 *   - EXCL.TAX/TAX/INCL.TAX are summed from goods_receipt_items.amount/tax_amount — this schema
 *     has no tax on GoodsReceipt/GoodsReceiptItem by default (tax is normally only recorded once
 *     a Purchase Invoice is raised); tax_id/tax_amount on GoodsReceiptItem exist solely to back
 *     this export (auto-copied from the linked PurchaseOrderItem, or optional/manual for a Direct
 *     Receipt line — see GoodsReceiptService). DISC is always 0 (no discount field exists).
 *   - Reference 1 #/Reference 2 # are the linked PurchaseOrder's document_number/remarks — blank
 *     for a Direct Receipt (no purchase_order_id). Reference 2 # is NOT "usually blank" as the
 *     ticket's prose guessed; the reference file shows it's the linked PO's own remarks whenever
 *     one exists, so that's what's reproduced here.
 *   - Detail's SALES PERSON/DEPARTMENT/PROJECT/BRANCH columns are always blank — scanned the full
 *     306-row reference file, non-blank in zero rows; no branch/salesperson/department/project
 *     relation exists anywhere on GoodsReceipt or its items in this schema. Not a bug.
 */
class GoodsReceiptReportService
{
    use BuildsListingReportBlock;

    public function __construct(
        protected GoodsReceiptRepository $goodsReceiptRepository,
        protected CompanyRepository $companyRepository,
    ) {}

    /** @param array<string, mixed> $filters */
    public function rows(array $filters): Collection
    {
        return $this->goodsReceiptRepository->searchAll($filters);
    }

    /**
     * Date, Document#, Supplier#, Supplier Name, Currency, Excl.Tax, Disc, Tax, Incl.Tax,
     * Reference 1 #, Reference 2 #, plus a trailing "Total By Header" row.
     *
     * @return array<int, array<int, mixed>>
     */
    public function summaryRows(Collection $receipts): array
    {
        $exclTaxOf = fn (GoodsReceipt $gr) => (float) $gr->items->sum('amount');
        $taxOf = fn (GoodsReceipt $gr) => (float) $gr->items->sum('tax_amount');

        $rows = $receipts->map(function (GoodsReceipt $gr) use ($exclTaxOf, $taxOf) {
            $exclTax = $exclTaxOf($gr);
            $tax = $taxOf($gr);

            return [
                $gr->receipt_date?->format('d/m/Y'),
                $gr->document_number,
                $gr->supplier?->supplier_code,
                $gr->supplier?->supplier_name,
                'RP - 1.00',
                $exclTax,
                0.0,
                $tax,
                $exclTax + $tax,
                $gr->purchaseOrder?->document_number,
                $gr->purchaseOrder?->remarks,
            ];
        })->all();

        $totalExclTax = $receipts->sum($exclTaxOf);
        $totalTax = $receipts->sum($taxOf);

        $rows[] = [
            null, null, null, null, 'Total By Header',
            $this->formatTotal($totalExclTax),
            $this->formatTotal(0),
            $this->formatTotal($totalTax),
            $this->formatTotal($totalExclTax + $totalTax),
            null, null,
        ];

        return $rows;
    }

    /**
     * Nested per-GR: a header-data row (DATE..NAME, DISC hardcoded 0, TAX/AMOUNT summed from
     * items, Reference 1/2 from the linked PO), an item-label row, then that GR's item rows —
     * repeated for every GR in $receipts. No trailing total row.
     *
     * @return array<int, array<int, mixed>>
     */
    public function detailRows(Collection $receipts): array
    {
        $rows = [];

        foreach ($receipts as $gr) {
            /** @var GoodsReceipt $gr */
            $exclTax = (float) $gr->items->sum('amount');
            $tax = (float) $gr->items->sum('tax_amount');
            $poNumber = $gr->purchaseOrder?->document_number;

            $rows[] = [
                $gr->receipt_date?->format('d/m/Y'),
                $gr->document_number,
                $gr->supplier?->supplier_code,
                $gr->supplier?->supplier_name,
                null, null, null,
                0.0,
                $tax,
                $exclTax + $tax,
                $poNumber,
                $gr->purchaseOrder?->remarks,
            ];

            $rows[] = [
                'ITEM #', null, 'DESCRIPTION', null, 'UOM', 'QUANTITY', 'UNIT COST', 'DISC', 'TAX', 'LINE AMOUNT',
                null, null, 'PO NO', 'SALES PERSON', 'LOCATION', 'DEPARTMENT', 'PROJECT', 'BRANCH',
            ];

            foreach ($gr->items as $item) {
                $rows[] = [
                    $item->item_code,
                    null,
                    $item->item_name,
                    null,
                    $item->uom,
                    (float) $item->qty,
                    (float) $item->rate,
                    0.0,
                    (float) $item->tax_amount,
                    (float) $item->amount + (float) $item->tax_amount,
                    null,
                    null,
                    $poNumber,
                    null,
                    $gr->warehouse?->code,
                    null,
                    null,
                    null,
                ];
            }
        }

        return $rows;
    }

    /** @return array<int, mixed> */
    public function summaryHeadings(): array
    {
        return ['Date', 'Document#', 'Supplier#', 'Supplier Name', 'Currency', 'Excl.Tax', 'Disc', 'Tax', 'Incl.Tax', 'Reference 1 #', 'Reference 2 #'];
    }

    /** One-time top heading row — each GR's own repeating item-label row is emitted inline by detailRows(). @return array<int, mixed> */
    public function detailHeadings(): array
    {
        return ['DATE', 'DOCUMENT #', 'SUPPLIER#', 'NAME', null, null, null, 'DISC', 'TAX', 'AMOUNT', 'REFERENCE 1 #', 'REFERENCE 2 #'];
    }
}
