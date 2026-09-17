<?php

namespace App\Services\Import;

use App\Enums\AccountType;
use App\Enums\DocumentStatus;
use App\Enums\ImportBatchStatus;
use App\Models\ChartOfAccount;
use App\Models\ImportBatch;
use App\Models\JournalEntry;
use App\Services\Import\Concerns\ParsesLegacyLedgerExport;
use App\Services\JournalEntryService;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Smart, one-click Print Ledger import — no mapping/preview wizard, same
 * shape as PaymentVoucherImportService/OfficialReceiptImportService (only
 * mapColumns()/normalize() reused from ParsesLegacyLedgerExport; the rest of
 * that trait assumes a double-entry, document-grouped source, which this
 * plainly isn't).
 *
 * The source ("Print Ledger [Summary]" export) is one row per account — an
 * already-summarized balance, not a transaction list — so there is nothing
 * to group. But General Ledger itself is a pure read model (see
 * GeneralLedgerService's own docblock: every figure derived fresh from
 * journal_entries, never written back), so this file's "Begining Balance"
 * column can't be poked into a stored field anywhere. The only way to make
 * a balance "the opening balance" for this system's own derived reports is
 * the same way every other opening balance gets into this system: a real,
 * posted Journal Entry dated the day before the file's own period start, so
 * GeneralLedgerRepository::openingTotalsByAccount()'s `posting_date <
 * date_from` picks it up naturally. One combined entry for the whole file
 * (not one per account) — the source is a balanced trial balance (its 110
 * real rows sum to ~0), so posting it as a single multi-line entry mirrors
 * how a real ledger migration is booked, and keeps the audit trail to one
 * document instead of ~110.
 *
 * An account code the current Chart of Accounts doesn't recognize is
 * skipped (never fails the whole file — see the ticket), which necessarily
 * unbalances the entry by exactly that account's contribution. The
 * remainder is plugged into a single, auto-created "Opening Balance Equity"
 * suspense account (find-or-create, idempotent) — the standard mechanism
 * every accounting system uses for a migration/conversion balance nobody
 * fully reconciled yet, rather than guessing which real account it belongs
 * to. It's reported like any other review-needed line, never hidden.
 */
final class PrintLedgerImportService
{
    use ParsesLegacyLedgerExport;

    private const AMOUNT_EPSILON = 0.01;

    public const SUSPENSE_ACCOUNT_CODE = 'OPENING-BALANCE-SUSPENSE';

    public function __construct(protected JournalEntryService $journalEntryService) {}

    public function import(ImportBatch $batch): void
    {
        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);
        $absolutePath = Storage::disk($batch->disk)->path($batch->file_path);
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        $fields = $this->printLedgerFieldDefinitions();
        $headerSettings = HeaderDetector::detect($rawRows, $fields);
        $headerRow = $rawRows[$headerSettings['header_row'] - 1] ?? [];
        $columnIndex = $this->mapColumns($headerRow, $fields);

        $requiredLabels = ['account_code' => 'Account Code', 'opening_balance' => 'Begining Balance'];
        $missing = array_values(array_diff_key($requiredLabels, $columnIndex));

        if ($missing !== []) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => 'Kolom wajib tidak terdeteksi di file ini: '.implode(', ', $missing).'.',
            ]);

            return;
        }

        $openingDate = $this->findOpeningDate($rawRows);

        if ($openingDate === null) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => 'Periode laporan (mis. "01/01/2022 - 31/12/2025") tidak ditemukan di baris judul file — tidak bisa menentukan tanggal Opening Balance.',
            ]);

            return;
        }

        if (($conflict = $this->existingOpeningBalanceEntry($openingDate)) !== null) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => "Sudah ada Opening Balance yang diimpor untuk tanggal {$openingDate} (Journal Entry {$conflict->document_number}) — reverse dulu entry tersebut sebelum mengimpor ulang, supaya saldo tidak dobel-hitung.",
            ]);

            return;
        }

        $dataRows = array_slice($rawRows, $headerSettings['data_start_row'] - 1);
        $rows = $this->parsePrintLedgerRows($dataRows, $columnIndex);

        if ($rows === []) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => 'Tidak ada baris data yang bisa dibaca dari file ini.',
            ]);

            return;
        }

        $batch->update(['total_rows' => count($rows), 'status' => ImportBatchStatus::PROCESSING, 'started_at' => now()]);

        $accountsByCode = ChartOfAccount::query()->get()->keyBy(fn (ChartOfAccount $a) => $this->normalizeCode($a->code));

        $lines = [];
        $report = [];
        $needsReviewTotal = 0.0;
        $needsReviewCount = 0;

        foreach ($rows as $row) {
            $batch->increment('processed_rows');
            $label = trim($row['code'].($row['description'] !== null ? " — {$row['description']}" : ''));
            $amount = round($row['opening_balance'], 2);

            $account = $accountsByCode->get($this->normalizeCode($row['code']));

            if ($account === null) {
                $report[] = ['document_number' => $label, 'status' => 'needs_review', 'reason' => 'Kode akun tidak ditemukan di Chart of Accounts.'];
                $needsReviewTotal += $amount;
                $needsReviewCount++;

                continue;
            }

            if (abs($amount) < self::AMOUNT_EPSILON) {
                $report[] = ['document_number' => $label, 'status' => 'success', 'reason' => 'Saldo 0 — tidak ada baris jurnal dibuat.'];

                continue;
            }

            $lines[] = [
                'chart_of_account_id' => $account->id,
                'debit' => $amount > 0 ? $amount : 0,
                'credit' => $amount < 0 ? abs($amount) : 0,
                'description' => "Opening Balance — {$account->code} {$account->name}",
            ];
            $report[] = ['document_number' => $label, 'status' => 'success', 'reason' => null];
        }

        $plug = round($needsReviewTotal, 2);

        if ($lines === [] && abs($plug) < self::AMOUNT_EPSILON) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => 'Tidak ada akun yang bisa diproses — semua kode akun di file ini tidak ditemukan di Chart of Accounts.',
            ]);

            return;
        }

        if (abs($plug) >= self::AMOUNT_EPSILON) {
            // Same debit-if-positive/credit-if-negative convention as every other line above —
            // this is exactly what each skipped row's own line would have posted, just combined
            // onto one suspense account instead of onto accounts this Chart of Accounts doesn't
            // have. (The matched lines above already net to -$plug by construction — the file's
            // 110 real rows sum to ~0 — so a plug of $plug, same sign convention, is what brings
            // total debit back to total credit; negating it here would double the imbalance.)
            $suspense = $this->findOrCreateSuspenseAccount();
            $lines[] = [
                'chart_of_account_id' => $suspense->id,
                'debit' => $plug > 0 ? $plug : 0,
                'credit' => $plug < 0 ? abs($plug) : 0,
                'description' => "Selisih {$needsReviewCount} akun yang tidak ditemukan di Chart of Accounts — perlu direview manual.",
            ];
            $report[] = [
                'document_number' => $suspense->code.' — '.$suspense->name,
                'status' => 'needs_review',
                'reason' => "Menampung selisih {$needsReviewCount} akun yang tidak ditemukan (lihat baris di atas) supaya Journal Entry tetap balance.",
            ];
        }

        try {
            $journalEntry = $this->journalEntryService->create([
                'posting_date' => $openingDate,
                'description' => "Print Ledger Opening Balance import ({$batch->original_filename})",
                'reference_type' => $batch->getMorphClass(),
                'reference_id' => $batch->id,
                'lines' => $lines,
            ]);
            $this->journalEntryService->post($journalEntry);
        } catch (Throwable $e) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => 'Journal Entry Opening Balance gagal dibuat: '.$e->getMessage(),
            ]);

            return;
        }

        $batch->update([
            'success_rows' => count(array_filter($report, fn ($r) => $r['status'] === 'success')),
            'failed_rows' => 0,
            'preview_summary' => ['needs_review_rows' => count(array_filter($report, fn ($r) => $r['status'] === 'needs_review')), 'vouchers' => $report],
            'status' => ImportBatchStatus::COMPLETED,
        ]);
    }

    /** @return ImportFieldDefinition[] */
    private function printLedgerFieldDefinitions(): array
    {
        return [
            new ImportFieldDefinition('account_code', 'Account Code', 'string', required: true, synonyms: ['kode akun', 'code']),
            new ImportFieldDefinition('account_description', 'Account Description', 'string', synonyms: ['account name', 'nama akun', 'description']),
            // The source misspells this "Begining Balance" (not "Beginning") — both spellings are
            // listed explicitly rather than relying on the fuzzy (>=70%) fallback, since an exact
            // synonym match is the more reliable of the two given mapColumns()' two-pass order.
            new ImportFieldDefinition('opening_balance', 'Opening Balance', 'number', required: true, synonyms: [
                'begining balance', 'beginning balance', 'saldo awal',
            ]),
            new ImportFieldDefinition('total_debit', 'Total Debit', 'number', synonyms: ['debit']),
            new ImportFieldDefinition('total_credit', 'Total Credit', 'number', synonyms: ['credit']),
            new ImportFieldDefinition('net_activity', 'Net Activity', 'number', synonyms: ['net movement', 'pergerakan']),
            new ImportFieldDefinition('ending_balance', 'Ending Balance', 'number', synonyms: ['saldo akhir']),
        ];
    }

    /** @return array<int, array{code: string, description: ?string, opening_balance: float}> */
    private function parsePrintLedgerRows(array $dataRows, array $columnIndex): array
    {
        $balanceIndex = $columnIndex['opening_balance'];
        // .xlsx cells arrive as native floats/ints already (normalizeNumber's fast path — no
        // ambiguity); only a .csv upload hits this string-style detection at all.
        $decimalStyle = DataCleaner::detectDecimalStyle(array_column($dataRows, $balanceIndex));

        $rows = [];

        foreach ($dataRows as $raw) {
            $get = fn (string $field) => isset($columnIndex[$field]) ? ($raw[$columnIndex[$field]] ?? null) : null;

            $code = DataCleaner::normalizeText($this->rawToStringOrNull($get('account_code')));

            // Blank separator rows and the file's own trailing "Total" row (which carries no
            // account code) both fall out here — structural noise, not a real account row.
            if ($code === null) {
                continue;
            }

            $rows[] = [
                'code' => $code,
                'description' => DataCleaner::normalizeText($this->rawToStringOrNull($get('account_description'))),
                'opening_balance' => DataCleaner::normalizeNumber($raw[$balanceIndex] ?? null, $decimalStyle) ?? 0.0,
            ];
        }

        return $rows;
    }

    private function rawToStringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * Scans the report's own title rows for "DD/MM/YYYY - DD/MM/YYYY" (row 3
     * in the documented export shape, but scanned across the first few rows
     * rather than hardcoded to that index, in case of minor layout drift)
     * and returns the day before the range's start — the conventional
     * "opening balance" posting date for a period beginning that day.
     */
    private function findOpeningDate(array $rawRows): ?string
    {
        foreach (array_slice($rawRows, 0, 6) as $row) {
            foreach ($row as $cell) {
                if (! is_string($cell)) {
                    continue;
                }

                if (preg_match('#(\d{2})/(\d{2})/(\d{4})\s*-\s*\d{2}/\d{2}/\d{4}#', $cell, $m) === 1) {
                    $date = \DateTime::createFromFormat('!d/m/Y', "{$m[1]}/{$m[2]}/{$m[3]}");

                    if ($date !== false) {
                        return $date->modify('-1 day')->format('Y-m-d');
                    }
                }
            }
        }

        return null;
    }

    private function existingOpeningBalanceEntry(string $postingDate): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('reference_type', (new ImportBatch)->getMorphClass())
            ->whereDate('posting_date', $postingDate)
            ->where('status', '!=', DocumentStatus::CANCELLED)
            ->first();
    }

    private function findOrCreateSuspenseAccount(): ChartOfAccount
    {
        return ChartOfAccount::query()->firstOrCreate(
            ['code' => self::SUSPENSE_ACCOUNT_CODE],
            ['name' => 'Opening Balance Equity (Migration Suspense)', 'account_type' => AccountType::EQUITY, 'is_active' => true],
        );
    }
}
