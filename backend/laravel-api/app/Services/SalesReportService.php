<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Invoice;
use App\Repositories\CompanyRepository;
use App\Repositories\InvoiceRepository;
use Illuminate\Support\Collection;

/**
 * Sales → Invoices' "Laporan Penjualan" export — Summary (one row per invoice) and Detail (one
 * header row per invoice plus a sub-row per line item), both built from the same filtered/selected
 * Invoice set. Row-shaping only; SalesReportController picks the Export class (XLSX/CSV writer)
 * and file name.
 */
class SalesReportService
{
    public function __construct(
        protected InvoiceRepository $invoiceRepository,
        protected CompanyRepository $companyRepository,
    ) {}

    /** @param array<string, mixed> $filters */
    public function rows(array $filters, ?array $ids = null): Collection
    {
        return $this->invoiceRepository->searchAll($filters, $ids);
    }

    /**
     * DATE, DOCUMENT#, CUSTOMER#, CUSTOMER NAME, CURRENCY, EXCL.TAX, DISC, TAX, INCL.TAX, REFERENCE 1#
     * plus a trailing Total row. subtotal - discount_amount + tax_amount = grand_total already
     * holds per invoice, so summing the four money columns independently reconciles for free.
     *
     * @return array<int, array<int, mixed>>
     */
    public function summaryRows(Collection $invoices): array
    {
        $currency = $this->currencyLabel();

        $rows = $invoices->map(fn (Invoice $invoice) => [
            $invoice->invoice_date?->format('Y-m-d'),
            $invoice->document_number,
            $invoice->customer?->customer_code,
            $invoice->customer?->customer_name,
            $currency,
            (float) $invoice->subtotal,
            (float) $invoice->discount_amount,
            (float) $invoice->tax_amount,
            (float) $invoice->grand_total,
            $invoice->reference_1,
        ])->all();

        $rows[] = [
            null, null, null, 'Total', null,
            round($invoices->sum(fn (Invoice $i) => (float) $i->subtotal), 2),
            round($invoices->sum(fn (Invoice $i) => (float) $i->discount_amount), 2),
            round($invoices->sum(fn (Invoice $i) => (float) $i->tax_amount), 2),
            round($invoices->sum(fn (Invoice $i) => (float) $i->grand_total), 2),
            null,
        ];

        return $rows;
    }

    /**
     * One unified 10-column table — the ticket's own header/item column lists already align:
     * DISC/TAX/T.CODE share positions 6/7/8 and AMOUNT/LINE AMOUNT share position 9 in both
     * row shapes, so columns 1-5 (and the header-only trailing REFERENCE 1#) are the only ones
     * that change meaning by row type:
     *   header row: DATE, DOCUMENT#, CUSTOMER#, NAME, DELIVERY TO, DISC, TAX, T.CODE, AMOUNT, REFERENCE 1#
     *   item row:   ITEM#, DESCRIPTION, UOM, QUANTITY, UNIT PRICE, DISC, TAX, T.CODE, LINE AMOUNT, (blank)
     *
     * @return array<int, array<int, mixed>>
     */
    public function detailRows(Collection $invoices): array
    {
        $rows = [];

        foreach ($invoices as $invoice) {
            /** @var Invoice $invoice */
            $rows[] = [
                $invoice->invoice_date?->format('Y-m-d'),
                $invoice->document_number,
                $invoice->customer?->customer_code,
                $invoice->customer?->customer_name,
                $invoice->delivery?->warehouse?->name,
                (float) $invoice->discount_amount,
                (float) $invoice->tax_amount,
                $this->headerTaxCode($invoice),
                (float) $invoice->grand_total,
                $invoice->reference_1,
            ];

            foreach ($invoice->items as $item) {
                $rows[] = [
                    $item->item_code,
                    $item->item_name,
                    $item->uom,
                    (int) $item->qty,
                    (float) $item->rate,
                    // No per-line discount exists anywhere in this system (discount is
                    // document-level only, already on the header row above) — always 0 here
                    // rather than a pro-rated split, which would invent numbers that don't
                    // reconcile to anything actually stored.
                    0.0,
                    (float) $item->tax_amount,
                    $item->tax?->code,
                    (float) $item->amount,
                    null,
                ];
            }
        }

        return $rows;
    }

    /**
     * A Goods invoice's tax is per-line since the tax refactor — no single header code exists
     * once lines disagree. Same "blank when mixed, the real code when uniform" rule already
     * shipped for DeliveryResource's own header tax_id.
     */
    protected function headerTaxCode(Invoice $invoice): ?string
    {
        $codes = $invoice->items->pluck('tax.code')->filter()->unique();

        return $codes->count() === 1 ? $codes->first() : null;
    }

    protected function currencyLabel(): ?string
    {
        $company = $this->companyRepository->defaultOrById(null);
        $currency = $company ? Currency::query()->where('code', $company->currency)->first() : null;

        if (! $currency) {
            return null;
        }

        return "{$currency->symbol} - " . number_format((float) $currency->exchange_rate, 2);
    }
}
