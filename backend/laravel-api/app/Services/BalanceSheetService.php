<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\BalanceSheetSection;
use App\Enums\ReportStatementType;
use App\Repositories\CompanyRepository;
use App\Repositories\ReportAccountMappingRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * A thin presentation layer over GeneralLedgerService and ProfitLossService
 * — the only balance computation in this class is the delegated
 * GeneralLedgerService::listAccounts() call; Current Year Profit and the
 * historical component of Retained Earnings are both obtained directly from
 * ProfitLossService::summarize(), never re-derived. One-way dependency only:
 * GeneralLedgerService -> ProfitLossService -> BalanceSheetService. Neither
 * ProfitLossService nor GeneralLedgerService knows this class exists. See
 * docs/BALANCE_SHEET_DESIGN.md.
 */
class BalanceSheetService
{
    public function __construct(
        protected GeneralLedgerService $generalLedgerService,
        protected ReportAccountMappingRepository $reportAccountMappingRepository,
        protected ProfitLossService $profitLossService,
        protected CompanyRepository $companyRepository,
    ) {}

    public function summarize(array $filters): array
    {
        $asOfDate = $filters['as_of_date'];

        $ledgerFilters = array_intersect_key($filters, array_flip(['branch_id', 'company_id', 'status', 'reference_type']));
        $ledgerFilters['date_to'] = $asOfDate; // date_from deliberately omitted — unbounded, cumulative ending_balance (§7)

        $ledgerRows = $this->generalLedgerService->listAccounts($ledgerFilters);
        $mappings = $this->reportAccountMappingRepository->forStatementType(ReportStatementType::BALANCE_SHEET);

        $this->warnAboutUnmappedAccounts($ledgerRows, $mappings);

        $buckets = [];
        foreach (BalanceSheetSection::cases() as $section) {
            $buckets[$section->value] = ['lines' => [], 'subtotal' => 0.0];
        }

        foreach ($ledgerRows as $row) {
            $mapping = $mappings[$row['account']->id] ?? null;
            if (! $mapping) {
                continue; // unmapped Revenue/Expense accounts, or a not-yet-mapped Asset/Liability/Equity account
            }

            $section = BalanceSheetSection::from($mapping->section);
            $endingBalance = $row['ending_balance'];
            // Same contra-account protection as ProfitLossSection::isDebitNormal() — flips the
            // sign so an account whose own type disagrees with its section's convention nets
            // against the section rather than adding to it (§4).
            $contribution = $row['account']->isDebitNormal() === $section->isDebitNormal() ? $endingBalance : -$endingBalance;

            $buckets[$section->value]['subtotal'] += $contribution;

            if (round($contribution, 2) !== 0.0) {
                $buckets[$section->value]['lines'][] = ['account' => $row['account'], 'amount' => $contribution];
            }
        }

        $mappedSubtotal = fn (BalanceSheetSection $section) => round($buckets[$section->value]['subtotal'], 2);

        $totalAssets = round($mappedSubtotal(BalanceSheetSection::CURRENT_ASSET) + $mappedSubtotal(BalanceSheetSection::NON_CURRENT_ASSET), 2);
        $totalLiabilities = round($mappedSubtotal(BalanceSheetSection::CURRENT_LIABILITY) + $mappedSubtotal(BalanceSheetSection::LONG_TERM_LIABILITY), 2);
        $shareCapital = $mappedSubtotal(BalanceSheetSection::SHARE_CAPITAL);

        [$currentYearProfit, $priorYearsProfit] = $this->profitAndRetainedEarnings($asOfDate, $filters);

        // Retained Earnings, until Period Closing exists: the mapped account's real balance
        // (today always 0 — nothing has ever posted to it) plus every prior fiscal year's P&L
        // net profit, kept visibly separate from Current Year Profit (§6).
        $retainedEarnings = round($mappedSubtotal(BalanceSheetSection::RETAINED_EARNINGS) + $priorYearsProfit, 2);
        $totalEquity = round($shareCapital + $retainedEarnings + $currentYearProfit, 2);
        $totalLiabilitiesAndEquity = round($totalLiabilities + $totalEquity, 2);

        $sections = array_map(fn (BalanceSheetSection $section) => [
            'key' => $section->value,
            'label' => $section->label(),
            'lines' => $buckets[$section->value]['lines'],
            // The Retained Earnings section renders the combined figure (mapped balance + prior
            // years' P&L), not just the raw mapped-account subtotal — §6/§9.
            'subtotal' => $section === BalanceSheetSection::RETAINED_EARNINGS ? $retainedEarnings : round($buckets[$section->value]['subtotal'], 2),
        ], BalanceSheetSection::cases());

        $isBalanced = $totalAssets === $totalLiabilitiesAndEquity;

        if (! $isBalanced) {
            Log::warning("BalanceSheetService: accounting equation does not balance as of {$asOfDate} — Total Assets {$totalAssets} != Total Liabilities + Equity {$totalLiabilitiesAndEquity}.");
        }

        return [
            'as_of_date' => $asOfDate,
            'sections' => $sections,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'share_capital' => $shareCapital,
            'retained_earnings' => $retainedEarnings,
            'current_year_profit' => round($currentYearProfit, 2),
            'total_equity' => $totalEquity,
            'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
            'is_balanced' => $isBalanced,
        ];
    }

