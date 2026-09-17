<?php

namespace App\Services\Import\Concerns;

use App\Models\ChartOfAccount;
use App\Services\Import\DataCleaner;
use App\Services\Import\ImportFieldDefinition;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

/**
 * Shared parsing/matching primitives for a "legacy double-entry ledger
 * export" import (Payment Voucher Listing, Official Receipt Listing, and
 * any future one of this shape): 4 report-title rows to skip, a header row
 * whose exact column names vary per export, and several DEBIT/CREDIT rows
 * per DOCUMENT # that must be grouped into one voucher.
 *
 * What's shared here is genuinely direction-agnostic: file reading, header
 * detection, column mapping, row normalization/grouping, and fuzzy
 * name-matching. What's NOT shared — which side of the entry is the cash
 * leg, what model the "other" leg resolves to (Supplier+AccountsPayable vs.
 * Customer+AccountsReceivable), and how the resulting document gets
 * created/submitted — stays in each concrete *ImportService, since forcing
 * that into a common template method would cost more than it saves for two
 * consumers with genuinely different domain models (PaymentEntry has three
 * payment_type variants and no required payment_method; ReceiptEntry has
 * one type and payment_method is required).
 */
trait ParsesLegacyLedgerExport
{
    /**
     * The 9 columns every consumer of this trait needs, keyed the same way
     * regardless of what the source file actually calls them — e.g. Payment
     * Voucher's "CHEQUE DATE" and Official Receipt's "COLLECTION DATE" are
     * both just this shared "secondary date" column.
     *
     * @return ImportFieldDefinition[]
     */
    protected function ledgerFieldDefinitions(): array
    {
        return [
            new ImportFieldDefinition('document_number', 'Document #', 'string', required: true, synonyms: [
                'no dokumen', 'nomor dokumen', 'voucher no', 'voucher number', 'no voucher', 'doc no', 'pv no', 'or no',
            ]),
            new ImportFieldDefinition('date', 'Date', 'date', required: true, synonyms: ['tanggal', 'tgl', 'payment date', 'voucher date', 'receipt date']),
            new ImportFieldDefinition('secondary_date', 'Cheque Date', 'date', synonyms: ['tanggal cek', 'giro date', 'collection date', 'tanggal collection', 'tanggal penagihan']),
            new ImportFieldDefinition('secondary_reference', 'Cheque #', 'string', synonyms: ['no cek', 'nomor cek', 'check number', 'no giro', 'giro number']),
            new ImportFieldDefinition('account', 'Account', 'string', synonyms: ['akun', 'kode akun', 'account code', 'coa', 'no akun']),
            new ImportFieldDefinition('sl_code', 'SL Code', 'string', synonyms: ['kode supplier', 'kode customer', 'supplier code', 'customer code', 'sl no', 'subsidiary ledger']),
            new ImportFieldDefinition('particulars', 'Particulars', 'string', synonyms: ['keterangan', 'description', 'uraian', 'narasi']),
            new ImportFieldDefinition('debit', 'Debit', 'number', required: true, synonyms: ['dr', 'debet']),
            new ImportFieldDefinition('credit', 'Credit', 'number', required: true, synonyms: ['cr', 'kredit']),
        ];
    }

    /**
     * Two-pass, order-independent header mapping: exact-normalized matches claim their field
     * first regardless of column order (so a real "DEBIT" column always wins its field even if
     * a "C.DEBIT" — currency-converted equivalent — column sits earlier in the file), then
     * fuzzy (>=70%) fills in whatever's left. Mirrors ImportBatchService's exactMapping()/
     * suggestMapping() split, just index- rather than header-string-keyed (this module has no
     * per-column mapping UI to key by header text).
     *
     * @param  ImportFieldDefinition[]  $fields
     * @return array<string, int> field name => column index
     */
    protected function mapColumns(array $headerRow, array $fields): array
    {
        $mapping = [];
        $claimed = [];
        $unclaimed = [];

        foreach ($headerRow as $index => $headerText) {
            $normalizedHeader = $this->normalize((string) $headerText);
            if ($normalizedHeader === '') {
                continue;
            }

            $matched = null;
            foreach ($fields as $field) {
                if (in_array($field->name, $claimed, true)) {
                    continue;
                }

                foreach ([$field->name, $field->label, ...$field->synonyms] as $candidate) {
                    if ($this->normalize($candidate) === $normalizedHeader) {
                        $matched = $field->name;
                        break 2;
                    }
                }
            }

            if ($matched !== null) {
                $mapping[$matched] = $index;
                $claimed[] = $matched;
            } else {
                $unclaimed[$index] = $normalizedHeader;
            }
        }

        foreach ($unclaimed as $index => $normalizedHeader) {
            $best = null;
            $bestScore = 0.0;

            foreach ($fields as $field) {
                if (in_array($field->name, $claimed, true)) {
                    continue;
                }

                foreach ([$field->name, $field->label, ...$field->synonyms] as $candidate) {
                    similar_text($normalizedHeader, $this->normalize($candidate), $percent);
                    if ($percent > $bestScore) {
                        $bestScore = $percent;
                        $best = $field->name;
                    }
                }
            }

            if ($best !== null && $bestScore >= 70.0) {
                $mapping[$best] = $index;
                $claimed[] = $best;
            }
        }

        return $mapping;
    }

