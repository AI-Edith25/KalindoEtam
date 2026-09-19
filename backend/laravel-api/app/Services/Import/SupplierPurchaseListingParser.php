<?php

namespace App\Services\Import;

/**
 * Parses the legacy "Supplier Purchase Listing" export into one row per historical Supplier
 * Invoice document — see PurchaseHistoryImportService for why each becomes a real Purchase Order
 * with a single placeholder line item (this file has a document total but no item-level breakdown
 * at all, same shape problem PurchaseOrderTrackingParser already solves for Purchase Order
 * Tracking — the two are still tagged with distinct import_source_type values downstream, never
 * conflated, since one represents a historical supplier invoice and the other a historical PO).
 *
 * Rows 1-4 preamble, row 5 the real header (DATE, DOCUMENT #, REFERENCE #, REFERENCE 2 #,
 * SUPPLIER CODE, SUPPLIER NAME, TYPE, AMOUNT (EXCLUDE TAX), TAX, AMOUNT (INCLUDE TAX)), data
 * starts row 6. `DOCUMENT #` is sometimes a bare number (e.g. `1312`) in the real file — always
 * cast to string, never rejected as non-text.
 */
final class SupplierPurchaseListingParser
{
    public const TITLE = 'SUPPLIER PURCHASE LISTING';

    private const DATA_START_ROW = 6;

    /**
     * @return array{rows: array<int, array{document_number: string, date: string, supplier_name: string, amount: float, supplier_code: ?string, reference_no: ?string, reference_no_2: ?string}>, warnings: array<int, string>}
     */
    public function parse(array $rawRows): array
    {
        $dataRows = array_slice($rawRows, self::DATA_START_ROW - 1);

        $decimalStyle = DataCleaner::detectDecimalStyle(array_column($dataRows, 9));

        $flatRows = [];
        $warnings = [];

        foreach ($dataRows as $row) {
            $dateRaw = DataCleaner::normalizeText($this->toStringOrNull($row[0] ?? null));
            $documentNumber = DataCleaner::normalizeText($this->toStringOrNull($row[1] ?? null));
            $supplierName = DataCleaner::normalizeText($this->toStringOrNull($row[5] ?? null));
            $amount = DataCleaner::normalizeNumber($row[9] ?? null, $decimalStyle);

            if ($dateRaw === null && $documentNumber === null && $supplierName === null && $amount === null) {
                continue; // blank separator row
            }

            $missing = array_filter([
                $documentNumber === null ? 'DOCUMENT #' : null,
                $supplierName === null ? 'SUPPLIER NAME' : null,
                $dateRaw === null ? 'DATE' : null,
                $amount === null ? 'AMOUNT (INCLUDE TAX)' : null,
            ]);

            if ($missing !== []) {
                $label = $documentNumber ?? '(tanpa nomor dokumen)';
                $warnings[] = "Baris \"{$label}\": kolom wajib kosong (".implode(', ', $missing).') — dilewati.';

                continue;
            }

            $date = DataCleaner::normalizeDate($dateRaw);

            if ($date === null) {
                $warnings[] = "Baris \"{$documentNumber}\": tanggal (\"{$dateRaw}\") tidak terbaca — dilewati.";

                continue;
            }

            $flatRows[] = [
                'document_number' => $documentNumber,
                'date' => $date,
                'supplier_name' => $supplierName,
                'amount' => $amount,
                'supplier_code' => DataCleaner::normalizeText($this->toStringOrNull($row[4] ?? null)),
                'reference_no' => DataCleaner::normalizeText($this->toStringOrNull($row[2] ?? null)),
                'reference_no_2' => DataCleaner::normalizeText($this->toStringOrNull($row[3] ?? null)),
            ];
        }

        // One row in Purchase Orders per Document No, even if the same document number repeats
        // across several lines in the source file — sum the amount, keep the first row's other
        // fields (same document, so date/supplier/reference are expected to agree).
        $grouped = [];
        foreach ($flatRows as $row) {
            $key = $row['document_number'];

            if (! isset($grouped[$key])) {
                $grouped[$key] = $row;
            } else {
                $grouped[$key]['amount'] += $row['amount'];
            }
        }

        return ['rows' => array_values($grouped), 'warnings' => $warnings];
    }

    private function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('d/m/Y') : (string) $value;
    }
}