    /**
     * Current Year Profit and the prior-years component of Retained Earnings — both are direct
     * ProfitLossService::summarize() calls with different date ranges, never a re-derivation of
     * P&L's own section/contra-sign logic (§5/§6). The two ranges are contiguous and
     * non-overlapping (everything before the fiscal year, then the fiscal year to date), so
     * together they always equal the true cumulative net income since inception.
     *
     * @return array{0: float, 1: float} [currentYearProfit, priorYearsProfit]
     */
    protected function profitAndRetainedEarnings(string $asOfDate, array $filters): array
    {
        $fiscalYearStart = $this->resolveFiscalYearStart($asOfDate, $filters['company_id'] ?? null);
        $plFilters = array_intersect_key($filters, array_flip(['branch_id', 'company_id']));

        $currentYearProfit = $this->profitLossService->summarize($plFilters + [
            'date_from' => $fiscalYearStart,
            'date_to' => $asOfDate,
        ])['net_profit'];

        // date_from deliberately omitted — unbounded back to inception (§6).
        $priorYearsProfit = $this->profitLossService->summarize($plFilters + [
            'date_to' => Carbon::parse($fiscalYearStart)->subDay()->toDateString(),
        ])['net_profit'];

        return [$currentYearProfit, $priorYearsProfit];
    }

    /**
     * Which fiscal year contains $asOfDate, anchored to Company.fiscal_year_start's month/day —
     * the same anchoring math the frontend's resolveFiscalYearStart() already does for its own
     * "This Fiscal Year" preset, ported server-side and parameterized by $asOfDate instead of
     * "now" (§5), since BalanceSheetService needs this boundary internally, not just as a UI
     * default. Falls back to 1 January of $asOfDate's year when no company / no
     * fiscal_year_start is configured, matching the frontend's own fallback.
     */
    protected function resolveFiscalYearStart(string $asOfDate, ?string $companyId): string
    {
        $asOf = Carbon::parse($asOfDate);
        $company = $this->companyRepository->defaultOrById($companyId);

        if (! $company?->fiscal_year_start) {
            return $asOf->copy()->startOfYear()->toDateString();
        }

        $fiscalYearStart = $company->fiscal_year_start;
        $anchored = Carbon::create($asOf->year, $fiscalYearStart->month, $fiscalYearStart->day);

        return ($anchored->greaterThan($asOf) ? $anchored->subYear() : $anchored)->toDateString();
    }

    /**
     * Visibility for developers when an Asset/Liability/Equity account has no
     * ReportAccountMapping row for this statement type — the report still renders (that account
     * is just omitted), but nothing vanishes silently. Same mechanism as
     * ProfitLossService::warnAboutUnmappedAccounts(), scoped to Balance Sheet's own account
     * types.
     */
    protected function warnAboutUnmappedAccounts(array $ledgerRows, array $mappings): void
    {
        foreach ($ledgerRows as $row) {
            $account = $row['account'];
            $isReportable = in_array($account->account_type, [AccountType::ASSET, AccountType::LIABILITY, AccountType::EQUITY], true);

            if ($isReportable && ! isset($mappings[$account->id])) {
                Log::warning("BalanceSheetService: account {$account->code} ({$account->name}) has no report_account_mappings row for statement_type=balance_sheet — omitted from the report.");
            }
        }
    }
}
