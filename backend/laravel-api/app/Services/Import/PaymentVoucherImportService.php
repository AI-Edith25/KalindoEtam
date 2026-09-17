<?php

namespace App\Services\Import;

use App\Enums\AccountsPayableStatus;
use App\Enums\AccountType;
use App\Enums\ImportBatchStatus;
use App\Models\AccountsPayable;
use App\Models\ChartOfAccount;
use App\Models\ImportBatch;
use App\Models\PaymentEntry;
use App\Models\Supplier;
use App\Services\PaymentEntryAllocationService;
use App\Services\PaymentEntryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

/**
 * Smart, one-click Payment Voucher import — no mapping/preview wizard (see
 * ImportBatchService/ImportTemplate for that shape). A legacy voucher export
 * is a double-entry journal: several rows share one DOCUMENT #, one row is
 * the cash/bank leg (CREDIT-side), the rest are allocation/expense legs
 * (DEBIT-side). ImportTemplate assumes one file row = one persisted record,
 * which this data plainly isn't — so this reuses ImportFileReader/
 * HeaderDetector (the parsing/header-detection primitives) directly instead
 * of forcing the row-shaped framework to fit.
 *
 * Column identification is by fuzzy header name (see fieldDefinitions()),
 * never fixed position, so a differently-shaped export of the same kind of
 * data still works. Supplier/expense-account matching is best-effort
 * (PHP's own similar_text — legacy codes don't correspond to this system's
 * chart at all, see ChartOfAccountsSeeder) — a voucher that can't be fully
 * resolved is still created (Draft, or Submitted-but-Unallocated/partially
 * applied), never dropped, and reported for manual review.
 */
final class PaymentVoucherImportService
{
    private const AMOUNT_EPSILON = 0.01;

    private const SUPPLIER_MATCH_THRESHOLD = 55.0;

    private const EXPENSE_MATCH_THRESHOLD = 60.0;

    private const CASH_ACCOUNT_MATCH_THRESHOLD = 40.0;

    public function __construct(
        protected PaymentEntryService $paymentEntryService,
        protected PaymentEntryAllocationService $paymentEntryAllocationService,
    ) {}

    public function import(ImportBatch $batch): void
    {
        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);
        $absolutePath = Storage::disk($batch->disk)->path($batch->file_path);
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        $fields = $this->fieldDefinitions();
        $headerSettings = HeaderDetector::detect($rawRows, $fields);
        $headerRow = $rawRows[$headerSettings['header_row'] - 1] ?? [];
        $columnIndex = $this->mapColumns($headerRow, $fields);

        $requiredLabels = ['document_number' => 'Document #', 'date' => 'Date', 'debit' => 'Debit', 'credit' => 'Credit'];
        $missing = array_values(array_diff_key($requiredLabels, $columnIndex));

