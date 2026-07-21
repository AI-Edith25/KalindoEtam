import type { Company } from '@/features/master/types'
import type { TrialBalanceFilterValues } from '../types'

export const emptyTrialBalanceFilters: TrialBalanceFilterValues = {
  periodPreset: 'this_month',
  dateFrom: '',
  dateTo: '',
  codeFrom: '',
  codeTo: '',
  branchId: null,
  companyId: null,
  hideZeroBalance: true,
}

export function hasActiveTrialBalanceFilters(filters: TrialBalanceFilterValues): boolean {
  return (
    filters.periodPreset !== emptyTrialBalanceFilters.periodPreset ||
    filters.dateFrom !== '' ||
    filters.dateTo !== '' ||
    filters.codeFrom !== '' ||
    filters.codeTo !== '' ||
    filters.branchId !== null ||
    filters.companyId !== null ||
    filters.hideZeroBalance !== emptyTrialBalanceFilters.hideZeroBalance
  )
}

/** Local-calendar-date formatting — toISOString() converts to UTC first, which silently shifts
 * the date by a day in any timezone ahead of UTC (e.g. this app's Asia/Jakarta, UTC+7). */
function toDateString(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

/**
 * "This Fiscal Year" reuses companies.fiscal_year_start (a UI-only concept —
 * no backend "period" parameter, see docs/TRIAL_BALANCE_DESIGN.md §4). With
 * no Company filter selected, falls back to the first company on record
 * (this codebase's data is single-company today); with none at all, falls
 * back to the calendar year.
 */
function resolveFiscalYearStart(companies: Company[], selectedCompanyId: string | null): Date {
  const company = (selectedCompanyId ? companies.find((c) => c.id === selectedCompanyId) : companies[0]) ?? null
  if (company?.fiscal_year_start) {
    // Manual parse, not `new Date(str)` — a bare "YYYY-MM-DD" parses as UTC midnight, which
    // reads back as the previous local day in any timezone behind UTC.
    const [y, m, d] = company.fiscal_year_start.split('-').map(Number)
    const start = new Date(y, m - 1, d)
    const now = new Date()
    // fiscal_year_start only carries month/day (year anchors to a template company record) — reanchor to the current fiscal year.
    const anchored = new Date(now.getFullYear(), start.getMonth(), start.getDate())
    return anchored > now ? new Date(now.getFullYear() - 1, start.getMonth(), start.getDate()) : anchored
  }

  return new Date(new Date().getFullYear(), 0, 1)
}

/** Resolves a Reporting Period preset to a date_from/date_to pair. Custom Range returns the filter's own dates unchanged. */
export function resolvePeriodPreset(
  filters: TrialBalanceFilterValues,
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

/** Account Range — both bounds inclusive, plain string comparison (fixed-width numeric codes, see docs/TRIAL_BALANCE_DESIGN.md §4). */
export function withinAccountRange(code: string, codeFrom: string, codeTo: string): boolean {
  if (codeFrom && code < codeFrom) return false
  if (codeTo && code > codeTo) return false
  return true
}
