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
 * Smart, one-click Income Statement import — same posture as TrialBalanceImportService: Income
 * Statement is a pure read model over GeneralLedgerService::listAccounts()' period movement
 * (ProfitLossService, docs/PROFIT_LOSS_DESIGN.md), no writable state of its own, so this posts one
 * combined, balanced Journal Entry per file instead of touching any report table directly.
 *
 * Unlike every other importer here, this source file has NO column-name header row at all — row 5
 * is just a "Year-To-Date (RP)" / "%" sub-label floating over columns C/D, confirmed against the
 * real attached xlsincomestatement.xlsx. So this class skips HeaderDetector/mapColumns() entirely
 * and reads fixed positions (A=code/label, B=description, C=value, D=%, ignored), data always
 * starting at row 6.
 *
 * Row classification is corrected from the ticket's own prose against that real file: a data row's
 * column A always holds a dotted numeric code ("410.01.02") — that pattern alone identifies it, not
 * "column C has a number," since subtotal rows ("Total Income") and summary/derived rows
 * ("GROSS PROFIT/(LOSS)") both also carry a real number in column C with the label in column A.
 *
 * Account resolution and the suspense-account balancing plug are identical to
 * TrialBalanceImportService (exact code → fuzzy name via ParsesLegacyLedgerExport::
 * matchLedgerPartyByName(), 70% threshold → excluded and reported, never guessed at). Sign
 * handling follows the ticket's own explicit, literal rule: a positive Year-To-Date value posts to
 * the account's own normal side (ChartOfAccount::isDebitNormal()), negative posts to the opposite
 * side — deliberately with no awareness of ProfitLossService's own contra-account section-display
 * sign flip (confirmed user decision).
 */
final class IncomeStatementImportService
{
    use ParsesLegacyLedgerExport;

    private const AMOUNT_EPSILON = 0.01;

    private const ACCOUNT_NAME_MATCH_THRESHOLD = 70.0;

    private const DATA_START_ROW = 6;

    private const ACCOUNT_CODE_PATTERN = '/^\d+(\.\d+)+$/';

    /** The file's own 7 section labels — open/reset the running per-section sum used for the "Total X" checksum below. Not posted themselves. */
    private const SECTION_LABELS = [
        'Income', 'Cost of Sales', 'Other Income', 'Administrative Expenses',
        'Operating Expenses', 'Others Expenses', 'Taxation',
    ];

    /**
     * Derived/summary lines — combine multiple sections or carry no account of their own, so
     * they're neither postable data nor a per-section checksum. Matched whitespace-normalized:
     * the real file spells some of these with irregular spacing ("RETAINED PROFIT /( LOSS) B/F").
     */
    private const SUMMARY_LABELS = [
        'GROSS PROFIT/(LOSS)', 'PROFIT/(LOSS) BEFORE TAXATION', 'PROFIT/(LOSS) AFTER TAXATION',
        'NET PROFIT/(LOSS)', 'CURRENT ADJUSMENT TO RETAINED EARNING A/C',
        'RETAINED PROFIT/(LOSS) B/F', 'RETAINED PROFIT/(LOSS) C/F',
    ];

    public function __construct(protected JournalEntryService $journalEntryService) {}

    /**
     * Parses the whole file — small (one row per Chart of Account, dozens to low hundreds of rows)
     * like Trial Balance, so plain ImportFileReader::readRaw() is fine, no streaming needed.
     *
     * @return array{accounts: array<int, array{code: string, description: ?string, value: float}>, section_checksums: array<string, float>, period_label: ?string, period_end_date: ?string}|array{error: string}
     */
    public function parse(string $absolutePath, string $extension): array
    {
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        if (count($rawRows) < self::DATA_START_ROW) {
            return ['error' => 'File ini tidak memiliki cukup baris untuk berisi data — periksa kembali formatnya.'];
        }

        $dataRows = array_slice($rawRows, self::DATA_START_ROW - 1);

        $decimalValues = array_column($dataRows, 2);
        $decimalStyle = DataCleaner::detectDecimalStyle($decimalValues);

        $accounts = [];
        $sectionChecksums = [];
        $unrecognized = [];
        $currentSection = null;
        $runningSectionSum = 0.0;
        $sawAnyAccountCode = false;

        foreach ($dataRows as $row) {
            $codeCell = DataCleaner::normalizeText($this->toStringOrNull($row[0] ?? null));
            $descriptionCell = DataCleaner::normalizeText($this->toStringOrNull($row[1] ?? null));
            $value = DataCleaner::normalizeNumber($row[2] ?? null, $decimalStyle);

            if ($codeCell === null && $descriptionCell === null && $value === null) {
                continue;
            }

            if ($codeCell !== null && preg_match(self::ACCOUNT_CODE_PATTERN, $codeCell) === 1) {
                $sawAnyAccountCode = true;
                $accounts[] = ['code' => $codeCell, 'description' => $descriptionCell, 'value' => $value ?? 0.0];
                $runningSectionSum += $value ?? 0.0;

                continue;
            }

            // Everything past here has no account code — a section label, a "Total X" subtotal, or
            // one of the summary/derived lines. Label text can sit in either column (confirmed:
            // section labels and "Total X" use column A; this system never puts it in B alone).
            $label = $codeCell ?? $descriptionCell;

            if ($label === null) {
                continue;
            }

            if (stripos($label, 'Total ') === 0) {
                if ($currentSection !== null) {
                    $sectionChecksums[$currentSection] = ['file' => $value, 'parsed' => round($runningSectionSum, 2)];
                }
                $currentSection = null;
                $runningSectionSum = 0.0;

                continue;
            }

            if (in_array($label, self::SECTION_LABELS, true)) {
                $currentSection = $label;
                $runningSectionSum = 0.0;

                continue;
            }

            // A known summary/derived line ("GROSS PROFIT/(LOSS)", ...) — informational only,
            // never posted, never part of a section checksum. Whitespace-normalized: the real file
            // spells some of these with irregular spacing ("RETAINED PROFIT /( LOSS) B/F").
            $normalizedLabel = preg_replace('/\s+/', '', strtoupper($label));
            $isKnownSummaryLine = in_array($normalizedLabel, array_map(fn ($s) => preg_replace('/\s+/', '', strtoupper($s)), self::SUMMARY_LABELS), true);

            if (! $isKnownSummaryLine) {
                $unrecognized[] = $label;
            }
        }

        if (! $sawAnyAccountCode) {
            return ['error' => 'Tidak ada baris data akun (kolom A berformat kode seperti "410.01.02") yang terdeteksi di file ini.'];
        }

        $period = $this->findDateRangeHeader($rawRows);

        return [
            'accounts' => $accounts,
            'section_checksums' => $sectionChecksums,
            'unrecognized' => $unrecognized,
            'period_label' => $period['label'] ?? null,
            'period_end_date' => $period['end_date'] ?? null,
        ];
    }

