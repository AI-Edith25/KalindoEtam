<?php

namespace App\Services\Import;

use App\Enums\AccountType;
use App\Enums\ImportBatchStatus;
use App\Models\ChartOfAccount;
use App\Models\ImportBatch;
use App\Models\JournalEntry;
use App\Services\Import\Concerns\ParsesLegacyLedgerExport;
use App\Services\JournalEntryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Smart, one-click Trial Balance import — Trial Balance itself is a pure read model over
 * GeneralLedgerService::listAccounts() (docs/TRIAL_BALANCE_DESIGN.md §1), it has no writable state
 * of its own, so the only way this import can affect it is the same trick PrintLedgerImportService
 * already uses for its own opening-balance file: post one real, balanced JournalEntry and let the
 * existing General Ledger read model pick it up. One combined, multi-line entry for the whole
 * file — not one per account, which could never balance on its own (JournalEntryService::
 * assertHasEnoughLines()/assertBalanced() require >=2 lines and equal totals).
 *
 * The source file's own account codes (legacy long format, e.g. "101.01.01") don't match this
 * system's Chart of Accounts (short format, e.g. "1100") at all — confirmed against the real
 * attached xlsTrialBalance.xlsx. Unlike every other importer in this codebase (all exact-code-only
 * against an export THIS system itself produced), this one resolves accounts exact-code-first,
 * then falls back to a fuzzy match on the account NAME via ParsesLegacyLedgerExport::
 * matchLedgerPartyByName() — and an account that clears neither bar is excluded from the Journal
 * Entry entirely rather than guessed at (posting to a suspense account would misattribute a real
 * balance to no account in particular, which is fine for the *difference* left over after
 * exclusions — see below — but not for a whole account's own figure).
 *
 * posting_date is the period's own END date (read from row 3's "dd/mm/yyyy - dd/mm/yyyy" range),
 * not "the day before period start" the way PrintLedgerImportService's opening balance is dated —
 * that class's own figure is a true pre-period balance; this file's "YEAR TO DATE" columns are a
 * cumulative snapshot AS OF the period's end, so posting on that end date is what correctly rolls
 * into any later period's opening balance (GeneralLedgerRepository::openingTotalsByAccount()'s
 * `posting_date < date_from`) while still landing inside any range that spans it (`date_to`
 * inclusive, `periodTotalsByAccount()`).
 */
final class TrialBalanceImportService
{
    use ParsesLegacyLedgerExport;

    private const AMOUNT_EPSILON = 0.01;

    private const ACCOUNT_NAME_MATCH_THRESHOLD = 70.0;

    public function __construct(protected JournalEntryService $journalEntryService) {}

    /** @return ImportFieldDefinition[] */
    private function fieldDefinitions(): array
    {
        return [
            new ImportFieldDefinition('account_code', 'Account #', 'string', required: true, synonyms: ['account code', 'kode akun', 'account number']),
            new ImportFieldDefinition('description', 'Description', 'string', synonyms: ['nama akun', 'account description', 'account name']),
            new ImportFieldDefinition('debit', 'Year To Date [DR] (RP)', 'number', synonyms: ['debit', 'dr']),
            new ImportFieldDefinition('credit', 'Year To Date [CR] (RP)', 'number', synonyms: ['credit', 'cr']),
        ];
    }

    /**
     * Parses the whole file — small (one row per Chart of Account, dozens to low hundreds of rows)
     * unlike the Sales/Purchase Journal export, so plain ImportFileReader::readRaw() is fine, no
     * streaming needed. Used both by the controller's synchronous pre-check (duplicate/out-of-
     * balance confirmation gates) and by import() itself — re-parsing twice is cheap at this size.
     *
     * @return array{accounts: array<int, array{code: ?string, description: ?string, debit: float, credit: float}>, out_of_balance_amount: ?float, file_total_debit: ?float, file_total_credit: ?float, pre_total_debit: float, pre_total_credit: float, period_label: ?string, period_end_date: ?string}|array{error: string}
     */
    public function parse(string $absolutePath, string $extension): array
    {
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);
        $fields = $this->fieldDefinitions();
        $headerSettings = HeaderDetector::detect($rawRows, $fields);
        $headerRow = $rawRows[$headerSettings['header_row'] - 1] ?? [];
        $columnIndex = $this->mapColumns($headerRow, $fields);

