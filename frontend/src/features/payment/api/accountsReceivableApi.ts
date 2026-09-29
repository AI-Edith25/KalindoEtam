import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, PaginationMeta } from '@/shared/types/api'
import type { AccountsReceivable } from '../types'

export interface AccountsReceivableListParams {
  customer_id?: string
  status?: string
  aging_bucket?: string
  date_from?: string
  date_to?: string
  invoice_date_from?: string
  invoice_date_to?: string
  branch_id?: string
  /** Legacy single-id param — the backend still accepts it, but every caller in this codebase now
      sends `sales_person_ids` (below) instead. Kept typed here only so a stray external caller
      passing it wouldn't be a type error. */
  sales_person_id?: string
  /** Multi-select (see AccountsReceivableDetailReportFiltersBar) — omit entirely for "all". */
  sales_person_ids?: string[]
  /** Sales > Invoices' checkbox print flow (Tanda Terima Invoice / Laporan Penagihan Harian) — resolves checked Invoice ids to their AccountsReceivable rows. */
  invoice_ids?: string[]
  page?: number
  per_page?: number
}

/** AR Detail Report's "Total Outstanding" footer needs this alongside the paginated list — kept local rather than widening the shared PaginationMeta every other list endpoint uses. */
export interface AccountsReceivableListResponse extends ApiListResponse<AccountsReceivable> {
  meta: PaginationMeta & { total_outstanding: number }
}

/** Read-only — Accounts Receivable rows are only ever created as a side effect of Delivery submission. Used by the Incoming Payment picker and the AR Detail report. */
export async function fetchAccountsReceivables(params: AccountsReceivableListParams): Promise<AccountsReceivableListResponse> {
  const { data } = await apiClient.get<AccountsReceivableListResponse>('/accounts-receivables', { params })
  return data
}

/**
 * AR Detail's Export (C2) — same filters as fetchAccountsReceivables(), unpaginated, XLSX or CSV,
 * plus a Detail/Summary template choice ("Customer Detail Aging" / "Customer Summary Aging").
 * Blob response (auth is a Bearer header, not a cookie, so a plain window.open link can't carry it).
 */
export async function exportAccountsReceivables(
  params: Omit<AccountsReceivableListParams, 'page' | 'per_page'>,
  type: 'detail' | 'summary',
  format: 'xlsx' | 'csv',
): Promise<Blob> {
  const { data } = await apiClient.get('/accounts-receivables/export', { params: { ...params, type, format }, responseType: 'blob' })
  return data as Blob
}
