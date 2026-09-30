<?php

namespace App\Services\Import;

/**
 * Parses the legacy "Sales Invoice Listing - Detail" export into one entry per historical
 * Invoice (header row + its nested item rows) — see SalesInvoiceImportService.
 *
 * Shape (confirmed against a real export): title row 1 contains "SALES INVOICE LISTING". The real
 * header row (found via ImportFileReader::findHeaderRowByCell(..., 'DOCUMENT #')) is: DATE,
 * DOCUMENT #, CUSTOMER#, NAME, (blank), DELIVERY TO, (blank), DISC, TAX, T.CODE, AMOUNT,
 * REFERENCE 1 #, REFERENCE 2 #. The row right after is a second, item-level sub-header: ITEM #,
 * (blank), DESCRIPTION, (blank), UOM, QUANTITY, UNIT PRICE, DISC, TAX, T.CODE, LINE AMOUNT — data
 * starts 2 rows below the main header. Both rows share the same column layout (position-based,
 * same technique ProductSalesArchiveImportService/SupplierPurchaseListingParser already use),
 * since a single Excel column meaning differs between the two row kinds.
 *
 * Body: one invoice header row (col 0 parses as a date) followed by 1+ item rows (col 0 is an
 * item code, not a date) until the next header row. DOCUMENT # prefix picks the Invoice type:
 * "SI/KE/..." -> Goods (item row col 0 is a real Item.item_code), "TR/KE/..." -> Transportation
 * (item row col 0 is always the literal "TRANSPORT" code, never a real Item — no Item lookup is
 * ever attempted for these, matching InvoiceService::createTransportation()'s own freeform-
 * description design). Any other prefix is skipped with a warning, never guessed.
 *
 * LINE AMOUNT is qty*rate + TAX (amount *including* tax, confirmed against several real rows) —
 * TAX is stored as-is as the line's raw tax_amount, matching Invoice::tax()'s own documented
 * "legacy raw tax_amount, no tax_id" convention.
 *
 * The file ends with a "TAX SUMMARY" block then a "Printed By :" trailer — parsing stops at the
 * first row whose column 0 is exactly "TAX SUMMARY" (fallback: a "Printed By" prefix), same
 * trailer-detection style ProductSalesArchiveImportService already uses.
 */
final class SalesInvoiceHistoryParser
{
    public const TITLE = 'SALES INVOICE LISTING';

    private const PREFIX_TYPE = [
        'SI' => 'goods',
        'TR' => 'transportation',
    ];

    /**
     * @return array{
     *   invoices: array<int, array{
     *     document_number: string, type: string, date: string, customer_code: string,
     *     customer_name: string, header_disc: float, header_tax: float, header_amount: float,
     *     reference_1: ?string, reference_2: ?string,
     *     items: array<int, array{item_code: string, description: string, uom: ?string, qty: float, rate: float, disc: float, tax: float, line_amount: float}>,
     *   }>,
     *   warnings: array<int, string>,
     * }
     */
    public function parse(array $rawRows): array
    {
        $headerRowIndex = ImportFileReader::findHeaderRowByCell($rawRows, 'DOCUMENT #');
        $bodyRows = array_slice($rawRows, $headerRowIndex + 2);

        $warnings = [];
        $invoices = [];
        $current = null;

        foreach ($bodyRows as $row) {
            $colA = $row[0] ?? null;

            if ($this->isBlankRow($row)) {
                continue;
            }

            if (is_string($colA) && strtoupper(trim($colA)) === 'TAX SUMMARY') {
                break;
            }

            if (is_string($colA) && str_starts_with(trim($colA), 'Printed By')) {
                break;
            }

            $date = is_string($colA) || $colA instanceof \DateTimeInterface ? DataCleaner::normalizeDate($this->toStringOrNull($colA)) : null;

            if ($date !== null) {
                // New invoice header — finalize/stash the previous one first.
                if ($current !== null) {
                    $invoices[] = $current;
                }

                $documentNumber = DataCleaner::normalizeText($this->toStringOrNull($row[1] ?? null));
                $prefix = $documentNumber !== null ? strtoupper(explode('/', $documentNumber)[0] ?? '') : null;
                $type = $prefix !== null ? (self::PREFIX_TYPE[$prefix] ?? null) : null;

                if ($documentNumber === null) {
                    $warnings[] = 'Baris header tanpa DOCUMENT # ditemukan — dilewati.';
                    $current = null;

                    continue;
                }

                if ($type === null) {
                    $warnings[] = "Dokumen \"{$documentNumber}\": jenis dokumen tidak dikenali (bukan SI/KE atau TR/KE) — dilewati.";
                    $current = null;

                    continue;
                }

                $current = [
                    'document_number' => $documentNumber,
                    'type' => $type,
                    'date' => $date,
                    'customer_code' => DataCleaner::normalizeText($this->toStringOrNull($row[2] ?? null)) ?? '',
                    'customer_name' => DataCleaner::normalizeText($this->toStringOrNull($row[3] ?? null)) ?? '',
                    'header_disc' => DataCleaner::normalizeNumber($row[7] ?? null) ?? 0.0,
                    'header_tax' => DataCleaner::normalizeNumber($row[8] ?? null) ?? 0.0,
                    'header_amount' => DataCleaner::normalizeNumber($row[10] ?? null) ?? 0.0,
                    'reference_1' => DataCleaner::normalizeText($this->toStringOrNull($row[11] ?? null)),
                    'reference_2' => DataCleaner::normalizeText($this->toStringOrNull($row[12] ?? null)),
                    'items' => [],
                ];

                continue;
            }

            // Item row.
            if ($current === null) {
                $warnings[] = 'Baris item ditemukan sebelum ada baris header dokumen — dilewati.';

                continue;
            }

            $itemCode = DataCleaner::normalizeText($this->toStringOrNull($colA));
            $qty = DataCleaner::normalizeNumber($row[5] ?? null);
            $rate = DataCleaner::normalizeNumber($row[6] ?? null);
            $lineAmount = DataCleaner::normalizeNumber($row[10] ?? null);

            if ($itemCode === null || $qty === null || $rate === null || $lineAmount === null) {
                $warnings[] = "Dokumen \"{$current['document_number']}\": baris item dengan kolom wajib kosong — dilewati.";

                continue;
            }

            $current['items'][] = [
                'item_code' => $itemCode,
                'description' => DataCleaner::normalizeText($this->toStringOrNull($row[2] ?? null)) ?? $itemCode,
                'uom' => DataCleaner::normalizeText($this->toStringOrNull($row[4] ?? null)),
                'qty' => $qty,
                'rate' => $rate,
                'disc' => DataCleaner::normalizeNumber($row[7] ?? null) ?? 0.0,
                'tax' => DataCleaner::normalizeNumber($row[8] ?? null) ?? 0.0,
                'line_amount' => $lineAmount,
            ];
        }

        if ($current !== null) {
            $invoices[] = $current;
        }

        // Drop any header that ended up with no item rows at all — nothing to invoice.
        $withItems = [];
        foreach ($invoices as $invoice) {
            if ($invoice['items'] === []) {
                $warnings[] = "Dokumen \"{$invoice['document_number']}\": tidak punya baris item — dilewati.";

                continue;
            }

            $withItems[] = $invoice;
        }

        return ['invoices' => $withItems, 'warnings' => $warnings];
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (! (DataCleaner::blankToNull($cell) === null)) {
                return false;
            }
        }

        return true;
    }

    private function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('d/m/Y') : (string) $value;
    }
}
