<?php

namespace App\Services;

use App\Models\ChartOfAccount;

/**
 * A thin presentation layer over GeneralLedgerService — the only balance
 * computation anywhere in this class is the delegated
 * GeneralLedgerService::listAccounts() call. See docs/TRIAL_BALANCE_DESIGN.md.
 */
class TrialBalanceService
{
    public function __construct(protected GeneralLedgerService $generalLedgerService) {}

    /**
     * @return array{rows: array<int, array{account: ChartOfAccount, debit: float, credit: float}>, total_debit: float, total_credit: float, is_balanced: bool}
     */
    public function summarize(array $filters): array
    {
        $ledgerRows = $this->generalLedgerService->listAccounts($filters);

        $rows = array_map(function (array $ledgerRow) {
            $placed = $this->placeBalance($ledgerRow['account'], $ledgerRow['ending_balance']);

            return [
                'account' => $ledgerRow['account'],
                'debit' => $placed['debit'],
                'credit' => $placed['credit'],
            ];
        }, $ledgerRows);

        $totalDebit = round(array_sum(array_column($rows, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($rows, 'credit')), 2);

        return [
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => $totalDebit === $totalCredit,
        ];
    }

    /**
     * Places an already-computed, signed ending_balance (from
     * GeneralLedgerService::listAccounts(), unchanged) into the Debit or
     * Credit column a traditional Trial Balance expects. A positive balance
     * on an account's own normal side is the expected case; a negative one
     * is an abnormal balance (e.g. an overpaid receivable) and is placed in
     * the opposite column instead of hidden or clamped to zero.
     */
    protected function placeBalance(ChartOfAccount $account, float $endingBalance): array
    {
        $isDebitNormal = $account->isDebitNormal();
        $onNormalSide = $endingBalance >= 0;

        return [
            'debit' => $onNormalSide === $isDebitNormal ? abs($endingBalance) : 0.0,
            'credit' => $onNormalSide === $isDebitNormal ? 0.0 : abs($endingBalance),
        ];
    }
}