        $missing = [];
        if (! isset($columnIndex['account_code'])) {
            $missing[] = 'Account #';
        }
        if (! isset($columnIndex['debit']) && ! isset($columnIndex['credit'])) {
            $missing[] = 'Debit/Credit';
        }

        if ($missing !== []) {
            return ['error' => 'Kolom wajib tidak terdeteksi di file ini: '.implode(', ', $missing).'.'];
        }

        $dataRows = array_slice($rawRows, $headerSettings['data_start_row'] - 1);

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

        $accounts = [];
        $outOfBalanceAmount = null;
        $preTotalDebit = 0.0;
        $preTotalCredit = 0.0;
        $fileTotalDebit = null;
        $fileTotalCredit = null;
        $totalRowSeen = false;

        foreach ($dataRows as $raw) {
            $get = fn (string $field) => isset($columnIndex[$field]) ? ($raw[$columnIndex[$field]] ?? null) : null;

            $code = DataCleaner::normalizeText($this->toStringOrNull($get('account_code')));
            $description = DataCleaner::normalizeText($this->toStringOrNull($get('description')));
            $debit = DataCleaner::normalizeNumber($get('debit'), $decimalStyle) ?? 0.0;
            $credit = DataCleaner::normalizeNumber($get('credit'), $decimalStyle) ?? 0.0;

            // Blank separator row.
            if ($code === null && $description === null && abs($debit) < self::AMOUNT_EPSILON && abs($credit) < self::AMOUNT_EPSILON) {
                continue;
            }

            // "OUT OF BALANCE BY" — the file's own admission it doesn't balance. Captured for the
            // controller's confirmation gate, never treated as account data.
            if ($description !== null && stripos($description, 'OUT OF BALANCE') !== false) {
                $outOfBalanceAmount = $debit >= self::AMOUNT_EPSILON ? $debit : ($credit >= self::AMOUNT_EPSILON ? -$credit : $debit);

                continue;
            }

            // The file's own checksum trailer: no account identity, but real totals. Its own scope
            // is only what came above it (confirmed: it excludes a later "Balance Sheet Stock"
            // addendum entirely), so the running pre-total sum freezes here — parsing continues.
            if ($code === null && $description === null && (abs($debit) >= self::AMOUNT_EPSILON || abs($credit) >= self::AMOUNT_EPSILON)) {
                $fileTotalDebit = $debit;
                $fileTotalCredit = $credit;
                $totalRowSeen = true;

                continue;
            }

            // A real account row always has both a code AND a description — even a legitimate
            // zero-balance one (confirmed in the real file, e.g. "101.02.01 KAS KECIL SAMARINDA, 0,
            // 0"). Anything with only one of the two (e.g. a section label like "Balance Sheet
            // Stock", code-only, description blank) is noise, not data — skipped, not end-of-file.
            if ($code === null || $description === null) {
                continue;
            }

            $accounts[] = ['code' => $code, 'description' => $description, 'debit' => $debit, 'credit' => $credit];

            if (! $totalRowSeen) {
                $preTotalDebit += $debit;
                $preTotalCredit += $credit;
            }
        }

        $period = $this->findPeriodRange($rawRows);

