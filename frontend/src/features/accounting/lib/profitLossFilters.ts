import type { Company } from '@/features/master/types'
import type { ProfitLossFilterValues } from '../types'

/**
 * Deliberately duplicated from trialBalanceFilters.ts rather than reused —
 * Trial Balance is a frozen module this sprint, so this file makes zero
 * edits to it. Same preset logic and the same local-date-formatting fix
 * (never toISOString(), which silently shifts the date in any timezone
 * ahead of UTC, e.g. this app's Asia/Jakarta).
 */
export const emptyProfitLossFilters: ProfitLossFilterValues = {
  periodPreset: 'this_month',
  dateFrom: '',
  dateTo: '',
  branchId: null,
  companyId: null,
}

export function hasActiveProfitLossFilters(filters: ProfitLossFilterValues): boolean {
  return (
    filters.periodPreset !== emptyProfitLossFilters.periodPreset ||
    filters.dateFrom !== '' ||
    filters.dateTo !== '' ||
    filters.branchId !== null ||
    filters.companyId !== null
  )
}

export function toDateString(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

/** Exported for Balance Sheet's Current Year Profit drill-down link, which needs the same fiscal-year-start anchoring, not a duplicate implementation. See docs/BALANCE_SHEET_DESIGN.md §9. */
export function resolveFiscalYearStart(companies: Company[], selectedCompanyId: string | null): Date {
  const company = (selectedCompanyId ? companies.find((c) => c.id === selectedCompanyId) : companies[0]) ?? null
  if (company?.fiscal_year_start) {
    const [y, m, d] = company.fiscal_year_start.split('-').map(Number)
    const start = new Date(y, m - 1, d)
    const now = new Date()
    const anchored = new Date(now.getFullYear(), start.getMonth(), start.getDate())
    return anchored > now ? new Date(now.getFullYear() - 1, start.getMonth(), start.getDate()) : anchored
  }

  return new Date(new Date().getFullYear(), 0, 1)
}

/** Resolves a Reporting Period preset to a date_from/date_to pair. Custom Range returns the filter's own dates unchanged. */
export function resolvePeriodPreset(
  filters: ProfitLossFilterValues,
  companies: Company[],
): { dateFrom: string; dateTo: string } {
  const now = new Date()

  switch (filters.periodPreset) {
    case 'this_month': {
      const from = new Date(now.getFullYear(), now.getMonth(), 1)
      const to = new Date(now.getFullYear(), now.getMonth() + 1, 0)
      return { dateFrom: toDateString(from), dateTo: toDateString(to) }
    }
    case 'this_quarter': {
      const quarterStartMonth = Math.floor(now.getMonth() / 3) * 3
      const from = new Date(now.getFullYear(), quarterStartMonth, 1)
      const to = new Date(now.getFullYear(), quarterStartMonth + 3, 0)
      return { dateFrom: toDateString(from), dateTo: toDateString(to) }
    }
    case 'this_fiscal_year': {
      const from = resolveFiscalYearStart(companies, filters.companyId)
      const to = new Date(from.getFullYear() + 1, from.getMonth(), from.getDate() - 1)
      return { dateFrom: toDateString(from), dateTo: toDateString(to) }
    }
    case 'custom':
    default:
      return { dateFrom: filters.dateFrom, dateTo: filters.dateTo }
  }
}
