<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "System side" of the reconciliation -- Payment Voucher (outflow, credit) and
 * Official Receipt (inflow, debit) against a bank account, grouped by day.
 * Reads payment_entries/receipt_entries directly, same precedent as
 * CashBookRepository, not journal_entries -- matches the ticket's own framing
 * of PV/OR as the "system side", not the full general ledger.
 */
class BankReconciliationRepository
{
    /** @return array<string, array{debit: float, credit: float}> keyed by Y-m-d */
    public function systemTotalsByDate(string $bankAccountId, string $dateFrom, string $dateTo): array
    {
        // whereDate(), not whereBetween() with plain 'Y-m-d' bounds -- a 'date'-cast
        // column is still stored as a full "Y-m-d 00:00:00" string on sqlite, which
        // sorts *after* a same-day 'Y-m-d' upper bound and silently drops same-day rows.
        $receipts = DB::table('receipt_entries')
            ->where('cash_account_id', $bankAccountId)
            ->where('status', DocumentStatus::SUBMITTED->value)
            ->whereDate('receipt_date', '>=', $dateFrom)
            ->whereDate('receipt_date', '<=', $dateTo)
            ->whereNull('deleted_at')
            ->selectRaw('receipt_date as date, SUM(total_amount) as total')
            ->groupBy('receipt_date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->format('Y-m-d'));

        $payments = DB::table('payment_entries')
            ->where('cash_account_id', $bankAccountId)
            ->where('status', DocumentStatus::SUBMITTED->value)
            ->whereDate('payment_date', '>=', $dateFrom)
            ->whereDate('payment_date', '<=', $dateTo)
            ->whereNull('deleted_at')
            ->selectRaw('payment_date as date, SUM(total_amount) as total')
            ->groupBy('payment_date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->format('Y-m-d'));

        $dates = $receipts->keys()->merge($payments->keys())->unique();

        return $dates->mapWithKeys(fn ($date) => [$date => [
            'debit' => (float) ($receipts[$date]->total ?? 0),
            'credit' => (float) ($payments[$date]->total ?? 0),
        ]])->all();
    }
}
