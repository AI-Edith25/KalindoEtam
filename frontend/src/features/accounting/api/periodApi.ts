import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { AccountingPeriod, FiscalYear, PeriodValidationCheck } from '../types'

export async function fetchFiscalYears(): Promise<FiscalYear[]> {
  const { data } = await apiClient.get<ApiResponse<FiscalYear[]>>('/fiscal-years')
  return data.data
}

export interface CreateFiscalYearParams {
  company_id: string
  name: string
  start_date: string
}

/** Generates 12 monthly Accounting Periods (all Open) starting from start_date — see docs/PERIOD_CLOSING_DESIGN.md §7. */
export async function createFiscalYear(params: CreateFiscalYearParams): Promise<FiscalYear> {
  const { data } = await apiClient.post<ApiResponse<FiscalYear>>('/fiscal-years', params)
  return data.data
}

export interface AccountingPeriodParams {
  fiscal_year_id?: string
  status?: string
}

/** Closing History's one row per period — never paginated in this UI, a fiscal year has at most 12. */
export async function fetchAccountingPeriods(params: AccountingPeriodParams): Promise<AccountingPeriod[]> {
  const { data } = await apiClient.get<ApiResponse<AccountingPeriod[]>>('/accounting-periods', { params: { ...params, per_page: 100 } })
  return data.data
}

export async function fetchPeriodValidation(periodId: string): Promise<PeriodValidationCheck[]> {
  const { data } = await apiClient.get<ApiResponse<{ checks: PeriodValidationCheck[] }>>(`/accounting-periods/${periodId}/validate`)
  return data.data.checks
}

export async function closePeriod(periodId: string): Promise<AccountingPeriod> {
  const { data } = await apiClient.post<ApiResponse<AccountingPeriod>>(`/accounting-periods/${periodId}/close`)
  return data.data
}

export async function reopenPeriod(periodId: string): Promise<AccountingPeriod> {
  const { data } = await apiClient.post<ApiResponse<AccountingPeriod>>(`/accounting-periods/${periodId}/reopen`)
  return data.data
}
