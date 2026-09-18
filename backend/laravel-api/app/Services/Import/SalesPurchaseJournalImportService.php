<?php

namespace App\Services\Import;

use App\Enums\AccountType;
use App\Enums\ImportBatchStatus;
use App\Imports\StreamedRowImport;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\ImportBatch;
use App\Models\JournalEntry;
use App\Services\Import\Concerns\ParsesLegacyLedgerExport;
use App\Services\JournalEntryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Smart, one-click import for Journal List's Sales Journal Invoice/Credit
 * Note and Purchase Journal Invoice/Return sub-tabs — a legacy double-entry
 * export (Transaction/Date/Ref.1#/Particulars/Debit/Credit/Tax Code/Salesman
 * Code/Department Code/Project Code/Branch Code, same 11-column shape for
 * all 4 sub-types).
 *
 * Unlike CashBookImportService (which reconstructs real PaymentEntry/
 * ReceiptEntry documents — both freestanding, no required source document),
 * none of Invoice/PurchaseInvoice/CreditNote/PurchaseReturn can be built this
 * way: Invoice/PurchaseInvoice items only ever come from an existing
 * Delivery/Goods Receipt, and CreditNote/PurchaseReturn need real
 * invoice-item rows to credit/return against — none of that exists in a
 * GL-only export (account code + amount, no Item/SKU, no source-document
 * link). Per explicit user decision, this importer follows the same
 * precedent PrintLedgerImportService already set for the same reason: it
 * posts a real, balanced JournalEntry straight to the General Ledger — one
 * per legacy transaction group — instead of fabricating a business document.
 * These entries will not appear in the Sales/Purchase Invoice or Credit
 * Note/Return module lists; they exist purely as historical GL postings.
 *
 * The real sample files are ~170k and ~25k rows — far larger than any other
 * importer here was built for — so this class streams the file row-by-row
 * via StreamedRowImport (Laravel-Excel's OnEachRow+WithChunkReading) instead
 * of ImportFileReader::readRaw(), which loads the entire sheet into memory.
 * A transaction group is processed and posted the moment it's complete, kept
 * to O(1) memory regardless of file size.
 *
 * Reuses ParsesLegacyLedgerExport's direction-agnostic mapColumns()/
 * normalize() only — not parseLedgerRows()/groupLedgerRows(), both built for
 * a fully-materialized array.
 */
final class SalesPurchaseJournalImportService
{
    use ParsesLegacyLedgerExport;

    private const AMOUNT_EPSILON = 0.01;

    private const CHECKSUM_EPSILON = 1.0;

    private const PEEK_ROWS = 20;

    /** Legacy account codes (e.g. "112.01.01") don't match this system's own Chart of Accounts codes (e.g. "1200") at all — confirmed against real production data. Same threshold as TrialBalanceImportService's own account-name fallback. */
    private const ACCOUNT_NAME_MATCH_THRESHOLD = 70.0;

    public const SUSPENSE_ACCOUNT_CODE = 'JOURNAL-IMPORT-SUSPENSE';

    private const GROUP_LABELS = [
        'sales_invoice' => 'Sales Journal',
        'sales_credit_note' => 'Sales Return Journal',
        'purchase_invoice' => 'Purchase Journal',
        'purchase_return' => 'Purchase Return Journal',
    ];

    private const DESCRIPTIONS = [
        'sales_invoice' => 'Sales Journal Invoice',
        'sales_credit_note' => 'Sales Journal Credit Note',
        'purchase_invoice' => 'Purchase Journal Invoice',
        'purchase_return' => 'Purchase Journal Return',
    ];

    public function __construct(protected JournalEntryService $journalEntryService) {}

    public function groupLabel(string $view): string
    {
        return self::GROUP_LABELS[$view] ?? '';
    }

    /** @return ImportFieldDefinition[] */
    private function fieldDefinitions(): array
    {
        return [
            new ImportFieldDefinition('document_number', 'Transaction', 'string', required: true, synonyms: ['document #', 'trans #', 'no transaksi']),
            new ImportFieldDefinition('date', 'Date', 'date', required: true, synonyms: ['tanggal', 'tgl']),
            new ImportFieldDefinition('secondary_reference', 'Ref. 1 #', 'string', synonyms: ['ref 1 #', 'reference', 'no referensi']),
            new ImportFieldDefinition('particulars', 'Particulars', 'string', synonyms: ['keterangan', 'description', 'uraian']),
            new ImportFieldDefinition('debit', 'Debit', 'number', required: true, synonyms: ['dr', 'debet']),
            new ImportFieldDefinition('credit', 'Credit', 'number', required: true, synonyms: ['cr', 'kredit']),
            new ImportFieldDefinition('tax_code', 'Tax Code', 'string', synonyms: ['kode pajak']),
            new ImportFieldDefinition('salesman_code', 'Salesman Code', 'string', synonyms: ['kode salesman', 'sales code']),
            new ImportFieldDefinition('department_code', 'Department Code', 'string', synonyms: ['kode departemen']),
            new ImportFieldDefinition('project_code', 'Project Code', 'string', synonyms: ['kode proyek']),
            new ImportFieldDefinition('branch_code', 'Branch Code', 'string', synonyms: ['kode cabang']),
        ];
    }

    /**
     * Reads only the first ~20 rows (StreamedRowImport with a matching chunk size, aborted right
     * after — Laravel-Excel's chunk reading uses a row-range read filter per chunk, so this never
     * touches the rest of the file on disk) to detect the header/section-label row and the file's
     * decimal style. HeaderDetector itself only ever scans its own first 15 rows regardless of how
     * many are handed to it, so this small peek is always enough.
     *
     * @return array{column_index: array<string,int>, data_start_row: int, label: ?string, decimal_style: string}
     */
    public function peek(string $absolutePath): array
    {
        $peekRows = [];

        try {
            Excel::import(new StreamedRowImport(function (array $row, int $index) use (&$peekRows) {
                $peekRows[$index - 1] = $row;
                if ($index >= self::PEEK_ROWS) {
                    throw new StopStreamingException();
                }
            }, chunkSize: self::PEEK_ROWS), $absolutePath);
        } catch (StopStreamingException) {
            // Expected — we only wanted the first chunk.
        }

        ksort($peekRows);
        $peekRows = array_values($peekRows);

        $fields = $this->fieldDefinitions();
        $headerSettings = HeaderDetector::detect($peekRows, $fields);
        $headerRow = $peekRows[$headerSettings['header_row'] - 1] ?? [];
        $columnIndex = $this->mapColumns($headerRow, $fields);

        $dataStartRow = $headerSettings['data_start_row'];
        $label = null;

        if (isset($columnIndex['document_number'])) {
            $candidate = $peekRows[$dataStartRow - 1] ?? [];

            if ($this->looksLikeGroupLabelRow($candidate, $columnIndex)) {
                $label = DataCleaner::normalizeText((string) ($candidate[$columnIndex['document_number']] ?? ''));
                $dataStartRow++;
            }
        }

        $decimalValues = [];
        foreach (['debit', 'credit'] as $field) {
            if (! isset($columnIndex[$field])) {
                continue;
            }
            foreach (array_slice($peekRows, $dataStartRow - 1) as $row) {
                $decimalValues[] = $row[$columnIndex[$field]] ?? null;
            }
        }

        return [
            'column_index' => $columnIndex,
            'data_start_row' => $dataStartRow,
            'label' => $label,
            'decimal_style' => DataCleaner::detectDecimalStyle($decimalValues),
        ];
    }

    public function import(ImportBatch $batch): void
    {
        $view = $batch->mapping['view'] ?? null;
        $duplicatePolicy = $batch->write_mode ?? 'skip';

        if (! isset(self::GROUP_LABELS[$view])) {
            $batch->update(['status' => ImportBatchStatus::FAILED, 'failure_reason' => 'Journal Type tidak dikenali untuk batch ini.']);

            return;
        }

        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);
        $absolutePath = Storage::disk($batch->disk)->path($batch->file_path);

        $peeked = $this->peek($absolutePath);
        $columnIndex = $peeked['column_index'];

        $requiredLabels = ['document_number' => 'Transaction', 'date' => 'Date', 'debit' => 'Debit', 'credit' => 'Credit'];
        $missing = array_values(array_diff_key($requiredLabels, $columnIndex));

        if ($missing !== []) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => 'Kolom wajib tidak terdeteksi di file ini: '.implode(', ', $missing).'.',
            ]);

            return;
        }

        $batch->update(['status' => ImportBatchStatus::PROCESSING, 'started_at' => now()]);

        $accountsByCode = ChartOfAccount::query()->where('is_active', true)->get()->keyBy('code');
        $allAccounts = $accountsByCode->values();
        $branchesByCode = Branch::query()->where('is_active', true)->get()->keyBy(fn (Branch $b) => strtoupper(trim($b->code)));

        $tally = [
            'success' => 0, 'failed' => 0, 'needsReview' => 0, 'report' => [],
            'totalDebit' => 0.0, 'totalCredit' => 0.0,
            'group' => [], 'groupTransaction' => null, 'discovered' => 0,
            'trailer' => null,
        ];

        $flush = function () use (&$tally, $accountsByCode, $allAccounts, $branchesByCode, $view, $duplicatePolicy, $batch) {
            if ($tally['group'] === []) {
                return;
            }

            $outcome = $this->processGroup($tally['groupTransaction'], $tally['group'], $accountsByCode, $allAccounts, $branchesByCode, $view, $duplicatePolicy, $batch);

            if ($outcome['status'] !== 'success') {
                $tally['report'][] = $outcome;
            }

            match ($outcome['status']) {
                'success' => $tally['success']++,
                'needs_review' => $tally['needsReview']++,
                default => $tally['failed']++,
            };

            $batch->increment('processed_rows');
            $tally['group'] = [];
        };

        Excel::import(new StreamedRowImport(function (array $row, int $index) use (&$tally, $columnIndex, $peeked, $flush, $batch) {
            if ($index < $peeked['data_start_row']) {
                return;
            }

            $rawTransaction = DataCleaner::normalizeText((string) ($row[$columnIndex['document_number']] ?? ''));

            if ($rawTransaction !== null && stripos($rawTransaction, 'Total For') === 0) {
                $flush();
                $tally['trailer'] = $row;

                return;
            }

            if ($rawTransaction !== null) {
                $flush();
                $tally['groupTransaction'] = $rawTransaction;
                $tally['discovered']++;

                // No upfront file-wide pre-scan (a full pass just to count/find duplicates was
                // measured at ~50s on the real 25k-row Purchase file alone — far too slow for a
                // synchronous upload request on the ~170k-row Sales file). total_rows instead
                // grows live as groups are discovered during this same streaming pass, so the
                // report dialog's progress bar still has a real, if slightly-ahead-of-processed,
                // denominator; the exact final count is set once the file finishes below.
                if ($tally['discovered'] % 500 === 0) {
                    $batch->update(['total_rows' => $tally['discovered']]);
                }
            }

            // Rows before the file's first real Transaction number (malformed/hand-edited files
            // only — never happens in a real export) have nothing to group under; drop them
            // rather than risk processGroup() being called with a null document number.
            if ($tally['groupTransaction'] === null) {
                return;
            }

            $parsed = $this->parseRow($row, $columnIndex, $peeked['decimal_style']);

            if ($parsed === null) {
                return;
            }

            $tally['group'][] = $parsed;
            $tally['totalDebit'] += $parsed['debit'];
            $tally['totalCredit'] += $parsed['credit'];
        }), $absolutePath);

        $flush();

        if ($tally['trailer'] !== null) {
            $expectedDebit = DataCleaner::normalizeNumber($tally['trailer'][$columnIndex['debit']] ?? null, $peeked['decimal_style']);
            $expectedCredit = DataCleaner::normalizeNumber($tally['trailer'][$columnIndex['credit']] ?? null, $peeked['decimal_style']);

            if ($expectedDebit !== null && $expectedCredit !== null
                && (abs($tally['totalDebit'] - $expectedDebit) > self::CHECKSUM_EPSILON || abs($tally['totalCredit'] - $expectedCredit) > self::CHECKSUM_EPSILON)) {
                $tally['report'][] = [
                    'document_number' => 'CHECKSUM',
                    'status' => 'needs_review',
                    'reason' => sprintf(
                        'Total hasil parsing (Debit Rp %s, Credit Rp %s) tidak cocok dengan baris Total di file (Debit Rp %s, Credit Rp %s).',
                        number_format($tally['totalDebit'], 0, ',', '.'), number_format($tally['totalCredit'], 0, ',', '.'),
                        number_format($expectedDebit, 0, ',', '.'), number_format($expectedCredit, 0, ',', '.'),
                    ),
                ];
                $tally['needsReview']++;
            }
        }

        $batch->update([
            'total_rows' => $tally['success'] + $tally['failed'] + $tally['needsReview'],
            'success_rows' => $tally['success'],
            'failed_rows' => $tally['failed'],
            'preview_summary' => ['needs_review_rows' => $tally['needsReview'], 'vouchers' => $tally['report']],
            'status' => ImportBatchStatus::COMPLETED,
        ]);
    }

    /**
     * A group-label row has a value in the Transaction column but is blank everywhere a real data
     * row must have a value (Date, Debit, Credit) — mirrors CashBookImportService's own
     * looksLikeGroupLabelRow() (duplicated rather than shared: two private, near-identical 6-line
     * consumers don't yet justify promoting this into the shared trait).
     */
    private function looksLikeGroupLabelRow(array $row, array $columnIndex): bool
    {
        $transaction = DataCleaner::blankToNull($row[$columnIndex['document_number']] ?? null);
        $date = isset($columnIndex['date']) ? DataCleaner::blankToNull($row[$columnIndex['date']] ?? null) : null;
        $debit = isset($columnIndex['debit']) ? DataCleaner::blankToNull($row[$columnIndex['debit']] ?? null) : null;
        $credit = isset($columnIndex['credit']) ? DataCleaner::blankToNull($row[$columnIndex['credit']] ?? null) : null;

        return $transaction !== null && $date === null && $debit === null && $credit === null;
    }

    /** @return array{date: string, account_code: ?string, account_name: ?string, remark: string, secondary_reference: ?string, tax_code: ?string, salesman_code: ?string, branch_code: ?string, debit: float, credit: float}|null */
    private function parseRow(array $raw, array $columnIndex, string $decimalStyle): ?array
    {
        $get = fn (string $field) => isset($columnIndex[$field]) ? ($raw[$columnIndex[$field]] ?? null) : null;

        $date = $this->parseRowDate($get('date'));
        $debit = DataCleaner::normalizeNumber($get('debit'), $decimalStyle) ?? 0.0;
        $credit = DataCleaner::normalizeNumber($get('credit'), $decimalStyle) ?? 0.0;

        // Blank separator rows and the file's own trailer both fall out here — structural noise,
        // not a real journal line (the trailer is handled separately by the caller before this
        // is ever reached, since it has no date).
        if ($date === null || ($debit < self::AMOUNT_EPSILON && $credit < self::AMOUNT_EPSILON)) {
            return null;
        }

        [$accountCode, $accountName, $remark] = $this->splitParticulars((string) ($get('particulars') ?? ''));

        return [
            'date' => $date,
            'account_code' => $accountCode,
            'account_name' => $accountName,
            'remark' => $remark,
            'secondary_reference' => DataCleaner::normalizeText($this->rawToStringOrNull($get('secondary_reference'))),
            'tax_code' => DataCleaner::normalizeText($this->rawToStringOrNull($get('tax_code'))),
            'salesman_code' => DataCleaner::normalizeText($this->rawToStringOrNull($get('salesman_code'))),
            'branch_code' => DataCleaner::normalizeText($this->rawToStringOrNull($get('branch_code'))),
            'debit' => $debit,
            'credit' => $credit,
        ];
    }

    private function parseRowDate(mixed $raw): ?string
    {
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        return DataCleaner::normalizeDate($raw === null ? null : (string) $raw);
    }

    /**
     * "{code} - {name} - [{remark}]" (see SalesJournalExport::particulars()/JournalListExport's
     * own convention) — code, account name, and the bracketed remark. The name segment (unlike
     * CashBookImportService::splitParticulars(), which only needs code+remark since it resolves
     * accounts by exact code alone) is what lets processGroup() fall back to a fuzzy name match
     * when the legacy code doesn't exist in this system's own Chart of Accounts at all.
     *
     * @return array{0: ?string, 1: ?string, 2: string}
     */
    private function splitParticulars(string $particulars): array
    {
        if (preg_match('/^(\S+)\s*-\s*(.*?)\s*-\s*\[(.*)\]\s*$/', trim($particulars), $matches) !== 1) {
            return [null, null, trim($particulars)];
        }

        return [$matches[1], $matches[2] !== '' ? $matches[2] : null, trim($matches[3])];
    }

    private function rawToStringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * @param  array<int, array>  $rows
     * @return array{document_number: string, status: string, reason: ?string}
     */
    private function processGroup(string $documentNumber, array $rows, Collection $accountsByCode, Collection $allAccounts, Collection $branchesByCode, string $view, string $duplicatePolicy, ImportBatch $batch): array
    {
        $base = ['document_number' => $documentNumber];

        $alreadyImported = JournalEntry::query()->where('source_document_number', $documentNumber)->exists();

        if ($alreadyImported && $duplicatePolicy === 'skip') {
            return [...$base, 'status' => 'needs_review', 'reason' => 'Nomor dokumen ini sudah pernah diimpor sebelumnya — dilewati.'];
        }

        $totalDebit = round(array_sum(array_column($rows, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($rows, 'credit')), 2);

        if (abs($totalDebit - $totalCredit) > self::AMOUNT_EPSILON) {
            return [...$base, 'status' => 'failed', 'reason' => sprintf(
                'Debit (Rp %s) tidak sama dengan Credit (Rp %s) — baris tidak balance.',
                number_format($totalDebit, 0, ',', '.'), number_format($totalCredit, 0, ',', '.'),
            )];
        }

        $reviewNotes = $alreadyImported ? ['Nomor dokumen ini sudah pernah diimpor sebelumnya — dibuat lagi sebagai entry tambahan.'] : [];
        $lines = [];
        $date = null;

        foreach ($rows as $row) {
            $date ??= $row['date'];

            $account = $row['account_code'] !== null ? $accountsByCode->get($row['account_code']) : null;

            // Legacy long-format codes (e.g. "112.01.01") routinely don't exist in this system's
            // own Chart of Accounts (short-format, e.g. "1200") at all — confirmed against real
            // production data. Before giving up to suspense, try a fuzzy match on the account NAME
            // segment of Particulars (splitParticulars()'s 2nd capture group), same primitive
            // TrialBalanceImportService's own account resolution uses.
            if ($account === null && $row['account_name'] !== null) {
                $fuzzyAccount = $this->matchLedgerPartyByName($row['account_name'], $allAccounts, fn (ChartOfAccount $a) => $a->name, self::ACCOUNT_NAME_MATCH_THRESHOLD);

                if ($fuzzyAccount !== null) {
                    $account = $fuzzyAccount;
                    $reviewNotes[] = "Kode akun \"{$row['account_code']}\" ({$row['account_name']}) tidak ditemukan — dicocokkan otomatis by nama ke \"{$account->code} - {$account->name}\", mohon verifikasi.";
                }
            }

            if ($account === null) {
                $account = $this->findOrCreateSuspenseAccount();
                $reviewNotes[] = "Kode akun \"{$row['account_code']}\" ({$row['account_name']}) tidak ditemukan di Chart of Accounts (exact maupun fuzzy) — dialihkan ke akun suspense.";
            }

            $branch = $row['branch_code'] !== null ? $branchesByCode->get(strtoupper(trim($row['branch_code']))) : null;

            $extras = [];
            if ($row['tax_code'] !== null) {
                $extras[] = "Tax: {$row['tax_code']}";
            }
            if ($row['salesman_code'] !== null) {
                $extras[] = "Salesman: {$row['salesman_code']}";
            }

            $description = trim($row['remark'].($extras !== [] ? ' ['.implode(', ', $extras).']' : ''));

            $lines[] = [
                'chart_of_account_id' => $account->id,
                'branch_id' => $branch?->id,
                'debit' => $row['debit'],
                'credit' => $row['credit'],
                'description' => $description !== '' ? $description : null,
            ];
        }

        if ($date === null) {
            return [...$base, 'status' => 'failed', 'reason' => 'Tanggal tidak terbaca pada grup ini.'];
        }

        $ref1 = collect($rows)->pluck('secondary_reference')->filter()->first();
        $descriptionPrefix = self::DESCRIPTIONS[$view] ?? 'Journal List';
        $description = trim("{$descriptionPrefix} import: {$documentNumber}".($ref1 !== null ? " (Ref: {$ref1})" : ''));

        try {
            return DB::transaction(function () use ($base, $lines, $date, $documentNumber, $description, $reviewNotes, $batch) {
                $entry = $this->journalEntryService->create([
                    'posting_date' => $date,
                    'description' => $description,
                    // Non-null reference_type is what keeps Documentable::requiresApproval() false
                    // for this "manual" entry (see JournalEntry::requiresApproval()) — same
                    // convention PrintLedgerImportService already relies on for the same reason.
                    'reference_type' => $batch->getMorphClass(),
                    'reference_id' => $batch->id,
                    'source_document_number' => $documentNumber,
                    'lines' => $lines,
                ]);
                $this->journalEntryService->post($entry);

                return [...$base, 'status' => $reviewNotes === [] ? 'success' : 'needs_review', 'reason' => $reviewNotes === [] ? null : implode(' ', $reviewNotes)];
            });
        } catch (Throwable $e) {
            return [...$base, 'status' => 'failed', 'reason' => $e->getMessage()];
        }
    }

    /**
     * Mirrors PrintLedgerImportService::findOrCreateSuspenseAccount() but its own distinct
     * account — this one absorbs an unrecognized account code hit *within* an otherwise-normal
     * transaction group, not a file-wide opening-balance plug; different semantics, different
     * account.
     */
    private function findOrCreateSuspenseAccount(): ChartOfAccount
    {
        return ChartOfAccount::query()->firstOrCreate(
            ['code' => self::SUSPENSE_ACCOUNT_CODE],
            ['name' => 'Journal Import Suspense (Account Not Found)', 'account_type' => AccountType::EQUITY, 'is_active' => true],
        );
    }
}
