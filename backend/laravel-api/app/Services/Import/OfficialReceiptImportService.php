<?php

namespace App\Services\Import;

use App\Enums\AccountsReceivableStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\PaymentMethod;
use App\Models\AccountsReceivable;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ReceiptEntry;
use App\Services\Import\Concerns\ParsesLegacyLedgerExport;
use App\Services\PaymentAllocationService;
use App\Services\ReceiptEntryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Smart, one-click Official Receipt import — the Accounts Receivable mirror
 * of PaymentVoucherImportService. Same legacy export shape (4 title rows,
 * DOCUMENT # groups of double-entry rows), just the transaction direction
 * flipped: the cash/bank leg sits on the DEBIT side here (money arriving),
 * and the customer/receivable leg sits on the CREDIT side — opposite of
 * Payment Voucher. Shares column-detection/row-grouping/fuzzy-name-matching
 * with it via ParsesLegacyLedgerExport.
 *
 * ReceiptEntry has no "general"/"mixed" fallback type the way PaymentEntry
 * does (see PaymentEntry::create()'s three payment_type branches) — every
 * receipt belongs to exactly one Customer, always. So unlike Payment
 * Voucher, a voucher whose customer can't be matched at all — or whose
 * receivable rows resolve to more than one different customer — cannot be
 * created as anything and is reported as failed for that voucher only,
 * never blocking the rest of the batch.
 */
final class OfficialReceiptImportService
{
    use ParsesLegacyLedgerExport;

    private const AMOUNT_EPSILON = 0.01;

    private const CUSTOMER_MATCH_THRESHOLD = 55.0;

    public function __construct(
        protected ReceiptEntryService $receiptEntryService,
        protected PaymentAllocationService $paymentAllocationService,
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

        $customers = Customer::query()->where('is_active', true)->get();
        $cashAccounts = ChartOfAccount::query()->where('is_cash_bank', true)->where('is_active', true)->get();

        $report = [];
        $success = 0;
        $failed = 0;
        $needsReview = 0;

        foreach ($groups as $documentNumber => $rows) {
            $outcome = $this->processGroup((string) $documentNumber, $rows, $customers, $cashAccounts);
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

    private function processGroup(string $documentNumber, array $rows, Collection $customers, Collection $cashAccounts): array
    {
        $base = ['document_number' => $documentNumber];

        if (ReceiptEntry::query()->where('reference_number', $documentNumber)->exists()) {
            return [...$base, 'status' => 'needs_review', 'reason' => 'Nomor dokumen ini sudah pernah diimpor sebelumnya — dilewati.'];
        }

        if (($mismatch = $this->ledgerBalanceMismatch($rows)) !== null) {
            return [...$base, 'status' => 'failed', 'reason' => $mismatch];
        }

        // Official Receipt: the cash/bank leg sits on the DEBIT side (money arriving); the
        // customer/receivable leg sits on the CREDIT side — the mirror image of Payment Voucher.
        ['cash' => $cashRows, 'party' => $receivableRows] = $this->classifyLedgerRows($rows, cashSide: 'debit');

        if (count($cashRows) !== 1) {
            return [...$base, 'status' => 'failed', 'reason' => count($cashRows) === 0
                ? 'Tidak ditemukan baris kas/bank (baris ber-DEBIT) dalam grup ini.'
                : 'Ditemukan lebih dari satu baris kas/bank dalam grup ini — tidak bisa ditentukan otomatis.'];
        }

        if ($receivableRows === []) {
            return [...$base, 'status' => 'failed', 'reason' => 'Tidak ada baris piutang (baris ber-CREDIT) dalam grup ini.'];
        }

        $cashRow = $cashRows[0];
        $totalAmount = (float) $cashRow['debit'];

        [$cashAccount, $cashGuessed] = $this->resolveLedgerCashAccount($cashRow, $cashAccounts);

        if ($cashAccount === null) {
            return [...$base, 'status' => 'failed', 'reason' => 'Tidak ada akun Kas/Bank aktif (is_cash_bank) di Chart of Accounts — tidak bisa membuat voucher apa pun.'];
        }

        $receiptDate = $cashRow['date'] ?? collect($rows)->pluck('date')->filter()->first();

        if ($receiptDate === null) {
            return [...$base, 'status' => 'failed', 'reason' => 'Tanggal penerimaan tidak terbaca pada grup ini.'];
        }

        $paymentMethod = $this->inferPaymentMethod($cashRow);

        // Every receivable row must resolve to the SAME customer — ReceiptEntry has no
        // multi-customer/"mixed" shape the way a Payment Voucher does (see class docblock).
        $matches = array_map(fn ($row) => ['row' => $row, 'customer' => $this->matchLedgerPartyByName(
            (string) ($row['particulars'] ?? ''), $customers, fn (Customer $c) => $c->customer_name, self::CUSTOMER_MATCH_THRESHOLD,
        )], $receivableRows);

        $distinctCustomerIds = collect($matches)->pluck('customer.id')->filter()->unique();

        if ($distinctCustomerIds->isEmpty()) {
            return [...$base, 'status' => 'failed', 'reason' => 'Customer tidak ditemukan untuk voucher ini — tidak bisa dibuat.'];
        }

        if ($distinctCustomerIds->count() > 1) {
            return [...$base, 'status' => 'failed', 'reason' => 'Grup piutang ini melibatkan lebih dari satu customer — satu Official Receipt hanya untuk satu customer.'];
        }

        $customer = $customers->firstWhere('id', $distinctCustomerIds->first());

        try {
            return DB::transaction(function () use ($base, $matches, $customer, $cashAccount, $cashGuessed, $receiptDate, $totalAmount, $paymentMethod, $documentNumber) {
                $entry = $this->receiptEntryService->create([
                    'customer_id' => $customer->id,
                    'receipt_date' => $receiptDate,
                    'cash_account_id' => $cashAccount->id,
                    'reference_number' => $documentNumber,
                    'total_amount' => $totalAmount,
                    'payment_method' => $paymentMethod->value,
                ]);
                $entry = $this->receiptEntryService->submit($entry);

                $notes = $cashGuessed ? ['Akun Kas/Bank dipilih otomatis (default) — mohon verifikasi.'] : [];

                $allocationLines = [];
                $unresolvedNotes = [];

                foreach ($matches as $item) {
                    $row = $item['row'];
                    $amount = (float) $row['credit'];
                    $receivable = $this->findOpenReceivable($customer, $amount);

                    if ($receivable !== null) {
                        $allocationLines[] = ['accounts_receivable_id' => $receivable->id, 'amount' => $amount];
                    } else {
                        $unresolvedNotes[] = sprintf('Rp %s (%s)', number_format($amount, 0, ',', '.'), $row['particulars'] ?: ($row['account'] ?: '—'));
                    }
                }

                if ($allocationLines !== []) {
                    $this->paymentAllocationService->allocateBatch($entry, $allocationLines);
                } else {
                    $notes[] = 'Tidak ada tagihan (Accounts Receivable) terbuka yang cocok untuk customer ini — voucher dibuat berstatus Unallocated.';
                }

                if ($unresolvedNotes !== []) {
                    $notes[] = 'Sebagian baris tidak cocok otomatis, dicatat sebagai belum teralokasi: '.implode(', ', $unresolvedNotes).'.';
                }

                return [...$base, 'status' => $notes === [] ? 'success' : 'needs_review', 'reason' => $notes === [] ? null : implode(' ', $notes), 'receipt_entry_id' => $entry->id];
            });
        } catch (Throwable $e) {
            return [...$base, 'status' => 'failed', 'reason' => $e->getMessage()];
        }
    }

    /**
     * The legacy export's CHEQUE #/COLLECTION column never holds a real cheque number here
     * (per the ticket: it's a bank-destination code like "bgdanamon210058" or "BCA KE SMD") —
     * any non-blank value is treated as a bank transfer, blank as cash. Never auto-infers
     * cheque/giro/qris/credit_card: there's no reliable signal for those in this export, and
     * StoreReceiptEntryRequest requires a giro_number/giro_due_date we simply don't have for
     * cheque/giro, so guessing wrong would only trade one review flag for a worse one.
     */
    private function inferPaymentMethod(array $cashRow): PaymentMethod
    {
        return trim((string) ($cashRow['secondary_reference'] ?? '')) !== ''
            ? PaymentMethod::BANK_TRANSFER
            : PaymentMethod::CASH;
    }

    /**
     * Closest still-outstanding, invoice-originated receivable for this customer, by nominal
     * amount — "fuzzy match by ... nominal" per the ticket, mirrors PaymentVoucherImportService::
     * findOpenPayable() exactly. PaymentAllocationService::allocateBatch() requires
     * invoice_id !== null (assertOriginatesFromInvoice()), so that's filtered here rather than
     * discovered as an allocation failure later.
     */
    private function findOpenReceivable(Customer $customer, float $amount): ?AccountsReceivable
    {
        $candidates = AccountsReceivable::query()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', AccountsReceivableStatus::PAID->value)
            ->whereNotNull('invoice_id')
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