        return [
            'accounts' => $accounts,
            'out_of_balance_amount' => $outOfBalanceAmount,
            'file_total_debit' => $fileTotalDebit,
            'file_total_credit' => $fileTotalCredit,
            'pre_total_debit' => round($preTotalDebit, 2),
            'pre_total_credit' => round($preTotalCredit, 2),
            'period_label' => $period['label'] ?? null,
            'period_end_date' => $period['end_date'] ?? null,
        ];
    }

    /** "IMPORT-TB-01/01/2022-31/12/2025" — the dedup/tracing key, stored in journal_entries.source_document_number. */
    public function referenceFor(string $periodLabel): string
    {
        return 'IMPORT-TB-'.str_replace(' ', '', $periodLabel);
    }

    public function import(ImportBatch $batch): void
    {
        $duplicatePolicy = $batch->write_mode ?? 'skip';
        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);
        $absolutePath = Storage::disk($batch->disk)->path($batch->file_path);

        $parsed = $this->parse($absolutePath, $extension);

        if (isset($parsed['error'])) {
            $batch->update(['status' => ImportBatchStatus::FAILED, 'failure_reason' => $parsed['error']]);

            return;
        }

        if ($parsed['period_end_date'] === null) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => 'Periode laporan (mis. "01/01/2022 - 31/12/2025") tidak ditemukan di baris judul file — tidak bisa menentukan tanggal posting.',
            ]);

            return;
        }

        $reference = $this->referenceFor($parsed['period_label']);
        $alreadyImported = JournalEntry::query()->where('source_document_number', $reference)->exists();

        if ($alreadyImported && $duplicatePolicy === 'skip') {
            $batch->update([
                'status' => ImportBatchStatus::COMPLETED,
                'total_rows' => count($parsed['accounts']),
                'success_rows' => 0,
                'failed_rows' => 0,
                'preview_summary' => [
                    'needs_review_rows' => 1,
                    'vouchers' => [['document_number' => $reference, 'status' => 'needs_review', 'reason' => 'Periode ini sudah pernah diimpor sebelumnya — dilewati (skip).']],
                ],
            ]);

            return;
        }

        $batch->update(['status' => ImportBatchStatus::PROCESSING, 'started_at' => now(), 'total_rows' => count($parsed['accounts'])]);

        $accountsByCode = ChartOfAccount::query()->where('is_active', true)->get()->keyBy('code');
        $allAccounts = $accountsByCode->values();

        $lines = [];
        $report = [];
        $exactCount = 0;
        $fuzzyCount = 0;
        $unmatchedCount = 0;
        $postedDebit = 0.0;
        $postedCredit = 0.0;

        foreach ($parsed['accounts'] as $row) {
            $batch->increment('processed_rows');

            $resolved = $this->resolveAccount($row['code'], $row['description'], $accountsByCode, $allAccounts);
            $label = "{$row['code']} — {$row['description']}";

            if ($resolved['account'] === null) {
                $unmatchedCount++;
                $report[] = ['document_number' => $label, 'status' => 'needs_review', 'reason' => 'Tidak ditemukan di Chart of Accounts (exact maupun fuzzy) — dilewati dari Journal Entry, bukan ditebak.'];

                continue;
            }

            $net = round($row['debit'] - $row['credit'], 2);

            if ($resolved['match_type'] === 'exact') {
                $exactCount++;
            } else {
                $fuzzyCount++;
                $report[] = [
                    'document_number' => $label,
                    'status' => 'needs_review',
                    'reason' => "Kode akun tidak ditemukan — dicocokkan otomatis by nama ke \"{$resolved['account']->code} - {$resolved['account']->name}\", mohon verifikasi.",
                ];
            }

            // A net-zero row (debit == credit, including the real file's own legitimate
            // zero-balance accounts) contributes nothing and would violate
            // JournalEntryService::assertEachLineIsSingleSided() (neither side > 0) if posted.
            if (abs($net) < self::AMOUNT_EPSILON) {
                continue;
            }

            $lines[] = [
                'chart_of_account_id' => $resolved['account']->id,
                'debit' => $net > 0 ? $net : 0,
                'credit' => $net < 0 ? abs($net) : 0,
                'description' => "Trial Balance import — {$row['code']} {$row['description']}",
            ];
            $postedDebit += $net > 0 ? $net : 0;
            $postedCredit += $net < 0 ? abs($net) : 0;
        }

        // Skipped/unmatched rows (and a genuinely imbalanced source file) can leave the matched
        // lines' own debit != credit — plugged into the same suspense account
        // PrintLedgerImportService already uses for this exact "unreconciled migration
        // difference" concept, not a second one.
        $plug = round($postedDebit - $postedCredit, 2);

        if ($lines === [] && abs($plug) < self::AMOUNT_EPSILON) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => 'Tidak ada akun yang bisa diposting — semua baris tidak match atau bernilai nol.',
            ]);

            return;
        }

        if (abs($plug) >= self::AMOUNT_EPSILON) {
            $suspense = $this->findOrCreateSuspenseAccount();
            $lines[] = [
                'chart_of_account_id' => $suspense->id,
                'debit' => $plug < 0 ? abs($plug) : 0,
                'credit' => $plug > 0 ? $plug : 0,
                'description' => 'Trial Balance import — selisih akun yang tidak match/dilewati, supaya Journal Entry tetap balance.',
            ];
            $report[] = [
                'document_number' => $suspense->code.' — '.$suspense->name,
                'status' => 'needs_review',
                'reason' => 'Menampung selisih akun yang tidak match/dilewati (lihat baris di atas) supaya Journal Entry tetap balance.',
            ];
        }

        if ($parsed['out_of_balance_amount'] !== null) {
            $report[] = [
                'document_number' => 'OUT OF BALANCE',
                'status' => 'needs_review',
                'reason' => sprintf('File asli sendiri tidak balance sebesar Rp %s (sudah dikonfirmasi sebelum posting).', number_format(abs($parsed['out_of_balance_amount']), 0, ',', '.')),
            ];
        }

        if ($parsed['file_total_debit'] !== null && $parsed['file_total_credit'] !== null) {
            $report[] = [
                'document_number' => 'CHECKSUM',
                'status' => 'needs_review',
                'reason' => sprintf(
                    'Total di file (baris Total): Debit Rp %s, Credit Rp %s. Total berhasil diposting (akun match, tidak termasuk suspense): Debit Rp %s, Credit Rp %s.',
                    number_format($parsed['file_total_debit'], 0, ',', '.'), number_format($parsed['file_total_credit'], 0, ',', '.'),
                    number_format($postedDebit, 0, ',', '.'), number_format($postedCredit, 0, ',', '.'),
                ),
            ];
        }

        if ($alreadyImported) {
            $report[] = ['document_number' => $reference, 'status' => 'needs_review', 'reason' => 'Periode ini sudah pernah diimpor sebelumnya — dibuat lagi sebagai entry tambahan (create anyway).'];
        }

        try {
            $entry = $this->journalEntryService->create([
                'posting_date' => $parsed['period_end_date'],
                'description' => "Trial Balance import ({$batch->original_filename}) — periode {$parsed['period_label']}",
                // Non-null reference_type is what keeps Documentable::requiresApproval() false for
                // this "manual" entry — same convention PrintLedgerImportService/
                // SalesPurchaseJournalImportService already rely on for the same reason.
                'reference_type' => $batch->getMorphClass(),
                'reference_id' => $batch->id,
                'source_document_number' => $reference,
                'lines' => $lines,
            ]);
            $this->journalEntryService->post($entry);
        } catch (Throwable $e) {
            $batch->update(['status' => ImportBatchStatus::FAILED, 'failure_reason' => 'Journal Entry gagal dibuat: '.$e->getMessage()]);

            return;
        }

        $batch->update([
            'success_rows' => $exactCount,
            'failed_rows' => $unmatchedCount,
            'preview_summary' => ['needs_review_rows' => count($report), 'vouchers' => $report],
            'status' => ImportBatchStatus::COMPLETED,
        ]);
    }

    /** @return array{account: ?ChartOfAccount, match_type: 'exact'|'fuzzy'|'unmatched'} */
    private function resolveAccount(?string $code, ?string $description, Collection $accountsByCode, Collection $allAccounts): array
    {
        if ($code !== null && $accountsByCode->has($code)) {
            return ['account' => $accountsByCode->get($code), 'match_type' => 'exact'];
        }

        if ($description !== null) {
            $match = $this->matchLedgerPartyByName($description, $allAccounts, fn (ChartOfAccount $a) => $a->name, self::ACCOUNT_NAME_MATCH_THRESHOLD);

            if ($match !== null) {
                return ['account' => $match, 'match_type' => 'fuzzy'];
            }
        }

        return ['account' => null, 'match_type' => 'unmatched'];
    }

    /** @return array{label: string, end_date: string}|null */
    private function findPeriodRange(array $rawRows): ?array
    {
        foreach (array_slice($rawRows, 0, 6) as $row) {
            foreach ($row as $cell) {
                if (! is_string($cell)) {
                    continue;
                }

                if (preg_match('#(\d{2}/\d{2}/\d{4})\s*-\s*(\d{2})/(\d{2})/(\d{4})#', $cell, $m) === 1) {
                    $endDate = \DateTime::createFromFormat('!d/m/Y', "{$m[2]}/{$m[3]}/{$m[4]}");

                    if ($endDate !== false) {
                        return ['label' => "{$m[1]} - {$m[2]}/{$m[3]}/{$m[4]}", 'end_date' => $endDate->format('Y-m-d')];
                    }
                }
            }
        }

        return null;
    }

    private function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }

    /** Reuses PrintLedgerImportService's own suspense account (same "unreconciled migration difference" concept) rather than creating a second one. */
    private function findOrCreateSuspenseAccount(): ChartOfAccount
    {
        return ChartOfAccount::query()->firstOrCreate(
            ['code' => PrintLedgerImportService::SUSPENSE_ACCOUNT_CODE],
            ['name' => 'Opening Balance Equity (Migration Suspense)', 'account_type' => AccountType::EQUITY, 'is_active' => true],
        );
    }
}
