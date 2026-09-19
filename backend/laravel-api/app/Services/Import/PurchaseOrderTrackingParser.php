<?php

namespace App\Services\Import;

/**
 * Parses the legacy "Purchase Order Tracking" export into one row per historical Purchase Order —
 * see PurchaseHistoryImportService for why each becomes a real PO with a single placeholder line
 * item (this file has a header total per PO but no item-level breakdown at all).
 *
 * Rows 1-4 preamble, row 5 the real header (PO DATE, PO NO, SUPPLIER NAME, AMOUNT, QUOTE NO.,
 * REQUEST BY, INVOICE DATE, SUPPLIER INVOICE NO., SUPPLIER DO NO., SUP. DO DATE, REQUISITION #,
 * AMOUNT BILLED, OUTSTD GRN, OUTSTD PO), data starts row 6. A real PO row always has both PO DATE
 * and PO NO populated; the file's own trailing summary rows ("TOTAL ORDER AMOUNT", "LESS CANCELLED
 * ORDER AMOUNT", "NET TOTAL AMOUNT") have both blank — detected structurally (blank identifying
 * columns), not by matching those exact labels, so a differently-worded summary row in another
 * export of this same shape is still skipped correctly.
 */
final class PurchaseOrderTrackingParser
{
    public const TITLE = 'PURCHASE ORDER TRACKING';

    private const DATA_START_ROW = 6;

    /**
     * @return array{rows: array<int, array{po_no: string, po_date: string, supplier_name: string, amount: float, quote_no: ?string, request_by: ?string, requisition_no: ?string, supplier_invoice_no: ?string, amount_billed: float, outstd_grn: float, outstd_po: float, has_grn: bool, grn_reference: ?string, grn_date: ?string}>, warnings: array<int, string>}
     */
    public function parse(array $rawRows): array
    {
        $dataRows = array_slice($rawRows, self::DATA_START_ROW - 1);

        $decimalStyle = DataCleaner::detectDecimalStyle(array_merge(
            array_column($dataRows, 3), array_column($dataRows, 11), array_column($dataRows, 12), array_column($dataRows, 13),
        ));

        $rows = [];
        $warnings = [];

        foreach ($dataRows as $row) {
            $poDate = DataCleaner::normalizeText($this->toStringOrNull($row[0] ?? null));
            $poNo = DataCleaner::normalizeText($this->toStringOrNull($row[1] ?? null));

            if ($poDate === null || $poNo === null) {
                continue; // blank row, or one of the file's own trailing summary rows
            }

            $date = DataCleaner::normalizeDate($poDate);

            if ($date === null) {
                $warnings[] = "Baris \"{$poNo}\": tanggal PO (\"{$poDate}\") tidak terbaca — dilewati.";

                continue;
            }

            $supplierName = DataCleaner::normalizeText($this->toStringOrNull($row[2] ?? null));

            if ($supplierName === null) {
                $warnings[] = "Baris \"{$poNo}\": nama supplier kosong — dilewati.";

                continue;
            }

            $amount = DataCleaner::normalizeNumber($row[3] ?? null, $decimalStyle) ?? 0.0;
            $grnReference = DataCleaner::normalizeText($this->toStringOrNull($row[8] ?? null));
            $grnDateRaw = DataCleaner::normalizeText($this->toStringOrNull($row[9] ?? null));
            $grnDate = $grnDateRaw !== null ? DataCleaner::normalizeDate($grnDateRaw) : null;

            $rows[] = [
                'po_no' => $poNo,
                'po_date' => $date,
                'supplier_name' => $supplierName,
                'amount' => $amount,
                'quote_no' => DataCleaner::normalizeText($this->toStringOrNull($row[4] ?? null)),
                'request_by' => DataCleaner::normalizeText($this->toStringOrNull($row[5] ?? null)),
                'requisition_no' => DataCleaner::normalizeText($this->toStringOrNull($row[10] ?? null)),
                'supplier_invoice_no' => DataCleaner::normalizeText($this->toStringOrNull($row[7] ?? null)),
                'amount_billed' => DataCleaner::normalizeNumber($row[11] ?? null, $decimalStyle) ?? 0.0,
                'outstd_grn' => DataCleaner::normalizeNumber($row[12] ?? null, $decimalStyle) ?? 0.0,
                'outstd_po' => DataCleaner::normalizeNumber($row[13] ?? null, $decimalStyle) ?? 0.0,
                // Both Supplier DO No. and Sup. DO Date must be present to count as "received" —
                // a date with no reference (or vice versa) is an incomplete/malformed source row,
                // not treated as a receipt.
                'has_grn' => $grnReference !== null && $grnDate !== null,
                'grn_reference' => $grnReference,
                'grn_date' => $grnDate,
            ];
        }

        return ['rows' => $rows, 'warnings' => $warnings];
    }

    private function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('d/m/Y') : (string) $value;
    }
}
