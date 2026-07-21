<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Repositories\ChartOfAccountRepository;
use App\Repositories\GeneralLedgerRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The General Ledger is a read model — no create/update/delete/submit here,
 * unlike every other service in this codebase. Every figure is derived
 * fresh from journal_entries/journal_entry_lines on each call, never
 * cached, never written back. See docs/GENERAL_LEDGER_DESIGN.md §1.
 */
class GeneralLedgerService
{
    public function __construct(
        protected GeneralLedgerRepository $generalLedgerRepository,
        protected ChartOfAccountRepository $chartOfAccountRepository,
    ) {}

    /**
     * Ledger List — one row per Chart of Account: opening balance, in-range
     * debit/credit totals, ending balance. Two grouped aggregate queries
     * total (not one query per account), merged in PHP against the full
     * account list so an account with zero activity in range still gets a
     * row (opening = ending, debit = credit = 0).
     *
     * @return array<int, array{account: ChartOfAccount, opening_balance: float, debit: float, credit: float, ending_balance: float}>
     */
    public function listAccounts(array $filters): array
    {
        $accounts = $this->chartOfAccountRepository->allOrderedByCode();
        $opening = $this->generalLedgerRepository->openingTotalsByAccount($filters);
        $period = $this->generalLedgerRepository->periodTotalsByAccount($filters);

        return $accounts->map(function (ChartOfAccount $account) use ($opening, $period) {
            $openingRow = $opening[$account->id] ?? ['debit' => 0.0, 'credit' => 0.0];
            $periodRow = $period[$account->id] ?? ['debit' => 0.0, 'credit' => 0.0];

            $openingBalance = $this->signedMovement($account, $openingRow['debit'], $openingRow['credit']);
            $periodMovement = $this->signedMovement($account, $periodRow['debit'], $periodRow['credit']);

            return [
                'account' => $account,
                'opening_balance' => $openingBalance,
                'debit' => $periodRow['debit'],
                'credit' => $periodRow['credit'],
                'ending_balance' => $openingBalance + $periodMovement,
            ];
        })->all();
    }

    /**
     * Ledger Detail / Account Drill-down — opening balance, ending balance
     * (both independent of which page is being viewed), and one page of
     * deterministically-ordered lines with a running balance attached to
     * each. Page N>1's starting point is derived via one extra aggregate
     * query (§6 of the design), never by re-summing every prior page.
     *
     * @return array{account: ChartOfAccount, opening_balance: float, ending_balance: float, paginator: LengthAwarePaginator}
     */
    public function accountLedger(ChartOfAccount $account, array $filters, int $perPage): array
    {
        $scopedFilters = ['account_id' => $account->id] + $filters;

        $opening = $this->generalLedgerRepository->openingTotalForAccount($account->id, $scopedFilters);
        $openingBalance = $this->signedMovement($account, $opening['debit'], $opening['credit']);

        $period = $this->generalLedgerRepository->periodTotalForAccount($account->id, $scopedFilters);
        $endingBalance = $openingBalance + $this->signedMovement($account, $period['debit'], $period['credit']);

        $paginator = $this->generalLedgerRepository->linesForAccount($account->id, $scopedFilters, $perPage);
        $items = $paginator->items();

        $runningStart = $openingBalance;
        if ($paginator->currentPage() > 1 && count($items) > 0) {
            $first = $items[0];
            $before = $this->generalLedgerRepository->totalBeforeCursor(
                $account->id,
                $scopedFilters,
                $first->journalEntry->posting_date->toDateString(),
                $first->journalEntry->document_number,
                (string) $first->id,
            );
            $runningStart = $openingBalance + $this->signedMovement($account, $before['debit'], $before['credit']);
        }

        $runningBalance = $runningStart;
        foreach ($items as $line) {
            $runningBalance += $this->signedMovement($account, (float) $line->debit, (float) $line->credit);
            $line->running_balance = $runningBalance;
        }

        return [
            'account' => $account,
            'opening_balance' => $openingBalance,
            'ending_balance' => $endingBalance,
            'paginator' => $paginator,
        ];
    }

    /**
     * The entire sign-convention "posting logic" this module has, reused
     * identically for opening balance, every line's running balance, and
     * ending balance — see docs/GENERAL_LEDGER_DESIGN.md §3. Debit-normal
     * (Asset/Expense) balances increase on debit; credit-normal
     * (Liability/Equity/Revenue) balances increase on credit.
     */
    protected function signedMovement(ChartOfAccount $account, float $debit, float $credit): float
    {
        return $account->isDebitNormal() ? $debit - $credit : $credit - $debit;
    }
}
