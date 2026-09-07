import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse, PaginationMeta } from '@/shared/types/api'
import type { AccountsPayable } from '../types'

export interface AccountsPayableListParams {
  supplier_id?: string
  warehouse_id?: string
  status?: string
  aging_bucket?: string
  date_from?: string
  date_to?: string
  invoice_date_from?: string
  invoice_date_to?: string
  invoice_ids?: string[]
  per_page?: number
  page?: number
}

/** AP Detail Report's "Total Hutang" card needs this alongside the paginated list — kept local rather than widening the shared PaginationMeta every other list endpoint uses. Mirrors AccountsReceivableListResponse. */
export interface AccountsPayableListResponse extends ApiListResponse<AccountsPayable> {
  meta: PaginationMeta & { total_outstanding: number }
}

/** Read-only — Accounts Payable rows are only ever created as a side effect of Purchase Invoice submission. Used by the Outgoing Payment picker and the AP Detail report. */
export async function fetchAccountsPayables(params: AccountsPayableListParams): Promise<AccountsPayableListResponse> {
  const { data } = await apiClient.get<AccountsPayableListResponse>('/accounts-payables', { params })
  return data
}

/**
 * AP Detail's Export — same filters as fetchAccountsPayables(), unpaginated, XLSX or CSV, plus a
 * Detail/Summary template choice. Mirrors exportAccountsReceivables(). Blob response (auth is a
 * Bearer header, not a cookie, so a plain window.open link can't carry it).
 */
export async function exportAccountsPayables(
  params: Omit<AccountsPayableListParams, 'page' | 'per_page'>,
  type: 'detail' | 'summary',
  format: 'xlsx' | 'csv',
): Promise<Blob> {
  const { data } = await apiClient.get('/accounts-payables/export', { params: { ...params, type, format }, responseType: 'blob' })
  return data as Blob
}

/** AP Detail's 4 summary cards — one request instead of issuing several filtered index() calls just to read totals. */
export async function fetchAccountsPayableSummary(): Promise<{
  total_outstanding: number
  due_this_week: number
  overdue: number
  unallocated_total: number
}> {
  const { data } = await apiClient.get<ApiResponse<{ total_outstanding: number; due_this_week: number; overdue: number; unallocated_total: number }>>(
    '/accounts-payables/summary',
  )
  return data.data
}
