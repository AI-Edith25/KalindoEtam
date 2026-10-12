<?php

namespace App\Services\Import;

use App\Enums\DiscountType;
use App\Enums\QtyCategory;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\MiscellaneousItem;
use App\Support\DocumentKeyNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Fills in real item-level detail on an Invoice CustomerOutstandingArchiveImportService already
 * created (import_source_type = 'outstanding_bills_archive') — those only ever get one freeform
 * line, since the Customer Outstanding Bills file has no item detail at all. This reads the old
 * system's full-detail export ("Sales Invoice Listing - Detail", the exact file shape
 * SalesInvoiceHistoryParser already parsed before the old import feature was removed 2026-10-08)
 * and, for every invoice it can confidently match back to one already in this system, replaces
 * the placeholder line with the file's real item rows and updates the header's
 * subtotal/discount_amount/tax_amount/reference_1/reference_2.
 *
 * Never creates an Invoice and never touches grand_total/customer_id/dates/status/warehouse/
 * stock/GL — those are already correct from the Outstanding Bills import. A group this can't
 * confidently match (wrong customer, wrong type, an item code with no master match, or a
 * recomputed total that doesn't reconcile) is skipped entirely and reported — never partially
 * applied. See docs/superpowers/specs/2026-10-12-sales-invoice-detail-backfill-design.md.
 */
class SalesInvoiceDetailBackfillService
{
    /** Not the usual 0.01 elsewhere in this app — this sums many lines from decades-old legacy
        data, so small per-line rounding drift compounds (same tolerance the old, now-removed
        SalesInvoiceImportService::crossCheckAmount() used for exactly this reason). */
    private const AMOUNT_TOLERANCE = 1.0;

    public function __construct(protected SalesInvoiceHistoryParser $parser) {}

    /**
     * @return array{
     *   total_rows: int, will_update: int,
     *   skipped_not_found: array<int, array{document_number: string, customer_code: string}>,
     *   skipped_customer_mismatch: array<int, array{document_number: string, file_customer_code: string, invoice_customer_code: string}>,
     *   skipped_type_mismatch: array<int, array{document_number: string, file_type: string, invoice_type: string}>,
     *   skipped_item_unresolved: array<int, array{document_number: string}>,
     *   skipped_amount_mismatch: array<int, array{document_number: string, file_amount: float, computed_amount: float}>,
     *   warnings: array<int, string>,
     * }
     */
    public function preflight(string $absolutePath, string $extension): array
    {
        $parsed = $this->parseFile($absolutePath, $extension);
        $classified = $this->classify($parsed['invoices']);

        return [...$this->summarize($classified), 'total_rows' => count($parsed['invoices']), 'warnings' => $parsed['warnings']];
    }

    /** @return array{updated: int, document_numbers: array<int, string>} */
    public function commit(string $absolutePath, string $extension): array
    {
        $parsed = $this->parseFile($absolutePath, $extension);
        $classified = $this->classify($parsed['invoices']);

        $documentNumbers = [];
        foreach ($classified as $entry) {
            if ($entry['outcome'] !== 'will_update') {
                continue;
            }

            DB::transaction(function () use ($entry) {
                $this->applyDetail($entry['invoice'], $entry['group'], $entry['resolvedItems'], $entry['totals']);
            });
            $documentNumbers[] = $entry['group']['document_number'];
        }

        return ['updated' => count($documentNumbers), 'document_numbers' => $documentNumbers];
    }

    /** @return array{invoices: array, warnings: array<int, string>} */
    private function parseFile(string $absolutePath, string $extension): array
    {
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        if (! is_string($rawRows[0][0] ?? null) || ! str_contains(mb_strtoupper($rawRows[0][0]), SalesInvoiceHistoryParser::TITLE)) {
            throw new BusinessException('File ini bukan format "Sales Invoice Listing - Detail" — periksa kembali file yang diupload.');
        }

        return $this->parser->parse($rawRows);
    }

    /**
     * One file group -> one classified entry. Read-only (no writes) so preflight() and commit()
     * share identical classification — commit() never trusts a cached preview, it reclassifies
     * fresh from the file every time, same posture as every other importer in this app.
     *
     * @param  array<int, array>  $groups  SalesInvoiceHistoryParser::parse()'s own 'invoices' shape
     * @return array<int, array{group: array, outcome: string, invoice?: Invoice, resolvedItems?: array, totals?: array}>
     */
    private function classify(array $groups): array
    {
        $normalizedNumbers = collect($groups)
            ->map(fn ($g) => DocumentKeyNormalizer::normalize($g['document_number']))
            ->filter()
            ->all();

        $invoicesByNormalized = Invoice::query()
            ->where('import_source_type', 'outstanding_bills_archive')
            ->whereIn('source_document_number_normalized', $normalizedNumbers)
            ->with('customer:id,customer_code')
            ->get()
            ->keyBy('source_document_number_normalized');

        $itemCodes = collect($groups)
            ->filter(fn ($g) => $g['type'] === 'goods')
            ->flatMap(fn ($g) => collect($g['items'])->pluck('item_code'))
            ->unique()
            ->all();

        $itemsByCode = Item::query()->whereIn('item_code', $itemCodes)->with('uom')->get()
            ->keyBy(fn (Item $i) => mb_strtoupper(trim($i->item_code)));
        $miscByCode = MiscellaneousItem::query()->whereIn('misc_code', $itemCodes)->get()
            ->keyBy(fn (MiscellaneousItem $m) => mb_strtoupper(trim($m->misc_code)));

        return array_map(
            fn (array $group) => $this->classifyOne($group, $invoicesByNormalized, $itemsByCode, $miscByCode),
            $groups
        );
    }

    /** @return array{group: array, outcome: string, invoice?: Invoice, resolvedItems?: array, totals?: array} */
    private function classifyOne(array $group, $invoicesByNormalized, $itemsByCode, $miscByCode): array
    {
        $normalized = DocumentKeyNormalizer::normalize($group['document_number']);
        $invoice = $normalized !== null ? $invoicesByNormalized->get($normalized) : null;

        if ($invoice === null) {
            return ['group' => $group, 'outcome' => 'skipped_not_found'];
        }

        if (mb_strtoupper(trim($invoice->customer->customer_code)) !== mb_strtoupper(trim($group['customer_code']))) {
            return ['group' => $group, 'outcome' => 'skipped_customer_mismatch', 'invoice' => $invoice];
        }

        if ($invoice->invoice_type->value !== $group['type']) {
            return ['group' => $group, 'outcome' => 'skipped_type_mismatch', 'invoice' => $invoice];
        }

        $resolvedItems = [];
        if ($group['type'] === 'goods') {
            foreach ($group['items'] as $itemRow) {
                $code = mb_strtoupper(trim($itemRow['item_code']));
                $resolved = $itemsByCode->get($code) ?? $miscByCode->get($code);

                if ($resolved === null) {
                    return ['group' => $group, 'outcome' => 'skipped_item_unresolved', 'invoice' => $invoice];
                }

                $resolvedItems[] = $resolved;
            }
        } else {
            $resolvedItems = array_fill(0, count($group['items']), null);
        }

        $totals = $this->computeTotals($group);

        if (abs($totals['grand_total'] - (float) $invoice->grand_total) > self::AMOUNT_TOLERANCE) {
            return ['group' => $group, 'outcome' => 'skipped_amount_mismatch', 'invoice' => $invoice, 'totals' => $totals];
        }

        return ['group' => $group, 'outcome' => 'will_update', 'invoice' => $invoice, 'resolvedItems' => $resolvedItems, 'totals' => $totals];
    }

    /**
     * Same formula the old, now-removed SalesInvoiceImportService::totals() used: subtotal is the
     * sum of qty*rate across item rows (pre-tax), tax_amount is the sum of each item's own TAX
     * (not the file's header TAX column — summing the lines keeps it consistent with subtotal),
     * discount_amount is the file's header DISC column directly.
     *
     * @return array{subtotal: float, tax_amount: float, discount_amount: float, grand_total: float}
     */
    private function computeTotals(array $group): array
    {
        $subtotal = 0.0;
        $taxTotal = 0.0;

        foreach ($group['items'] as $itemRow) {
            $subtotal += (float) $itemRow['qty'] * (float) $itemRow['rate'];
            $taxTotal += (float) $itemRow['tax'];
        }

        $subtotal = round($subtotal, 2);
        $taxTotal = round($taxTotal, 2);
        $discountAmount = round((float) $group['header_disc'], 2);

        return [
            'subtotal' => $subtotal,
            'tax_amount' => $taxTotal,
            'discount_amount' => $discountAmount,
            'grand_total' => round($subtotal - $discountAmount + $taxTotal, 2),
        ];
    }

    /** @param  array<int, Item|MiscellaneousItem|null>  $resolvedItems */
    private function applyDetail(Invoice $invoice, array $group, array $resolvedItems, array $totals): void
    {
        $invoice->items()->delete();

        foreach ($group['items'] as $i => $itemRow) {
            $resolved = $resolvedItems[$i];
            $item = $resolved instanceof Item ? $resolved : null;
            $misc = $resolved instanceof MiscellaneousItem ? $resolved : null;
            $qtyCategory = $item?->qty_category ?? QtyCategory::WEIGHT;

            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'delivery_item_id' => null,
                'item_id' => $item?->id,
                'item_code' => $item?->item_code,
                'item_name' => $item?->item_name ?? $misc?->description ?? $itemRow['description'],
                'uom' => $item?->uom?->name ?? $itemRow['uom'],
                'rate' => $itemRow['rate'],
                'qty' => round((float) $itemRow['qty'], $qtyCategory->decimalPlaces()),
                'qty_category' => $qtyCategory->value,
                'amount' => round((float) $itemRow['qty'] * (float) $itemRow['rate'], 2),
                'tax_id' => null,
                'tax_amount' => round((float) $itemRow['tax'], 2),
            ]);
        }

        $invoice->update([
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'discount_type' => DiscountType::AMOUNT->value,
            'tax_amount' => $totals['tax_amount'],
            'reference_1' => $group['reference_1'],
            'reference_2' => $group['reference_2'],
        ]);
    }

    /**
     * Full row detail for every skip outcome — doubles as the source for the rejected-rows CSV,
     * same pattern as CustomerOutstandingArchiveImportService::summarizeClassification().
     *
     * @param  array<int, array{group: array, outcome: string}>  $classified
     */
    private function summarize(array $classified): array
    {
        $pick = fn (string $outcome, callable $map) => array_values(array_map(
            $map,
            array_filter($classified, fn ($c) => $c['outcome'] === $outcome)
        ));

        return [
            'will_update' => count(array_filter($classified, fn ($c) => $c['outcome'] === 'will_update')),
            'skipped_not_found' => $pick('skipped_not_found', fn ($c) => [
                'document_number' => $c['group']['document_number'],
                'customer_code' => $c['group']['customer_code'],
            ]),
            'skipped_customer_mismatch' => $pick('skipped_customer_mismatch', fn ($c) => [
                'document_number' => $c['group']['document_number'],
                'file_customer_code' => $c['group']['customer_code'],
                'invoice_customer_code' => $c['invoice']->customer->customer_code,
            ]),
            'skipped_type_mismatch' => $pick('skipped_type_mismatch', fn ($c) => [
                'document_number' => $c['group']['document_number'],
                'file_type' => $c['group']['type'],
                'invoice_type' => $c['invoice']->invoice_type->value,
            ]),
            'skipped_item_unresolved' => $pick('skipped_item_unresolved', fn ($c) => [
                'document_number' => $c['group']['document_number'],
            ]),
            'skipped_amount_mismatch' => $pick('skipped_amount_mismatch', fn ($c) => [
                'document_number' => $c['group']['document_number'],
                'file_amount' => $c['group']['header_amount'],
                'computed_amount' => $c['totals']['grand_total'],
            ]),
        ];
    }
}
