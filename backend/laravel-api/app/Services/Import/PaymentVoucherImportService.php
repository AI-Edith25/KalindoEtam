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
use App\Services\Import\Concerns\ParsesLegacyLedgerExport;
use App\Services\PaymentEntryAllocationService;
use App\Services\PaymentEntryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Smart, one-click Payment Voucher import — no mapping/preview wizard (see
 * ImportBatchService/ImportTemplate for that shape). A legacy voucher export
 * is a double-entry journal: several rows share one DOCUMENT #, one row is
 * the cash/bank leg (CREDIT-side), the rest are allocation/expense legs
 * (DEBIT-side). ImportTemplate assumes one file row = one persisted record,
 * which this data plainly isn't — so this reuses ImportFileReader/
 * HeaderDetector (the parsing/header-detection primitives) directly instead
 * of forcing the row-shaped framework to fit. Shares its column-detection/
 * row-grouping/fuzzy-name-matching machinery with OfficialReceiptImportService
 * (the AR mirror of this — same source shape, opposite cash-leg side) via
 * ParsesLegacyLedgerExport; only what's genuinely different (which side is
 * cash, what the "other" leg resolves to, and PaymentEntry's three
 * payment_type variants) lives here.
 *
 * Supplier/expense-account matching is best-effort (PHP's own similar_text —
 * legacy codes don't correspond to this system's chart at all, see
 * ChartOfAccountsSeeder) — a voucher that can't be fully resolved is still
 * created (Draft, or Submitted-but-Unallocated/partially applied), never
 * dropped, and reported for manual review.
 */
final class PaymentVoucherImportService
{
    use ParsesLegacyLedgerExport;

    private const AMOUNT_EPSILON = 0.01;

    private const SUPPLIER_MATCH_THRESHOLD = 55.0;

    private const EXPENSE_MATCH_THRESHOLD = 60.0;

    public function __construct(
        protected PaymentEntryService $paymentEntryService,
        protected PaymentEntryAllocationService $paymentEntryAllocationService,
    ) {}

    public function import(ImportBatch $batch): void
    {
        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);
        $absolutePath = Storage::disk($batch->disk)->path($batch->file_path);
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        $fields = $this->ledgerFieldDefinitions();
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
        $groups = $this->groupLedgerRows($this->parseLedgerRows($dataRows, $columnIndex));

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

    private function processGroup(string $documentNumber, array $rows, Collection $suppliers, Collection $cashAccounts, Collection $expenseAccounts): array
    {
        $base = ['document_number' => $documentNumber];

        if (PaymentEntry::query()->where('reference_number', $documentNumber)->exists()) {
            return [...$base, 'status' => 'needs_review', 'reason' => 'Nomor dokumen ini sudah pernah diimpor sebelumnya — dilewati.'];
        }

        if (($mismatch = $this->ledgerBalanceMismatch($rows)) !== null) {
            return [...$base, 'status' => 'failed', 'reason' => $mismatch];
        }

        // Payment Voucher: the cash/bank leg sits on the CREDIT side (money leaving cash);
        // allocation/expense legs sit on the DEBIT side. Official Receipt is the mirror image.
        ['cash' => $cashRows, 'party' => $allocationRows] = $this->classifyLedgerRows($rows, cashSide: 'credit');

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

        [$cashAccount, $cashGuessed] = $this->resolveLedgerCashAccount($cashRow, $cashAccounts);

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
            $supplier = $this->matchLedgerPartyByName($particulars !== '' ? $particulars : $slCode, $suppliers, fn (Supplier $s) => $s->supplier_name, self::SUPPLIER_MATCH_THRESHOLD);

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
            $account = $this->matchLedgerPartyByName($particulars, $expenseAccounts, fn (ChartOfAccount $a) => $a->name, self::EXPENSE_MATCH_THRESHOLD);

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
}