    /** "IMPORT-IS-01/01/2022-31/12/2025" — the dedup/tracing key, stored in journal_entries.source_document_number. */
    public function referenceFor(string $periodLabel): string
    {
        return 'IMPORT-IS-'.str_replace(' ', '', $periodLabel);
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

            $resolved = $this->resolveAccount($row['code'], $row['description'], $accountsByCode, $allAccounts);
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
            // literal per the ticket, no awareness of ProfitLossService's own contra-account
            // section-display sign flip (confirmed decision).
            $isDebitNormal = $resolved['account']->isDebitNormal();
            $debit = $isDebitNormal ? max($row['value'], 0) : max(-$row['value'], 0);
            $credit = $isDebitNormal ? max(-$row['value'], 0) : max($row['value'], 0);

            $lines[] = [
                'chart_of_account_id' => $resolved['account']->id,
                'debit' => $debit,
                'credit' => $credit,
                'description' => "Income Statement import — {$row['code']} {$row['description']}",
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
            $suspense = $this->findOrCreateSuspenseAccount();
            $lines[] = [
                'chart_of_account_id' => $suspense->id,
                'debit' => $plug < 0 ? abs($plug) : 0,
                'credit' => $plug > 0 ? $plug : 0,
                'description' => 'Income Statement import — selisih akun yang tidak match/dilewati, supaya Journal Entry tetap balance.',
            ];
            $report[] = [
                'document_number' => $suspense->code.' — '.$suspense->name,
                'status' => 'needs_review',
                'reason' => 'Menampung selisih akun yang tidak match/dilewati (lihat baris di atas) supaya Journal Entry tetap balance.',
            ];
        }

        foreach ($parsed['section_checksums'] as $section => $figures) {
            if ($figures['file'] === null) {
                continue;
            }

            if (abs($figures['file'] - $figures['parsed']) > self::AMOUNT_EPSILON) {
                $report[] = [
                    'document_number' => "CHECKSUM: {$section}",
                    'status' => 'needs_review',
                    'reason' => sprintf(
                        'Total "%s" di file: Rp %s. Total hasil parsing baris akun di section ini: Rp %s.',
                        $section, number_format($figures['file'], 0, ',', '.'), number_format($figures['parsed'], 0, ',', '.'),
                    ),
                ];
            }
        }

        if ($alreadyImported) {
            $report[] = ['document_number' => $reference, 'status' => 'needs_review', 'reason' => 'Periode ini sudah pernah diimpor sebelumnya — dibuat lagi sebagai entry tambahan (create anyway).'];
        }

        foreach ($parsed['unrecognized'] as $label) {
            $report[] = [
                'document_number' => 'UNRECOGNIZED',
                'status' => 'needs_review',
                'reason' => "Baris \"{$label}\" tidak dikenali sebagai kode akun, section, subtotal, atau summary line yang diketahui — dilewati, periksa format file.",
            ];
        }

        try {
            $entry = $this->journalEntryService->create([
                'posting_date' => $parsed['period_end_date'],
                'description' => "Income Statement import ({$batch->original_filename}) — periode {$parsed['period_label']}",
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

    /** @return array{account: ?ChartOfAccount, match_type: 'exact'|'fuzzy'|'unmatched'} */
    private function resolveAccount(string $code, ?string $description, Collection $accountsByCode, Collection $allAccounts): array
    {
        if ($accountsByCode->has($code)) {
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

    private function toStringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /** Reuses PrintLedgerImportService's own suspense account (same "unreconciled migration difference" concept TrialBalanceImportService also shares) rather than creating a third one. */
    private function findOrCreateSuspenseAccount(): ChartOfAccount
    {
        return ChartOfAccount::query()->firstOrCreate(
            ['code' => PrintLedgerImportService::SUSPENSE_ACCOUNT_CODE],
            ['name' => 'Opening Balance Equity (Migration Suspense)', 'account_type' => AccountType::EQUITY, 'is_active' => true],
        );
    }
}
