<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\CashFlowSection;
use App\Enums\ReportStatementType;
use App\Repositories\ReportAccountMappingRepository;
use Illuminate\Support\Facades\Log;

/**
 * A thin presentation layer over GeneralLedgerService and ProfitLossService
 * — the only balance computation in this class is the delegated
 * GeneralLedgerService::listAccounts() call, which already returns both
 * opening_balance and ending_balance per account in one call; Net Profit
 * for the Period is obtained directly from ProfitLossService::summarize(),
 * never re-derived. One-way dependency only: GeneralLedgerService ->
 * ProfitLossService -> CashFlowService. BalanceSheetService is deliberately
 * never called — its point-in-time snapshot shape doesn't fit a period
 * report, and calling GeneralLedgerService/ProfitLossService directly is
 * both cheaper and architecturally cleaner (§3). Neither ProfitLossService,
 * GeneralLedgerService, nor BalanceSheetService knows this class exists.
 * See docs/CASH_FLOW_DESIGN.md.
 */
class CashFlowService
{
    public function __construct(
        protected GeneralLedgerService $generalLedgerService,
        protected ReportAccountMappingRepository $reportAccountMappingRepository,
        protected ProfitLossService $profitLossService,
    ) {}

    public function summarize(array $filters): array
    {
        $plFilters = array_intersect_key($filters, array_flip(['date_from', 'date_to', 'branch_id', 'company_id']));
        $netProfit = $this->profitLossService->summarize($plFilters)['net_profit'];

        $ledgerFilters = array_intersect_key($filters, array_flip(['branch_id', 'company_id', 'status', 'reference_type']));
        $ledgerFilters['date_from'] = $filters['date_from'];
        if (! empty($filters['date_to'])) {
            $ledgerFilters['date_to'] = $filters['date_to'];
        }

        // The one call this entire report is built on — opening_balance AND ending_balance for
        // every account, in a single pass. See docs/CASH_FLOW_DESIGN.md §0/§9.
        $ledgerRows = $this->generalLedgerService->listAccounts($ledgerFilters);
        $mappings = $this->reportAccountMappingRepository->forStatementType(ReportStatementType::CASH_FLOW);

        $this->warnAboutUnmappedAccounts($ledgerRows, $mappings);

        $buckets = [];
        foreach (CashFlowSection::cases() as $section) {
            $buckets[$section->value] = ['lines' => [], 'subtotal' => 0.0];
        }

        $openingCash = 0.0;
        $closingCashFromLedger = 0.0;

        foreach ($ledgerRows as $row) {
            $mapping = $mappings[$row['account']->id] ?? null;
            if (! $mapping) {
                continue; // unmapped Revenue/Expense accounts, or a not-yet-mapped Asset/Liability/Equity account
            }

            $section = CashFlowSection::from($mapping->section);

            if ($section === CashFlowSection::CASH_AND_EQUIVALENTS) {
                $openingCash += $row['opening_balance'];
                $closingCashFromLedger += $row['ending_balance'];

                continue;
            }

            $change = $row['ending_balance'] - $row['opening_balance'];
            // The mirror image of every prior report's contribution formula: an asset's increase
            // is a cash USE (negative), a liability/equity's increase is a cash SOURCE (positive).
            // Same isDebitNormal() primitive every prior report reuses, opposite polarity. See §3/§8.
            $contribution = $row['account']->isDebitNormal() ? -$change : $change;

            $buckets[$section->value]['subtotal'] += $contribution;

            if (round($contribution, 2) !== 0.0) {
                $buckets[$section->value]['lines'][] = ['account' => $row['account'], 'amount' => $contribution];
            }
        }

        $operatingAdjustment = round($buckets[CashFlowSection::OPERATING_ADJUSTMENT->value]['subtotal'], 2);
        $investingActivity = round($buckets[CashFlowSection::INVESTING_ACTIVITY->value]['subtotal'], 2);
        $financingActivity = round($buckets[CashFlowSection::FINANCING_ACTIVITY->value]['subtotal'], 2);

        $netCashFromOperating = round($netProfit + $operatingAdjustment, 2);
        $netCashMovement = round($netCashFromOperating + $investingActivity + $financingActivity, 2);

        $openingCash = round($openingCash, 2);
        $closingCash = round($openingCash + $netCashMovement, 2);
        $closingCashFromLedger = round($closingCashFromLedger, 2);

        $isBalanced = $closingCash === $closingCashFromLedger;

        if (! $isBalanced) {
            $rangeLabel = $filters['date_from'].' to '.($filters['date_to'] ?? 'present');
            Log::warning("CashFlowService: accounting equation does not balance for {$rangeLabel} — Opening Cash {$openingCash} + Net Cash Movement {$netCashMovement} = {$closingCash}, but the actual cash account balance is {$closingCashFromLedger}.");
        }

        return [
            'net_profit' => round($netProfit, 2),
            'operating' => [
                'key' => CashFlowSection::OPERATING_ADJUSTMENT->value,
                'label' => CashFlowSection::OPERATING_ADJUSTMENT->label(),
                'lines' => $buckets[CashFlowSection::OPERATING_ADJUSTMENT->value]['lines'],
                'net_cash' => $netCashFromOperating,
            ],
            'investing' => [
                'key' => CashFlowSection::INVESTING_ACTIVITY->value,
                'label' => CashFlowSection::INVESTING_ACTIVITY->label(),
                'lines' => $buckets[CashFlowSection::INVESTING_ACTIVITY->value]['lines'],
                'net_cash' => $investingActivity,
            ],
            'financing' => [
                'key' => CashFlowSection::FINANCING_ACTIVITY->value,
                'label' => CashFlowSection::FINANCING_ACTIVITY->label(),
                'lines' => $buckets[CashFlowSection::FINANCING_ACTIVITY->value]['lines'],
                'net_cash' => $financingActivity,
            ],
            'net_cash_movement' => $netCashMovement,
            'opening_cash' => $openingCash,
            'closing_cash' => $closingCash,
            'is_balanced' => $isBalanced,
        ];
    }

    /**
     * Visibility for developers when an Asset/Liability/Equity account has no
     * ReportAccountMapping row for this statement type — the report still renders (that account
     * is just omitted), but nothing vanishes silently. Same mechanism as
     * ProfitLossService/BalanceSheetService's own warnAboutUnmappedAccounts().
     */
    protected function warnAboutUnmappedAccounts(array $ledgerRows, array $mappings): void
    {
        foreach ($ledgerRows as $row) {
            $account = $row['account'];
            $isReportable = in_array($account->account_type, [AccountType::ASSET, AccountType::LIABILITY, AccountType::EQUITY], true);

            if ($isReportable && ! isset($mappings[$account->id])) {
                Log::warning("CashFlowService: account {$account->code} ({$account->name}) has no report_account_mappings row for statement_type=cash_flow — omitted from the report.");
            }
        }
    }
}
