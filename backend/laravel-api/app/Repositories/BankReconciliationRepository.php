<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use App\Models\PaymentEntry;
use App\Models\ReceiptEntry;
use Illuminate\Support\Collection;

/**
 * "System side" of the reconciliation -- every submitted Official Receipt/Payment Voucher,
 * grouped by day. The day-total aggregate (systemTotalsByDate()) still combines every account
 * into one bucket, unchanged since the migration that dropped bank_account_id from this feature;
 * only the Detail tab's per-account matching table (BankReconciliationService::matchingRows())
 * filters cashBookRows() by bank_account_id. Reads receipt_entries/payment_entries
 * directly rather than via journal_entries: every field the Detail tab needs (document number,
 * date, reference number, cash/bank account) is already a plain column on these two documents,
 * so there is no journal join, no "which line is the bank leg", and no particulars/description
 * reconstruction needed at all.
 *
 * Amounts use bank-statement convention (debit = uang keluar, credit = uang masuk -- see
 * BcaStatementParser/MandiriStatementParser), the opposite of ledger/asset convention, so a
 * Payment Voucher lands in 'debit' here and an Official Receipt lands in 'credit' -- this is
 * what makes these totals directly comparable to BankStatementLine's debit_amount/credit_amount
 * without inversion.
 */
class BankReconciliationRepository
{
    /**
     * One row per submitted Official Receipt/Payment Voucher in range. 'bank_account'/
     * 'bank_account_id' are display/filter fields only (see the migration above) -- the
     * aggregate day totals below never split by account; only the Detail tab's per-account
     * matching table (BankReconciliationService::matchingRows()) filters on bank_account_id.
     *
     * @return array<int, array{document_number: ?string, date: string, reference_number: ?string, bank_account: ?string, bank_account_id: ?string, tipe: 'masuk'|'keluar', debit: float, kredit: float}>
     */
    public function cashBookRows(string $dateFrom, string $dateTo): array
    {
        $receipts = ReceiptEntry::query()
            ->where('status', DocumentStatus::SUBMITTED)
            ->whereDate('receipt_date', '>=', $dateFrom)
            ->whereDate('receipt_date', '<=', $dateTo)
            ->with('cashAccount')
            ->get()
            ->map(fn (ReceiptEntry $receipt) => [
                'document_number' => $receipt->document_number,
                'date' => $receipt->receipt_date->format('Y-m-d'),
                'reference_number' => $receipt->reference_number,
                'bank_account' => $receipt->cashAccount?->name,
                'bank_account_id' => $receipt->cash_account_id,
                'tipe' => 'masuk',
                'debit' => 0.0,
                'kredit' => (float) $receipt->total_amount,
            ]);

        $payments = PaymentEntry::query()
            ->where('status', DocumentStatus::SUBMITTED)
            ->whereDate('payment_date', '>=', $dateFrom)
            ->whereDate('payment_date', '<=', $dateTo)
            ->with('cashAccount')
            ->get()
            ->map(fn (PaymentEntry $payment) => [
                'document_number' => $payment->document_number,
                'date' => $payment->payment_date->format('Y-m-d'),
                'reference_number' => $payment->reference_number,
                'bank_account' => $payment->cashAccount?->name,
                'bank_account_id' => $payment->cash_account_id,
                'tipe' => 'keluar',
                'debit' => (float) $payment->total_amount,
                'kredit' => 0.0,
            ]);

        return $receipts->concat($payments)->sortBy('date')->values()->all();
    }

    /** @return array<string, array{debit: float, credit: float}> keyed by Y-m-d */
    public function systemTotalsByDate(string $dateFrom, string $dateTo): array
    {
        return collect($this->cashBookRows($dateFrom, $dateTo))
            ->groupBy('date')
            ->map(fn (Collection $rows) => [
                'debit' => $rows->sum('debit'),
                'credit' => $rows->sum('kredit'),
            ])
            ->all();
    }
}
