import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'

export interface AccountsReceivableAgingParams {
  customer_id?: string
  as_of_date?: string
}

export interface AccountsReceivableAgingRow {
  customer_id: string
  customer_name: string
  bucket_0_30: number | string
  bucket_31_60: number | string
  bucket_61_90: number | string
  bucket_over_90: number | string
  total_outstanding: number | string
}

export interface AccountsReceivableAgingTotals {
  bucket_0_30: number
  bucket_31_60: number
  bucket_61_90: number
  bucket_over_90: number
  total_outstanding: number
}

export interface AccountsReceivableAgingData {
  rows: AccountsReceivableAgingRow[]
  totals: AccountsReceivableAgingTotals
  as_of_date: string
}

/** Never paginated — one row per customer, same shape as Trial Balance's one-row-per-account. */
export async function fetchAccountsReceivableAging(params: AccountsReceivableAgingParams): Promise<AccountsReceivableAgingData> {
  const { data } = await apiClient.get<ApiResponse<AccountsReceivableAgingData>>('/accounts-receivable-aging', { params })
  return data.data
}
