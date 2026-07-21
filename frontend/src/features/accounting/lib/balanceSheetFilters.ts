import { toDateString } from './profitLossFilters'
import type { BalanceSheetFilterValues } from '../types'

/** A Balance Sheet is a point-in-time snapshot — no period preset, unlike Trial Balance/Profit & Loss. See docs/BALANCE_SHEET_DESIGN.md §7. */
export const emptyBalanceSheetFilters: BalanceSheetFilterValues = {
  asOfDate: toDateString(new Date()),
  branchId: null,
  companyId: null,
}

export function hasActiveBalanceSheetFilters(filters: BalanceSheetFilterValues): boolean {
  return filters.asOfDate !== emptyBalanceSheetFilters.asOfDate || filters.branchId !== null || filters.companyId !== null
}
