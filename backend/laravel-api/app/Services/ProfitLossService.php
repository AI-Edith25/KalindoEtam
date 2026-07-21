<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\ProfitLossSection;
use App\Enums\ReportStatementType;
use App\Models\ChartOfAccount;
use App\Repositories\ReportAccountMappingRepository;
use Illuminate\Support\Facades\Log;

/**
 * A thin presentation layer over GeneralLedgerService — the only balance
 * computation anywhere in this class is the delegated
 * GeneralLedgerService::listAccounts() call. Unlike Trial Balance, this
 * report uses each account's period movement only, never opening_balance/
 * ending_balance — see docs/PROFIT_LOSS_DESIGN.md §3.
 */
class ProfitLossService
{
    public function __construct(
        protected GeneralLedgerService $generalLedgerService,
        protected ReportAccountMappingRepository $reportAccountMappingRepository,
    ) {}

    public function summarize(array $filters): array
    {
        $ledgerRows = $this->generalLedgerService->listAccounts($filters);
        $mappings = $this->reportAccountMappingRepository->forStatementType(ReportStatementType::PROFIT_LOSS);

        $this->warnAboutUnmappedAccounts($ledgerRows, $mappings);

        $buckets = [];
        foreach (ProfitLossSection::cases() as $section) {
            $buckets[$section->value] = ['lines' => [], 'subtotal' => 0.0];
        }

        foreach ($ledgerRows as $row) {
            $mapping = $mappings[$row['account']->id] ?? null;
            if (! $mapping) {
                continue; // unmapped Asset/Liability/Equity accounts, or a not-yet-mapped Revenue/Expense account
            }

            $section = ProfitLossSection::from($mapping->section);
            $movement = $this->periodMovement($row['account'], $row['debit'], $row['credit']);
            // A contra account's own type can disagree with its assigned section's convention
            // (Discount Given is expense/debit-normal but mapped into Revenue as a deduction) —
            // flip the sign so it nets against the section rather than adding to it.
            $contribution = $row['account']->isDebitNormal() === $section->isDebitNormal() ? $movement : -$movement;

            $buckets[$section->value]['subtotal'] += $contribution;

            if (round($contribution, 2) !== 0.0) {
                $buckets[$section->value]['lines'][] = ['account' => $row['account'], 'amount' => $contribution];
            }
        }

        $sections = array_map(fn (ProfitLossSection $section) => [
            'key' => $section->value,
            'label' => $section->label(),
            'lines' => $buckets[$section->value]['lines'],
            'subtotal' => round($buckets[$section->value]['subtotal'], 2),
        ], ProfitLossSection::cases());

        $subtotals = array_column($sections, 'subtotal', 'key');

        $grossProfit = round($subtotals[ProfitLossSection::REVENUE->value] - $subtotals[ProfitLossSection::COST_OF_GOODS_SOLD->value], 2);
        $operatingIncome = round($grossProfit - $subtotals[ProfitLossSection::OPERATING_EXPENSE->value], 2);
        $netProfitBeforeTax = round($operatingIncome + $subtotals[ProfitLossSection::OTHER_INCOME->value] - $subtotals[ProfitLossSection::OTHER_EXPENSE->value], 2);

        // No Tax Expense account or tax-calculation concept exists anywhere in this codebase yet
        // (docs/PROFIT_LOSS_DESIGN.md Open Question 1) — null, not 0.0, so the UI can distinguish
        // "not configured" from "computed to zero."
        $tax = null;
        $netProfit = round($netProfitBeforeTax - ($tax ?? 0.0), 2);

        return [
            'sections' => $sections,
            'gross_profit' => $grossProfit,
            'operating_income' => $operatingIncome,
            'net_profit_before_tax' => $netProfitBeforeTax,
            'tax' => $tax,
            'net_profit' => $netProfit,
        ];
    }

    /**
     * Same formula as GeneralLedgerService::signedMovement() and
     * TrialBalanceService's placeBalance() — reused, not reinvented. Never
     * adds opening_balance, unlike Trial Balance (see docs/PROFIT_LOSS_DESIGN.md §3).
     */
    protected function periodMovement(ChartOfAccount $account, float $debit, float $credit): float
    {
        return $account->isDebitNormal() ? $debit - $credit : $credit - $debit;
    }

    /**
     * Visibility for developers when a Revenue/Expense account has no
     * ReportAccountMapping row for this statement type — the report still
     * renders (that account is just omitted), but nothing vanishes silently.
     */
    protected function warnAboutUnmappedAccounts(array $ledgerRows, array $mappings): void
    {
        foreach ($ledgerRows as $row) {
            $account = $row['account'];
            $isReportable = in_array($account->account_type, [AccountType::REVENUE, AccountType::EXPENSE], true);

            if ($isReportable && ! isset($mappings[$account->id])) {
                Log::warning("ProfitLossService: account {$account->code} ({$account->name}) has no report_account_mappings row for statement_type=profit_loss — omitted from the report.");
            }
        }
    }
}
