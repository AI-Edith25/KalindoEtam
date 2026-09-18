<?php

namespace App\Services\Import;

use App\Enums\ImportBatchStatus;
use App\Models\ChartOfAccount;
use App\Models\ImportBatch;
use App\Models\JournalEntry;
use App\Services\Import\Concerns\ParsesLegacyLedgerExport;
use App\Services\JournalEntryService;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Smart, one-click Balance Sheet import — same posture as TrialBalanceImportService/
 * IncomeStatementImportService: Balance Sheet is a pure read model (BalanceSheetService — a
 * presentation layer over GeneralLedgerService::listAccounts() and ProfitLossService::summarize(),
 * docs/BALANCE_SHEET_DESIGN.md), no writable state of its own, so this posts one combined,
 * balanced Journal Entry per file instead of touching any report table directly.
 *
 * Like Income Statement, no column-name header row exists (row 5 is a floating "Year-To-Date
 * (RP)"/"%" sub-label) — fixed positions, data starting at row 6.
 *
 * Unlike Income Statement's fixed 7 named sections, this file has a real 3-level hierarchy
 * (Section > Group > Sub-group > Account) whose section names and order aren't fixed (confirmed
 * against the real attached xlsbalancesheet_maintainstockvalue.xlsx — a different section shape
 * than Income Statement's). The only reliable level signal is dot-count in column A, NOT
 * indentation — that file has a real Level-3 account ("350.01.01", 2 dots) indented exactly like a
 * Level-2 sub-group ("121.03", 1 dot). So instead of a known-section whitelist, this class resets
 * its running checksum sum on ANY line starting with "Total" (case-insensitive), using that line's
 * own text as the checksum's report label — verified against the real file: "TOTAL PROPERTY, PLANT
 * & EQUIPMENT" exactly equals the sum of the two sub-groups' worth of Level-3 accounts above it,
 * confirming one Total line can close out however many sub-groups came since the last reset.
 *
 * Account resolution, sign handling, and the suspense-account balancing plug are identical to
 * IncomeStatementImportService (resolveAccountByCodeOrName()/findOrCreateMigrationSuspenseAccount(),
 * both now shared via ParsesLegacyLedgerExport since this is their 3rd occurrence).
 */
final class BalanceSheetImportService
{
    use ParsesLegacyLedgerExport;

    private const AMOUNT_EPSILON = 0.01;

    private const ACCOUNT_NAME_MATCH_THRESHOLD = 70.0;

    private const DATA_START_ROW = 6;

    /** At least 2 dots (3+ segments) — a 1-dot code ("121.03") is a sub-group header, not an account, confirmed in the real file. */
    private const ACCOUNT_CODE_PATTERN = '/^\d+(\.\d+){2,}$/';

    public function __construct(protected JournalEntryService $journalEntryService) {}

    /**
     * Parses the whole file — small (one row per Chart of Account, dozens to low hundreds of
     * rows), so plain ImportFileReader::readRaw() is fine, no streaming needed.
     *
     * @return array{accounts: array<int, array{code: string, description: ?string, value: float}>, section_checksums: array<string, array{file: ?float, parsed: float}>, period_label: ?string, period_end_date: ?string}|array{error: string}
     */
    public function parse(string $absolutePath, string $extension): array
    {
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        if (count($rawRows) < self::DATA_START_ROW) {
            return ['error' => 'File ini tidak memiliki cukup baris untuk berisi data — periksa kembali formatnya.'];
        }

        $dataRows = array_slice($rawRows, self::DATA_START_ROW - 1);

        $decimalStyle = DataCleaner::detectDecimalStyle(array_column($dataRows, 2));

        $accounts = [];
        $sectionChecksums = [];
        $runningSum = 0.0;
        $sawAnyAccountCode = false;

        foreach ($dataRows as $row) {
            $codeCell = DataCleaner::normalizeText($this->toStringOrNull($row[0] ?? null));
            $descriptionCell = DataCleaner::normalizeText($this->toStringOrNull($row[1] ?? null));
            $value = DataCleaner::normalizeNumber($row[2] ?? null, $decimalStyle);

            if ($codeCell === null && $descriptionCell === null && $value === null) {
                continue;
            }

            // A "Total ..." line (label can sit in column A or B) closes out everything
            // accumulated since the last reset — however many groups/sub-groups that spanned —
            // and is never itself account data, regardless of whether it also happens to carry a
            // number in column C.
            $totalLabel = $this->startsWithTotal($codeCell) ? $codeCell : ($this->startsWithTotal($descriptionCell) ? $descriptionCell : null);

            if ($totalLabel !== null) {
                $sectionChecksums[$totalLabel] = ['file' => $value, 'parsed' => round($runningSum, 2)];
                $runningSum = 0.0;

                continue;
            }

            if ($codeCell !== null && preg_match(self::ACCOUNT_CODE_PATTERN, $codeCell) === 1) {
                $sawAnyAccountCode = true;
                $accounts[] = ['code' => $codeCell, 'description' => $descriptionCell, 'value' => $value ?? 0.0];
                $runningSum += $value ?? 0.0;

                continue;
            }

            // Everything else — a pure-integer group, a pure-text section/sub-header, a 1-dot
            // sub-group, or a blank-A/B derived summary line (the file's own unlabeled "Net
            // Assets"/grand-total rows) — is structural noise, not data. Never resets the running
            // sum: it didn't close anything, and the next real section's accounts should still
            // accumulate correctly regardless of what sits between them and the last Total line.
        }

        if (! $sawAnyAccountCode) {
            return ['error' => 'Tidak ada baris data akun (kolom A berformat kode seperti "121.03.01") yang terdeteksi di file ini.'];
        }

        $period = $this->findDateRangeHeader($rawRows);

        return [
            'accounts' => $accounts,
            'section_checksums' => $sectionChecksums,
            'period_label' => $period['label'] ?? null,
            'period_end_date' => $period['end_date'] ?? null,
        ];
    }

    /** "IMPORT-BS-01/01/2022-31/12/2025" — the dedup/tracing key, stored in journal_entries.source_document_number. */
    public function referenceFor(string $periodLabel): string
    {
        return 'IMPORT-BS-'.str_replace(' ', '', $periodLabel);
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
        $unmatchedCount = 0;
        $postedDebit = 0.0;
        $postedCredit = 0.0;

        foreach ($parsed['accounts'] as $row) {
            $batch->increment('processed_rows');

            $resolved = $this->resolveAccountByCodeOrName($row['code'], $row['description'], $accountsByCode, $allAccounts, self::ACCOUNT_NAME_MATCH_THRESHOLD);
            $label = "{$row['code']} — {$row['description']}";

            if ($resolved['account'] === null) {
                $unmatchedCount++;
                $report[] = ['document_number' => $label, 'status' => 'needs_review', 'reason' => 'Tidak ditemukan di Chart of Accounts (exact maupun fuzzy) — dilewati dari Journal Entry, bukan ditebak.'];

                continue;
            }

            if ($resolved['match_type'] === 'exact') {
                $exactCount++;
            } else {
                $report[] = [
                    'document_number' => $label,
                    'status' => 'needs_review',
                    'reason' => "Kode akun tidak ditemukan — dicocokkan otomatis by nama ke \"{$resolved['account']->code} - {$resolved['account']->name}\", mohon verifikasi.",
                ];
            }

            if (abs($row['value']) < self::AMOUNT_EPSILON) {
                continue;
            }

            // Positive posts to the account's own normal side, negative to the opposite side —
            // identical rule to IncomeStatementImportService, based on the account's own
            // ChartOfAccount type (isDebitNormal()), never a report-section convention.
            $isDebitNormal = $resolved['account']->isDebitNormal();
            $debit = $isDebitNormal ? max($row['value'], 0) : max(-$row['value'], 0);
            $credit = $isDebitNormal ? max(-$row['value'], 0) : max($row['value'], 0);

            $lines[] = [
                'chart_of_account_id' => $resolved['account']->id,
                'debit' => $debit,
                'credit' => $credit,
                'description' => "Balance Sheet import — {$row['code']} {$row['description']}",
            ];
            $postedDebit += $debit;
            $postedCredit += $credit;
        }

        $plug = round($postedDebit - $postedCredit, 2);

        if ($lines === [] && abs($plug) < self::AMOUNT_EPSILON) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => 'Tidak ada akun yang bisa diposting — semua baris tidak match atau bernilai nol.',
            ]);

            return;
        }

        if (abs($plug) >= self::AMOUNT_EPSILON) {
            $suspense = $this->findOrCreateMigrationSuspenseAccount();
            $lines[] = [
                'chart_of_account_id' => $suspense->id,
                'debit' => $plug < 0 ? abs($plug) : 0,
                'credit' => $plug > 0 ? $plug : 0,
                'description' => 'Balance Sheet import — selisih akun yang tidak match/dilewati, supaya Journal Entry tetap balance.',
            ];
            $report[] = [
                'document_number' => $suspense->code.' — '.$suspense->name,
                'status' => 'needs_review',
                'reason' => 'Menampung selisih akun yang tidak match/dilewati (lihat baris di atas) supaya Journal Entry tetap balance.',
            ];
        }

        foreach ($parsed['section_checksums'] as $label => $figures) {
            if ($figures['file'] === null) {
                continue;
            }

            if (abs($figures['file'] - $figures['parsed']) > self::AMOUNT_EPSILON) {
                $report[] = [
                    'document_number' => "CHECKSUM: {$label}",
                    'status' => 'needs_review',
                    'reason' => sprintf(
                        '"%s" di file: Rp %s. Total hasil parsing baris akun sejak reset sebelumnya: Rp %s.',
                        $label, number_format($figures['file'], 0, ',', '.'), number_format($figures['parsed'], 0, ',', '.'),
                    ),
                ];
            }
        }

        if ($alreadyImported) {
            $report[] = ['document_number' => $reference, 'status' => 'needs_review', 'reason' => 'Periode ini sudah pernah diimpor sebelumnya — dibuat lagi sebagai entry tambahan (create anyway).'];
        }

        try {
            $entry = $this->journalEntryService->create([
                'posting_date' => $parsed['period_end_date'],
                'description' => "Balance Sheet import ({$batch->original_filename}) — periode {$parsed['period_label']}",
                // Non-null reference_type is what keeps Documentable::requiresApproval() false for
                // this "manual" entry — same convention every prior importer this session relies on.
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

    private function startsWithTotal(?string $label): bool
    {
        return $label !== null && stripos($label, 'Total') === 0;
    }

    private function toStringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
