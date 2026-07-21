import type { CashFlowFilterValues } from '../types'

/**
 * Deliberately duplicated from profitLossFilters.ts's empty-filters shape
 * rather than reused — same "own filter set" discipline ProfitLossFilters
 * itself already applies relative to Trial Balance. resolvePeriodPreset()/
 * resolveFiscalYearStart()/toDateString() ARE reused directly from
 * profitLossFilters.ts (identical date-preset math, not reinvented) — see
 * CashFlowListPage.
 */
export const emptyCashFlowFilters: CashFlowFilterValues = {
  periodPreset: 'this_month',
  dateFrom: '',
  dateTo: '',
  branchId: null,
  companyId: null,
}

export function hasActiveCashFlowFilters(filters: CashFlowFilterValues): boolean {
  return (
    filters.periodPreset !== emptyCashFlowFilters.periodPreset ||
    filters.dateFrom !== '' ||
    filters.dateTo !== '' ||
    filters.branchId !== null ||
    filters.companyId !== null
  )
}
