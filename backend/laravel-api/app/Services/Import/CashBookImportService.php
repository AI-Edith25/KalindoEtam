<?php

namespace App\Services\Import;

use App\Enums\AccountsPayableStatus;
use App\Enums\AccountsReceivableStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\PaymentMethod;
use App\Models\AccountsPayable;
use App\Models\AccountsReceivable;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\PaymentEntry;
use App\Models\ReceiptEntry;
use App\Models\Supplier;
use App\Services\Import\Concerns\ParsesLegacyLedgerExport;
use App\Services\PaymentAllocationService;
use App\Services\PaymentEntryAllocationService;
use App\Services\PaymentEntryService;
use App\Services\ReceiptEntryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Smart, one-click Cash Book import — re-imports THIS system's own Journal
 * List > Cash Book export (JournalListExport), not the older external
 * "Payment Voucher Listing"/"Official Receipt Listing" files
 * PaymentVoucherImportService/OfficialReceiptImportService already handle.
 * Same double-entry-group shape (Transaction/Date/Particulars/Debit/Credit),
 * just without that file's separate ACCOUNT/SL CODE/CHEQUE columns — this
 * export instead embeds the account code directly in Particulars
 * ("{code} - {name} - [{remark}]", see JournalListExport::particulars()),
 * which this class parses out and resolves against chart_of_accounts by
 * EXACT code instead of fuzzy name — more precise than the two importers
 * above, which have no code to work with at all.
 *
 * Reuses ParsesLegacyLedgerExport's column-detection/grouping/fuzzy-name-
 * matching (mapColumns/classifyLedgerRows/matchLedgerPartyByName/
 * ledgerBalanceMismatch/groupLedgerRows) — the direction-agnostic half of
 * that trait — but not its ledgerFieldDefinitions()/parseLedgerRows()
 * (built for the other file's 9-column shape) or PV/OR's own
 * processGroup()/resolveAllocationLine() (gated on a SL CODE column this
 * file doesn't have).
 *
 * Every real Cash Book group is exactly ReceiptEntry::journalLines() or
 * PaymentEntry::journalLines()/mixedJournalLines() shape: one cash/bank
 * leg + 1+ "party" legs, where the party leg's account code alone says
 * what it is — 1150 (Unapplied Customer Payments) = Receipt, 1250
 * (Advance to Suppliers) = Payment-to-supplier, anything else = a genuine
 * expense account (Payment, resolved directly by that code, no fuzzy
 * matching needed). Direction itself comes from which side the cash leg
 * sits on (debit = Receipt, credit = Payment — true for all 3 PaymentEntry
 * variants), not from which of those codes appears, so a "Cash Book" (all)
 * file mixing both directions in one sheet is handled per group, not
 * per file.
 */
final class CashBookImportService
{
    use ParsesLegacyLedgerExport;

    private const AMOUNT_EPSILON = 0.01;

    private const CUSTOMER_MATCH_THRESHOLD = 55.0;

    private const SUPPLIER_MATCH_THRESHOLD = 55.0;

    private const EXPENSE_MATCH_THRESHOLD = 60.0;

    /** Account codes ReceiptEntry/PaymentEntry always post their non-cash leg to — see class docblock. */
    private const RECEIVABLE_SUSPENSE_CODE = '1150';

    private const PAYABLE_SUSPENSE_CODE = '1250';

    public function __construct(
        protected PaymentEntryService $paymentEntryService,
        protected PaymentEntryAllocationService $paymentEntryAllocationService,
        protected ReceiptEntryService $receiptEntryService,
        protected PaymentAllocationService $paymentAllocationService,
    ) {}

    /**
     * Peeks at just the section-label row (see class docblock / looksLikeGroupLabelRow()) without
     * doing anything else — used by CashBookImportController::store() to confirm the uploaded
     * file actually matches the Journal Type selected in the UI before an ImportBatch/job is even
     * created. Null when the file's required columns can't be detected at all, or when no such
     * row is found — either way there's nothing to compare, so the caller's own required-column
     * check (inside import()) is left to report that properly once the batch exists.
     */
    public function detectGroupLabel(string $absolutePath, string $extension): ?string
    {
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);
        $fields = $this->cashBookFieldDefinitions();
        $headerSettings = HeaderDetector::detect($rawRows, $fields);
        $headerRow = $rawRows[$headerSettings['header_row'] - 1] ?? [];
        $columnIndex = $this->mapColumns($headerRow, $fields);

        if (! isset($columnIndex['document_number'], $columnIndex['date'], $columnIndex['debit'], $columnIndex['credit'])) {
            return null;
        }

        $candidate = $rawRows[$headerSettings['data_start_row'] - 1] ?? [];

        return $this->looksLikeGroupLabelRow($candidate, $columnIndex)
            ? DataCleaner::normalizeText((string) ($candidate[$columnIndex['document_number']] ?? ''))
            : null;
    }

    public function import(ImportBatch $batch): void
    {
        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);
        $absolutePath = Storage::disk($batch->disk)->path($batch->file_path);
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        $fields = $this->cashBookFieldDefinitions();
        $headerSettings = HeaderDetector::detect($rawRows, $fields);
        $headerRow = $rawRows[$headerSettings['header_row'] - 1] ?? [];
        $columnIndex = $this->mapColumns($headerRow, $fields);

        $requiredLabels = ['document_number' => 'Transaction', 'date' => 'Date', 'debit' => 'Debit', 'credit' => 'Credit'];
        $missing = array_values(array_diff_key($requiredLabels, $columnIndex));

        if ($missing !== []) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => 'Kolom wajib tidak terdeteksi di file ini: '.implode(', ', $missing).'.',
            ]);

            return;
        }

        // Row right after the column header, one cell wide (the "Cash Book Transaction"/
        // "Cash Book-Receipt"/"Cash Book-Payment" section label JournalListExport writes to A6) —
        // not itself real data, so it's skipped here rather than left for parseCashBookRows() to
        // (harmlessly, since it has no Transaction/amount) drop as noise. Mismatch against what
        // the caller expected was already checked before this job was even queued — see
        // CashBookImportController::store().
        $dataStartIndex = $headerSettings['data_start_row'] - 1;
        if ($this->looksLikeGroupLabelRow($rawRows[$dataStartIndex] ?? [], $columnIndex)) {
            $dataStartIndex++;
        }

        $trailerRow = $this->findTrailerRow($rawRows, $dataStartIndex);
        $dataRows = array_slice($rawRows, $dataStartIndex, $trailerRow !== null ? $trailerRow - $dataStartIndex : null);

        $groups = $this->groupLedgerRows($this->parseCashBookRows($dataRows, $columnIndex));

        if ($groups === []) {
            $batch->update([
                'status' => ImportBatchStatus::FAILED,
                'failure_reason' => 'Tidak ada baris data yang bisa dibaca dari file ini.',
            ]);

            return;
        }

        if ($trailerRow !== null && ($mismatch = $this->trailerChecksumMismatch($rawRows[$trailerRow], $columnIndex, $groups)) !== null) {
            $batch->update(['status' => ImportBatchStatus::FAILED, 'failure_reason' => $mismatch]);

            return;
        }

        $batch->update(['total_rows' => count($groups), 'status' => ImportBatchStatus::PROCESSING, 'started_at' => now()]);

        $accountsByCode = ChartOfAccount::query()->where('is_active', true)->get()->keyBy('code');
        $suppliers = Supplier::query()->where('is_active', true)->get();
        $customers = Customer::query()->where('is_active', true)->get();

        $report = [];
        $success = 0;
        $failed = 0;
        $needsReview = 0;

        foreach ($groups as $documentNumber => $rows) {
            $outcome = $this->processGroup((string) $documentNumber, $rows, $accountsByCode, $suppliers, $customers);
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
    private function cashBookFieldDefinitions(): array
    {
        return [
            new ImportFieldDefinition('document_number', 'Transaction', 'string', required: true, synonyms: ['document #', 'trans #', 'no transaksi', 'voucher no']),
            new ImportFieldDefinition('date', 'Date', 'date', required: true, synonyms: ['tanggal', 'tgl']),
            new ImportFieldDefinition('secondary_reference', 'Notes', 'string', synonyms: ['ref. 1 #', 'ref 1 #', 'reference', 'no referensi']),
            new ImportFieldDefinition('particulars', 'Particulars', 'string', synonyms: ['keterangan', 'description', 'uraian']),
            new ImportFieldDefinition('debit', 'Debit', 'number', required: true, synonyms: ['dr', 'debet']),
            new ImportFieldDefinition('credit', 'Credit', 'number', required: true, synonyms: ['cr', 'kredit']),
        ];
    }

    /**
     * A group-label row has a value in the Transaction column but is blank everywhere a real data
     * row must have a value (Date, Debit, Credit) — that combination never happens for an actual
     * transaction line (parseCashBookRows drops any row missing Date entirely, and every posted
     * line has a non-zero Debit or Credit), so it's an unambiguous, format-agnostic signal.
     */
    private function looksLikeGroupLabelRow(array $row, array $columnIndex): bool
    {
        $transaction = DataCleaner::blankToNull($row[$columnIndex['document_number']] ?? null);
        $date = DataCleaner::blankToNull($row[$columnIndex['date']] ?? null);
        $debit = DataCleaner::blankToNull($row[$columnIndex['debit']] ?? null);
        $credit = DataCleaner::blankToNull($row[$columnIndex['credit']] ?? null);

        return $transaction !== null && $date === null && $debit === null && $credit === null;
    }

    /**
     * The file's own "Total For :[...]" trailer — same shape as the group-label row (one label
     * cell, blank Date) but sitting at the very end with real Debit/Credit totals instead of blank
     * ones, and always the last non-blank row in the file. Returns its raw-row index, or null if
     * no such row is found (an older/hand-edited file, say) — the checksum check is then simply
     * skipped rather than blocking the import over a row shape it can't confirm either way.
     */
    private function findTrailerRow(array $rawRows, int $dataStartIndex): ?int
    {
        for ($i = count($rawRows) - 1; $i >= $dataStartIndex; $i--) {
            $row = $rawRows[$i];
            $firstCell = DataCleaner::blankToNull($row[0] ?? null);

            if ($firstCell !== null && is_string($firstCell) && stripos($firstCell, 'Total For') === 0) {
                return $i;
            }
        }

        return null;
    }

    private function trailerChecksumMismatch(array $trailerRow, array $columnIndex, array $groups): ?string
    {
        $expectedDebit = DataCleaner::normalizeNumber($trailerRow[$columnIndex['debit']] ?? null);
        $expectedCredit = DataCleaner::normalizeNumber($trailerRow[$columnIndex['credit']] ?? null);

        if ($expectedDebit === null || $expectedCredit === null) {
            return null;
        }

        $actualDebit = 0.0;
        $actualCredit = 0.0;
        foreach ($groups as $rows) {
            $actualDebit += array_sum(array_column($rows, 'debit'));
            $actualCredit += array_sum(array_column($rows, 'credit'));
        }

        if (abs($actualDebit - $expectedDebit) <= self::AMOUNT_EPSILON && abs($actualCredit - $expectedCredit) <= self::AMOUNT_EPSILON) {
            return null;
        }

        return sprintf(
            'Total hasil parsing (Debit Rp %s, Credit Rp %s) tidak cocok dengan baris Total di file (Debit Rp %s, Credit Rp %s) — ada baris yang kemungkinan salah terbaca. Import dibatalkan, periksa kembali file-nya.',
            number_format($actualDebit, 0, ',', '.'), number_format($actualCredit, 0, ',', '.'),
            number_format($expectedDebit, 0, ',', '.'), number_format($expectedCredit, 0, ',', '.'),
        );
    }

    /** @return array<int, array{document_number: string, date: ?string, particulars: ?string, account_code: ?string, secondary_reference: ?string, debit: float, credit: float}> */
    private function parseCashBookRows(array $dataRows, array $columnIndex): array
    {
        $decimalValues = [];
        foreach (['debit', 'credit'] as $field) {
            foreach ($dataRows as $row) {
                $decimalValues[] = $row[$columnIndex[$field]] ?? null;
            }
        }
        $decimalStyle = DataCleaner::detectDecimalStyle($decimalValues);

        $parsed = [];
        $lastDocumentNumber = null;

        foreach ($dataRows as $raw) {
            $get = fn (string $field) => isset($columnIndex[$field]) ? ($raw[$columnIndex[$field]] ?? null) : null;

            $documentNumber = DataCleaner::normalizeText($this->rawToStringOrNull($get('document_number')));
            $documentNumber ??= $lastDocumentNumber;
            if ($documentNumber !== null) {
                $lastDocumentNumber = $documentNumber;
            }

            $date = $this->parseCashBookDate($get('date'));
            $debit = DataCleaner::normalizeNumber($get('debit'), $decimalStyle) ?? 0.0;
            $credit = DataCleaner::normalizeNumber($get('credit'), $decimalStyle) ?? 0.0;

            if ($documentNumber === null || $date === null || ($debit < self::AMOUNT_EPSILON && $credit < self::AMOUNT_EPSILON)) {
                continue;
            }

            [$accountCode, $remark] = $this->splitParticulars((string) ($get('particulars') ?? ''));

            $parsed[] = [
                'document_number' => $documentNumber,
                'date' => $date,
                'account' => $accountCode,
                'sl_code' => null,
                'particulars' => $remark,
                'secondary_reference' => DataCleaner::normalizeText($this->rawToStringOrNull($get('secondary_reference'))),
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        return $parsed;
    }

    private function parseCashBookDate(mixed $raw): ?string
    {
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        return DataCleaner::normalizeDate($raw === null ? null : (string) $raw);
    }

    /**
     * "{code} - {name} - [{remark}]" (JournalListExport::particulars()) → the leading code, and a
     * fuzzy-match-ready version of the remark: ';' and ' - ' are both normalized to ',' so
     * matchLedgerPartyByName's own comma-segment splitting also tries ReceiptEntry's own
     * "{customer}; {cash account}" convention and PaymentEntry's "{label} - {supplier}" one,
     * not just a single whole-string match against a lot of surrounding noise.
     *
     * @return array{0: ?string, 1: string}
     */
    private function splitParticulars(string $particulars): array
    {
        if (preg_match('/^(\S+)\s*-\s*.*?\[(.*)\]\s*$/', trim($particulars), $matches) !== 1) {
            return [null, trim($particulars)];
        }

        $remark = str_replace([';', ' - '], ',', $matches[2]);

        return [$matches[1], trim($remark)];
    }

    private function rawToStringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function processGroup(string $documentNumber, array $rows, Collection $accountsByCode, Collection $suppliers, Collection $customers): array
    {
        $base = ['document_number' => $documentNumber];

        if (PaymentEntry::query()->where('reference_number', $documentNumber)->exists()
            || ReceiptEntry::query()->where('reference_number', $documentNumber)->exists()) {
            return [...$base, 'status' => 'needs_review', 'reason' => 'Nomor dokumen ini sudah pernah diimpor sebelumnya — dilewati.'];
        }

        if (($mismatch = $this->ledgerBalanceMismatch($rows)) !== null) {
            return [...$base, 'status' => 'failed', 'reason' => $mismatch];
        }

        $resolved = array_map(fn ($row) => [...$row, 'resolved_account' => $row['account'] !== null ? $accountsByCode->get($row['account']) : null], $rows);

        $cashRows = array_values(array_filter($resolved, fn ($r) => $r['resolved_account']?->is_cash_bank === true));
        $otherRows = array_values(array_filter($resolved, fn ($r) => ($r['resolved_account']?->is_cash_bank ?? false) !== true));

        if (count($cashRows) !== 1) {
            return [...$base, 'status' => 'failed', 'reason' => count($cashRows) === 0
                ? 'Tidak ditemukan baris kas/bank (kode akun ber-atribut Cash/Bank) yang bisa dikenali dalam grup ini.'
                : 'Ditemukan lebih dari satu baris kas/bank dalam grup ini — tidak bisa ditentukan otomatis.'];
        }

        if ($otherRows === []) {
            return [...$base, 'status' => 'failed', 'reason' => 'Tidak ada baris pasangan (party) dalam grup ini.'];
        }

        $cashRow = $cashRows[0];
        $cashAccount = $cashRow['resolved_account'];
        // Same convention every PaymentEntry/ReceiptEntry variant already posts by (see class
        // docblock): cash debited = money arriving = Receipt; cash credited = money leaving =
        // Payment. True regardless of which account the OTHER leg(s) resolve to.
        $direction = $cashRow['debit'] > self::AMOUNT_EPSILON ? 'receipt' : 'payment';
        $totalAmount = $direction === 'receipt' ? (float) $cashRow['debit'] : (float) $cashRow['credit'];
        $date = $cashRow['date'] ?? collect($rows)->pluck('date')->filter()->first();

        if ($date === null) {
            return [...$base, 'status' => 'failed', 'reason' => 'Tanggal tidak terbaca pada grup ini.'];
        }

        return $direction === 'receipt'
            ? $this->processReceiptGroup($base, $documentNumber, $otherRows, $customers, $cashAccount, $totalAmount, $date, $cashRow)
            : $this->processPaymentGroup($base, $documentNumber, $otherRows, $suppliers, $accountsByCode, $cashAccount, $totalAmount, $date);
    }

    /** @param array<int, array> $otherRows every non-cash row, all expected to resolve to the SAME customer — see OfficialReceiptImportService's own docblock for why ReceiptEntry has no multi-customer shape. */
    private function processReceiptGroup(array $base, string $documentNumber, array $otherRows, Collection $customers, ChartOfAccount $cashAccount, float $totalAmount, string $date, array $cashRow): array
    {
        $matches = array_map(fn ($row) => ['row' => $row, 'customer' => $this->matchLedgerPartyByName(
            (string) ($row['particulars'] ?? ''), $customers, fn (Customer $c) => $c->customer_name, self::CUSTOMER_MATCH_THRESHOLD,
        )], $otherRows);

        $distinctCustomerIds = collect($matches)->pluck('customer.id')->filter()->unique();

        if ($distinctCustomerIds->isEmpty()) {
            return [...$base, 'status' => 'needs_review', 'reason' => 'Customer tidak ditemukan untuk transaksi ini — perlu dicocokkan manual.'];
        }

        if ($distinctCustomerIds->count() > 1) {
            return [...$base, 'status' => 'failed', 'reason' => 'Grup ini melibatkan lebih dari satu customer — satu Official Receipt hanya untuk satu customer.'];
        }

        $customer = $customers->firstWhere('id', $distinctCustomerIds->first());
        $paymentMethod = trim((string) ($cashRow['secondary_reference'] ?? '')) !== '' ? PaymentMethod::BANK_TRANSFER : PaymentMethod::CASH;

        try {
            return DB::transaction(function () use ($base, $matches, $customer, $cashAccount, $date, $totalAmount, $paymentMethod, $documentNumber) {
                $entry = $this->receiptEntryService->create([
                    'customer_id' => $customer->id,
                    'receipt_date' => $date,
                    'cash_account_id' => $cashAccount->id,
                    'reference_number' => $documentNumber,
                    'total_amount' => $totalAmount,
                    'payment_method' => $paymentMethod->value,
                ]);
                $entry = $this->receiptEntryService->submit($entry);

                $notes = [];
                $allocationLines = [];
                $unresolvedNotes = [];

                foreach ($matches as $item) {
                    $row = $item['row'];
                    $amount = (float) $row['credit'];
                    $receivable = $this->findOpenReceivable($customer, $amount);

                    if ($receivable !== null) {
                        $allocationLines[] = ['accounts_receivable_id' => $receivable->id, 'amount' => $amount];
                    } else {
                        $unresolvedNotes[] = sprintf('Rp %s', number_format($amount, 0, ',', '.'));
                    }
                }

                if ($allocationLines !== []) {
                    $this->paymentAllocationService->allocateBatch($entry, $allocationLines);
                } else {
                    $notes[] = 'Tidak ada tagihan (Accounts Receivable) terbuka yang cocok — dibuat berstatus Unallocated.';
                }

                if ($unresolvedNotes !== []) {
                    $notes[] = 'Sebagian baris tidak cocok otomatis, belum teralokasi: '.implode(', ', $unresolvedNotes).'.';
                }

                return [...$base, 'status' => $notes === [] ? 'success' : 'needs_review', 'reason' => $notes === [] ? null : implode(' ', $notes), 'receipt_entry_id' => $entry->id];
            });
        } catch (Throwable $e) {
            return [...$base, 'status' => 'failed', 'reason' => $e->getMessage()];
        }
    }

    /** @param array<int, array> $otherRows one or more debit-side rows — a 1250 (supplier) row per matched supplier line, or a real expense account row, mirroring PaymentEntry's plain/mixed shapes. */
    private function processPaymentGroup(array $base, string $documentNumber, array $otherRows, Collection $suppliers, Collection $accountsByCode, ChartOfAccount $cashAccount, float $totalAmount, string $date): array
    {
        $resolved = array_map(fn ($row) => ['row' => $row, 'line' => $this->resolvePaymentLine($row, $suppliers, $accountsByCode)], $otherRows);

        try {
            return DB::transaction(function () use ($base, $resolved, $cashAccount, $date, $totalAmount, $documentNumber) {
                if (count($resolved) === 1) {
                    $line = $resolved[0]['line'];

                    if ($line !== null && $line['type'] === 'supplier') {
                        $entry = $this->paymentEntryService->create([
                            'payment_type' => 'supplier',
                            'supplier_id' => $line['supplier_id'],
                            'payment_date' => $date,
                            'cash_account_id' => $cashAccount->id,
                            'reference_number' => $documentNumber,
                            'amount' => $totalAmount,
                        ]);
                        $entry = $this->paymentEntryService->submit($entry);

                        if ($line['accounts_payable_id'] !== null) {
                            $this->paymentEntryAllocationService->allocateBatch($entry, [
                                ['accounts_payable_id' => $line['accounts_payable_id'], 'amount' => $totalAmount],
                            ]);

                            return [...$base, 'status' => 'success', 'reason' => null, 'payment_entry_id' => $entry->id];
                        }

                        return [...$base, 'status' => 'needs_review', 'reason' => 'Tidak ada tagihan (Accounts Payable) terbuka yang cocok — dibuat berstatus Unallocated.', 'payment_entry_id' => $entry->id];
                    }

                    if ($line !== null && $line['type'] === 'expense') {
                        $entry = $this->paymentEntryService->create([
                            'payment_type' => 'general_expense',
                            'expense_account_id' => $line['expense_account_id'],
                            'description' => $line['description'],
                            'payment_date' => $date,
                            'cash_account_id' => $cashAccount->id,
                            'reference_number' => $documentNumber,
                            'amount' => $totalAmount,
                        ]);
                        $entry = $this->paymentEntryService->submit($entry);

                        return [...$base, 'status' => 'success', 'reason' => null, 'payment_entry_id' => $entry->id];
                    }

                    return [...$base, 'status' => 'needs_review', 'reason' => 'Baris ini tidak bisa dicocokkan otomatis — perlu dibuat manual.'];
                }

                $entry = $this->paymentEntryService->create([
                    'payment_type' => 'mixed',
                    'payment_date' => $date,
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
                        $unresolvedNotes[] = sprintf('Rp %s (%s)', number_format($row['debit'], 0, ',', '.'), $row['particulars'] ?: '—');
                    }
                }

                if ($submitLines === []) {
                    return [...$base, 'status' => 'needs_review', 'reason' => 'Tidak ada baris yang bisa dicocokkan otomatis — dibuat sebagai Draft, lengkapi manual.', 'payment_entry_id' => $entry->id];
                }

                $entry = $this->paymentEntryService->submit($entry, $submitLines);
                $notes = $unresolvedNotes !== [] ? ['Sebagian baris tidak cocok otomatis, belum teralokasi: '.implode(', ', $unresolvedNotes).'.'] : [];

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
    private function resolvePaymentLine(array $row, Collection $suppliers, Collection $accountsByCode): ?array
    {
        $amount = (float) $row['debit'];
        $account = $row['resolved_account'];
        $particulars = (string) ($row['particulars'] ?? '');

        if ($account?->code === self::PAYABLE_SUSPENSE_CODE) {
            $supplier = $this->matchLedgerPartyByName($particulars, $suppliers, fn (Supplier $s) => $s->supplier_name, self::SUPPLIER_MATCH_THRESHOLD);

            if ($supplier === null) {
                return null;
            }

            $payable = $this->findOpenPayable($supplier, $amount);

            return [
                'type' => 'supplier',
                'supplier_id' => $supplier->id,
                'accounts_payable_id' => $payable?->id,
                'amount' => $amount,
                'label' => $supplier->supplier_name,
            ];
        }

        // Any other resolved account is a real expense account — the code already identifies it
        // exactly, no fuzzy name matching needed (unlike PV's original legacy-file importer, which
        // never had a code to work with at all).
        if ($account !== null) {
            return [
                'type' => 'expense',
                'expense_account_id' => $account->id,
                'description' => $particulars ?: $account->name,
                'amount' => $amount,
                'label' => $account->name,
            ];
        }

        // Code didn't resolve to any known account — last-resort fuzzy match by name, same as
        // PaymentVoucherImportService's fallback for an unrecognized legacy code.
        $fallback = $this->matchLedgerPartyByName($particulars, $accountsByCode, fn (ChartOfAccount $a) => $a->name, self::EXPENSE_MATCH_THRESHOLD);

        if ($fallback === null) {
            return null;
        }

        return [
            'type' => 'expense',
            'expense_account_id' => $fallback->id,
            'description' => $particulars,
            'amount' => $amount,
            'label' => $fallback->name,
        ];
    }

    /** Mirrors PaymentVoucherImportService::findOpenPayable() exactly — see its docblock. */
    private function findOpenPayable(Supplier $supplier, float $amount): ?AccountsPayable
    {
        $candidates = AccountsPayable::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', '!=', AccountsPayableStatus::PAID->value)
            ->orderBy('due_date')
            ->get();

        return $this->closestOutstanding($candidates, $amount);
    }

    /** Mirrors OfficialReceiptImportService::findOpenReceivable() exactly — see its docblock. */
    private function findOpenReceivable(Customer $customer, float $amount): ?AccountsReceivable
    {
        $candidates = AccountsReceivable::query()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', AccountsReceivableStatus::PAID->value)
            ->whereNotNull('invoice_id')
            ->orderBy('due_date')
            ->get();

        return $this->closestOutstanding($candidates, $amount);
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, T>  $candidates  each with amount/paid_amount columns
     * @return T|null
     */
    private function closestOutstanding(Collection $candidates, float $amount): mixed
    {
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