    protected function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)) ?? '');
    }

    /** @return array<int, array{document_number: string, date: ?string, account: ?string, sl_code: ?string, particulars: ?string, secondary_reference: ?string, debit: float, credit: float}> */
    protected function parseLedgerRows(array $dataRows, array $columnIndex): array
    {
        $decimalValues = [];
        foreach (['debit', 'credit'] as $field) {
            if (! isset($columnIndex[$field])) {
                continue;
            }
            foreach ($dataRows as $row) {
                $decimalValues[] = $row[$columnIndex[$field]] ?? null;
            }
        }
        $decimalStyle = DataCleaner::detectDecimalStyle($decimalValues);

        $parsed = [];
        $lastDocumentNumber = null;

        foreach ($dataRows as $raw) {
            $get = fn (string $field) => isset($columnIndex[$field]) ? ($raw[$columnIndex[$field]] ?? null) : null;

            // A legacy export sometimes only prints the DOCUMENT # once per voucher, leaving
            // continuation rows blank — carry the last seen number forward rather than dropping
            // those rows out of their group.
            $documentNumber = DataCleaner::normalizeText($this->toStringOrNull($get('document_number')));
            $documentNumber ??= $lastDocumentNumber;
            if ($documentNumber !== null) {
                $lastDocumentNumber = $documentNumber;
            }

            $debit = DataCleaner::normalizeNumber($get('debit'), $decimalStyle) ?? 0.0;
            $credit = DataCleaner::normalizeNumber($get('credit'), $decimalStyle) ?? 0.0;

            // Blank separator/subtotal rows carry no document # or no amount at all — structural
            // noise, not a real journal line.
            if ($documentNumber === null || ($debit < self::AMOUNT_EPSILON && $credit < self::AMOUNT_EPSILON)) {
                continue;
            }

            $parsed[] = [
                'document_number' => $documentNumber,
                'date' => $this->parseLedgerDate($get('date')),
                'account' => DataCleaner::normalizeText($this->toStringOrNull($get('account'))),
                'sl_code' => DataCleaner::normalizeText($this->toStringOrNull($get('sl_code'))),
                'particulars' => DataCleaner::normalizeText($this->toStringOrNull($get('particulars'))),
                'secondary_reference' => DataCleaner::normalizeText($this->toStringOrNull($get('secondary_reference'))),
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        return $parsed;
    }

    protected function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }

    /** Handles a Carbon/DateTime cell (PhpSpreadsheet, date-formatted), a raw Excel serial number, or plain text — DataCleaner::normalizeDate() alone only covers the last of these. */
    protected function parseLedgerDate(mixed $raw): ?string
    {
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        if (is_numeric($raw) && (float) $raw > 20000) {
            try {
                return Date::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (Throwable) {
                // fall through to text parsing
            }
        }

        return DataCleaner::normalizeDate($raw === null ? null : (string) $raw);
    }

    /** @return array<string, array<int, array>> document_number => rows */
    protected function groupLedgerRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['document_number']][] = $row;
        }

        return $groups;
    }

    /** Formatted "Debit (Rp X) tidak sama dengan Credit (Rp Y)" message, or null if the group balances within AMOUNT_EPSILON. */
    protected function ledgerBalanceMismatch(array $rows): ?string
    {
        $totalDebit = round(array_sum(array_column($rows, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($rows, 'credit')), 2);

        if (abs($totalDebit - $totalCredit) <= self::AMOUNT_EPSILON) {
            return null;
        }

        return sprintf(
            'Debit (Rp %s) tidak sama dengan Credit (Rp %s) — baris tidak balance.',
            number_format($totalDebit, 0, ',', '.'),
            number_format($totalCredit, 0, ',', '.'),
        );
    }

    /**
     * Splits a voucher's rows into "the cash/bank leg(s)" and "the other party's leg(s)" by
     * which side of the entry is non-zero — $cashSide is 'credit' for Payment Voucher (money
     * leaves cash on the credit side) or 'debit' for Official Receipt (money arrives on the
     * debit side). A row with both sides zero can't happen (parseLedgerRows() already drops
     * those); a row with both sides non-zero satisfies neither filter and simply vanishes from
     * both buckets, which surfaces naturally as a balance mismatch or a missing-cash-leg error.
     *
     * @return array{cash: array, party: array}
     */
    protected function classifyLedgerRows(array $rows, string $cashSide): array
    {
        $isCash = fn (array $r) => $cashSide === 'credit'
            ? ($r['credit'] > self::AMOUNT_EPSILON && $r['debit'] <= self::AMOUNT_EPSILON)
            : ($r['debit'] > self::AMOUNT_EPSILON && $r['credit'] <= self::AMOUNT_EPSILON);

        return [
            'cash' => array_values(array_filter($rows, $isCash)),
            'party' => array_values(array_filter($rows, fn ($r) => ! $isCash($r))),
        ];
    }

    /** @return array{0: ?ChartOfAccount, 1: bool} the account (or null if none exist at all), and whether it was a confident match vs. a default guess */
    protected function resolveLedgerCashAccount(array $cashRow, Collection $cashAccounts, float $threshold = 40.0): array
    {
        if ($cashAccounts->isEmpty()) {
            return [null, false];
        }

        if ($cashAccounts->count() === 1) {
            return [$cashAccounts->first(), false];
        }

        $needle = trim(($cashRow['particulars'] ?? '').' '.($cashRow['account'] ?? '').' '.($cashRow['secondary_reference'] ?? ''));
        $normalizedNeedle = $this->normalizeForMatch($needle);

        $best = null;
        $bestScore = 0.0;

        foreach ($cashAccounts as $account) {
            similar_text($normalizedNeedle, $this->normalizeForMatch($account->name), $percent);
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $account;
            }
        }

        return $best !== null && $bestScore >= $threshold
            ? [$best, false]
            : [$cashAccounts->first(), true];
    }

    /**
     * Tries the full text and each comma-separated segment against every candidate's name —
     * legacy PARTICULARS embeds a supplier/customer name amid other free text in varying
     * positions (e.g. "HUTANG SUPPLIER, PT. Conch South Kalimantan Cement, BANK BCA 1312" or
     * "PIUTANG USAHA, FAKTA JAYA-(SMD), BANK BCA 1312"), so no single fixed slice is reliable
     * across files. $nameOf extracts the comparable name string from one candidate (e.g.
     * fn ($c) => $c->supplier_name).
     *
     * @template T
     *
     * @param  Collection<int, T>  $candidates
     * @param  \Closure(T): string  $nameOf
     * @return T|null
     */
    protected function matchLedgerPartyByName(string $text, Collection $candidates, \Closure $nameOf, float $threshold): mixed
    {
        if ($candidates->isEmpty() || trim($text) === '') {
            return null;
        }

        $segments = array_unique(array_filter(array_map('trim', [$text, ...explode(',', $text)]), fn ($s) => $s !== ''));

        $best = null;
        $bestScore = 0.0;

        foreach ($candidates as $candidate) {
            $normalizedName = $this->normalizeForMatch($nameOf($candidate));

            foreach ($segments as $segment) {
                similar_text($this->normalizeForMatch($segment), $normalizedName, $percent);
                if ($percent > $bestScore) {
                    $bestScore = $percent;
                    $best = $candidate;
                }
            }
        }

        return $bestScore >= $threshold ? $best : null;
    }

    /** Uppercases, strips common Indonesian legal-entity prefixes (PT/CV/UD/Tbk) and punctuation — raises match quality between e.g. "PT. Conch South Kalimantan Cement" and a PARTICULARS mention of the same name with different casing/punctuation. */
    protected function normalizeForMatch(string $value): string
    {
        $value = strtoupper($value);
        $value = preg_replace('/\b(PT|CV|UD|TBK)\b\.?/', '', $value) ?? $value;
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