        if ($missing !== []) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => 'Kolom wajib tidak terdeteksi di file ini: '.implode(', ', $missing).'.',
            ]);

            return;
        }

        $dataRows = array_slice($rawRows, $headerSettings['data_start_row'] - 1);
        $groups = $this->groupRows($this->parseRows($dataRows, $columnIndex));

        if ($groups === []) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => 'Tidak ada baris data yang bisa dibaca dari file ini.',
            ]);

            return;
        }

        $batch->update(['total_rows' => count($groups), 'status' => ImportBatchStatus::PROCESSING, 'started_at' => now()]);

        $suppliers = Supplier::query()->where('is_active', true)->get();
        $cashAccounts = ChartOfAccount::query()->where('is_cash_bank', true)->where('is_active', true)->get();
        $expenseAccounts = ChartOfAccount::query()->where('account_type', AccountType::EXPENSE)->where('is_active', true)->get();

        $report = [];
        $success = 0;
        $failed = 0;
        $needsReview = 0;

        foreach ($groups as $documentNumber => $rows) {
            $outcome = $this->processGroup((string) $documentNumber, $rows, $suppliers, $cashAccounts, $expenseAccounts);
            $report[] = $outcome;

            match ($outcome['status']) {
                'success' => $success++,
                'needs_review' => $needsReview++,
                default => $failed++,
            };

            $batch->increment('processed_rows');
        }

        $batch->update([
            'success_rows' => $success,
            'failed_rows' => $failed,
            'preview_summary' => ['needs_review_rows' => $needsReview, 'vouchers' => $report],
            'status' => ImportBatchStatus::COMPLETED,
        ]);
    }

    /** @return ImportFieldDefinition[] */
    private function fieldDefinitions(): array
    {
        return [
            new ImportFieldDefinition('document_number', 'Document #', 'string', required: true, synonyms: [
                'no dokumen', 'nomor dokumen', 'voucher no', 'voucher number', 'no voucher', 'doc no', 'pv no',
            ]),
            new ImportFieldDefinition('date', 'Date', 'date', required: true, synonyms: ['tanggal', 'tgl', 'payment date', 'voucher date']),
            new ImportFieldDefinition('cheque_date', 'Cheque Date', 'date', synonyms: ['tanggal cek', 'giro date']),
            new ImportFieldDefinition('cheque_number', 'Cheque #', 'string', synonyms: ['no cek', 'nomor cek', 'check number', 'no giro', 'giro number']),
            new ImportFieldDefinition('account', 'Account', 'string', synonyms: ['akun', 'kode akun', 'account code', 'coa', 'no akun']),
            new ImportFieldDefinition('sl_code', 'SL Code', 'string', synonyms: ['kode supplier', 'supplier code', 'sl no', 'subsidiary ledger']),
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
    private function mapColumns(array $headerRow, array $fields): array
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

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)) ?? '');
    }

    /** @return array<int, array{document_number: string, date: ?string, account: ?string, sl_code: ?string, particulars: ?string, debit: float, credit: float}> */
    private function parseRows(array $dataRows, array $columnIndex): array
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
                'date' => $this->parseDate($get('date')),
                'account' => DataCleaner::normalizeText($this->toStringOrNull($get('account'))),
                'sl_code' => DataCleaner::normalizeText($this->toStringOrNull($get('sl_code'))),
                'particulars' => DataCleaner::normalizeText($this->toStringOrNull($get('particulars'))),
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        return $parsed;
    }

    private function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }

    /** Handles a Carbon/DateTime cell (PhpSpreadsheet, date-formatted), a raw Excel serial number, or plain text — DataCleaner::normalizeDate() alone only covers the last of these. */
    private function parseDate(mixed $raw): ?string
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
    private function groupRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['document_number']][] = $row;
        }

        return $groups;
    }

    private function processGroup(string $documentNumber, array $rows, Collection $suppliers, Collection $cashAccounts, Collection $expenseAccounts): array
    {
        $base = ['document_number' => $documentNumber];

        if (PaymentEntry::query()->where('reference_number', $documentNumber)->exists()) {
            return [...$base, 'status' => 'needs_review', 'reason' => 'Nomor dokumen ini sudah pernah diimpor sebelumnya — dilewati.'];
        }

        $totalDebit = round(array_sum(array_column($rows, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($rows, 'credit')), 2);

        if (abs($totalDebit - $totalCredit) > self::AMOUNT_EPSILON) {
            return [...$base, 'status' => 'failed', 'reason' => sprintf(
                'Debit (Rp %s) tidak sama dengan Credit (Rp %s) — baris tidak balance.',
                number_format($totalDebit, 0, ',', '.'),
                number_format($totalCredit, 0, ',', '.'),
            )];
        }

        $cashRows = array_values(array_filter($rows, fn ($r) => $r['credit'] > self::AMOUNT_EPSILON && $r['debit'] <= self::AMOUNT_EPSILON));
        $allocationRows = array_values(array_filter($rows, fn ($r) => $r['debit'] > self::AMOUNT_EPSILON && $r['credit'] <= self::AMOUNT_EPSILON));

        if (count($cashRows) !== 1) {
            return [...$base, 'status' => 'failed', 'reason' => count($cashRows) === 0
                ? 'Tidak ditemukan baris kas/bank (baris ber-CREDIT) dalam grup ini.'
                : 'Ditemukan lebih dari satu baris kas/bank dalam grup ini — tidak bisa ditentukan otomatis.'];
        }

        if ($allocationRows === []) {
            return [...$base, 'status' => 'failed', 'reason' => 'Tidak ada baris alokasi (baris ber-DEBIT) dalam grup ini.'];
        }

        $cashRow = $cashRows[0];
        $totalAmount = (float) $cashRow['credit'];

        [$cashAccount, $cashGuessed] = $this->resolveCashAccount($cashRow, $cashAccounts);

        if ($cashAccount === null) {
            return [...$base, 'status' => 'failed', 'reason' => 'Tidak ada akun Kas/Bank aktif (is_cash_bank) di Chart of Accounts — tidak bisa membuat voucher apa pun.'];
        }

        $paymentDate = $cashRow['date'] ?? collect($rows)->pluck('date')->filter()->first();

        if ($paymentDate === null) {
            return [...$base, 'status' => 'failed', 'reason' => 'Tanggal pembayaran tidak terbaca pada grup ini.'];
        }

        $resolved = array_map(fn ($row) => ['row' => $row, 'line' => $this->resolveAllocationLine($row, $suppliers, $expenseAccounts)], $allocationRows);

        try {
            return DB::transaction(function () use ($base, $resolved, $cashAccount, $cashGuessed, $paymentDate, $totalAmount, $documentNumber) {
                $notes = $cashGuessed ? ['Akun Kas/Bank dipilih otomatis (default) — mohon verifikasi.'] : [];

                if (count($resolved) === 1) {
                    $line = $resolved[0]['line'];

                    if ($line !== null && $line['type'] === 'supplier') {
                        $entry = $this->paymentEntryService->create([
                            'payment_type' => 'supplier',
                            'supplier_id' => $line['supplier_id'],
                            'payment_date' => $paymentDate,
                            'cash_account_id' => $cashAccount->id,
                            'reference_number' => $documentNumber,
                            'amount' => $totalAmount,
                        ]);
                        $entry = $this->paymentEntryService->submit($entry);

                        if ($line['accounts_payable_id'] !== null) {
                            $this->paymentEntryAllocationService->allocateBatch($entry, [
                                ['accounts_payable_id' => $line['accounts_payable_id'], 'amount' => $totalAmount],
                            ]);

                            return [...$base, 'status' => $notes === [] ? 'success' : 'needs_review', 'reason' => $notes[0] ?? null, 'payment_entry_id' => $entry->id];
                        }

                        $notes[] = 'Tidak ada tagihan (Accounts Payable) terbuka yang cocok untuk supplier ini — voucher dibuat berstatus Unallocated.';

                        return [...$base, 'status' => 'needs_review', 'reason' => implode(' ', $notes), 'payment_entry_id' => $entry->id];
                    }

                    if ($line !== null && $line['type'] === 'expense') {
                        $entry = $this->paymentEntryService->create([
                            'payment_type' => 'general_expense',
                            'expense_account_id' => $line['expense_account_id'],
                            'description' => $line['description'],
                            'payment_date' => $paymentDate,
                            'cash_account_id' => $cashAccount->id,
                            'reference_number' => $documentNumber,
                            'amount' => $totalAmount,
                        ]);
                        $entry = $this->paymentEntryService->submit($entry);

                        return [...$base, 'status' => $notes === [] ? 'success' : 'needs_review', 'reason' => $notes[0] ?? null, 'payment_entry_id' => $entry->id];
                    }
                }

                // 2+ allocation rows, or a single row nothing could be matched to — Mixed lets
                // us submit whatever DID resolve and leave the rest as an automatic "unapplied"
                // remainder (PaymentEntry::mixedJournalLines()), rather than blocking the voucher.
                $entry = $this->paymentEntryService->create([
                    'payment_type' => 'mixed',
                    'payment_date' => $paymentDate,
                    'cash_account_id' => $cashAccount->id,
                    'reference_number' => $documentNumber,
                    'amount' => $totalAmount,
                ]);

                $submitLines = [];
                $unresolvedNotes = [];

                foreach ($resolved as $item) {
                    $line = $item['line'];
                    $row = $item['row'];

                    if ($line !== null && $line['type'] === 'supplier' && $line['accounts_payable_id'] !== null) {
                        $submitLines[] = ['type' => 'supplier', 'accounts_payable_id' => $line['accounts_payable_id'], 'amount' => $line['amount']];
                    } elseif ($line !== null && $line['type'] === 'expense') {
                        $submitLines[] = ['type' => 'expense', 'expense_account_id' => $line['expense_account_id'], 'description' => $line['description'], 'amount' => $line['amount']];
                    } else {
                        $label = $line['label'] ?? ($row['particulars'] ?: ($row['account'] ?: '—'));
                        $unresolvedNotes[] = sprintf('Rp %s (%s)', number_format($row['debit'], 0, ',', '.'), $label);
                    }
                }

                if ($submitLines === []) {
                    $notes[] = 'Tidak ada baris yang bisa dicocokkan otomatis — voucher dibuat sebagai Draft, lengkapi manual.';

                    return [...$base, 'status' => 'needs_review', 'reason' => implode(' ', $notes), 'payment_entry_id' => $entry->id];
                }

                $entry = $this->paymentEntryService->submit($entry, $submitLines);

                if ($unresolvedNotes !== []) {
                    $notes[] = 'Sebagian baris tidak cocok otomatis, dicatat sebagai belum teralokasi: '.implode(', ', $unresolvedNotes).'.';
                }

                return [...$base, 'status' => $notes === [] ? 'success' : 'needs_review', 'reason' => $notes === [] ? null : implode(' ', $notes), 'payment_entry_id' => $entry->id];
            });
        } catch (Throwable $e) {
            return [...$base, 'status' => 'failed', 'reason' => $e->getMessage()];
        }
    }

    /**
     * @return array{type: 'supplier', supplier_id: string, accounts_payable_id: ?string, amount: float, label: string}
     *                                                                                                                  |array{type: 'expense', expense_account_id: string, description: string, amount: float, label: string}|null
     */
    private function resolveAllocationLine(array $row, Collection $suppliers, Collection $expenseAccounts): ?array
    {
        $amount = (float) $row['debit'];
        $particulars = (string) ($row['particulars'] ?? '');
        $slCode = trim((string) ($row['sl_code'] ?? ''));

        // SL CODE filled means "this leg settles a supplier's bill" — the code itself doesn't
        // correspond to anything in this system (see class docblock), so the supplier is
        // resolved by fuzzy-matching PARTICULARS' free text instead, per the ticket's own
        // guidance that codes are a hint, not authoritative.
        if ($slCode !== '') {
            $supplier = $this->matchSupplier($particulars !== '' ? $particulars : $slCode, $suppliers);

            if ($supplier !== null) {
                $payable = $this->findOpenPayable($supplier, $amount);

                return [
                    'type' => 'supplier',
                    'supplier_id' => $supplier->id,
                    'accounts_payable_id' => $payable?->id,
                    'amount' => $amount,
                    'label' => $supplier->supplier_name,
                ];
            }
        }

        if ($particulars !== '') {
            $account = $this->matchExpenseAccount($particulars, $expenseAccounts);

            if ($account !== null) {
                return [
                    'type' => 'expense',
                    'expense_account_id' => $account->id,
                    'description' => $particulars,
                    'amount' => $amount,
                    'label' => $account->name,
                ];
            }
        }

        return null;
    }

    /** @return array{0: ?ChartOfAccount, 1: bool} account (or null if none exist at all), and whether it was a confident match vs. a default guess */
    private function resolveCashAccount(array $cashRow, Collection $cashAccounts): array
    {
        if ($cashAccounts->isEmpty()) {
            return [null, false];
        }

        if ($cashAccounts->count() === 1) {
            return [$cashAccounts->first(), false];
        }

        $needle = trim(($cashRow['particulars'] ?? '').' '.($cashRow['account'] ?? ''));
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

        return $best !== null && $bestScore >= self::CASH_ACCOUNT_MATCH_THRESHOLD
            ? [$best, false]
            : [$cashAccounts->first(), true];
    }

    /** Tries the full text and each comma-separated segment against every supplier name — legacy PARTICULARS embeds the supplier name amid other free text in varying positions (e.g. "HUTANG SUPPLIER, PT. Conch South Kalimantan Cement, BANK BCA 1312"), so no single fixed slice is reliable across files. */
    private function matchSupplier(string $text, Collection $suppliers): ?Supplier
    {
        if ($suppliers->isEmpty() || trim($text) === '') {
            return null;
        }

        $segments = array_unique(array_filter(array_map('trim', [$text, ...explode(',', $text)]), fn ($s) => $s !== ''));

        $best = null;
        $bestScore = 0.0;

        foreach ($suppliers as $supplier) {
            $normalizedSupplier = $this->normalizeForMatch($supplier->supplier_name);

            foreach ($segments as $segment) {
                similar_text($this->normalizeForMatch($segment), $normalizedSupplier, $percent);
                if ($percent > $bestScore) {
                    $bestScore = $percent;
                    $best = $supplier;
                }
            }
        }

        return $bestScore >= self::SUPPLIER_MATCH_THRESHOLD ? $best : null;
    }

    private function matchExpenseAccount(string $particulars, Collection $expenseAccounts): ?ChartOfAccount
    {
        if ($expenseAccounts->isEmpty()) {
            return null;
        }

        $needle = $this->normalizeForMatch($particulars);
        $best = null;
        $bestScore = 0.0;

        foreach ($expenseAccounts as $account) {
            similar_text($needle, $this->normalizeForMatch($account->name), $percent);
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $account;
            }
        }

        return $bestScore >= self::EXPENSE_MATCH_THRESHOLD ? $best : null;
    }

    /**
     * Closest still-outstanding bill for this supplier, by nominal amount — "fuzzy match by
     * ... nominal" per the ticket. Smallest |outstanding - amount| among payables whose
     * outstanding balance can actually cover this line (assertWithinOutstanding must hold),
     * so an exact-amount match always wins when one exists.
     */
    private function findOpenPayable(Supplier $supplier, float $amount): ?AccountsPayable
    {
        $candidates = AccountsPayable::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', '!=', AccountsPayableStatus::PAID->value)
            ->orderBy('due_date')
            ->get();

        $best = null;
        $bestDiff = null;

        foreach ($candidates as $candidate) {
            $outstanding = (float) $candidate->amount - (float) $candidate->paid_amount;

            if ($outstanding < $amount - self::AMOUNT_EPSILON) {
                continue;
            }

            $diff = abs($outstanding - $amount);

            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $candidate;
            }
        }

        return $best;
    }

    /** Uppercases, strips common Indonesian legal-entity prefixes (PT/CV/UD/Tbk) and punctuation — raises match quality between e.g. "PT. Conch South Kalimantan Cement" and a PARTICULARS mention of the same name with different casing/punctuation. */
    private function normalizeForMatch(string $value): string
    {
        $value = strtoupper($value);
        $value = preg_replace('/\b(PT|CV|UD|TBK)\b\.?/', '', $value) ?? $value;
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
