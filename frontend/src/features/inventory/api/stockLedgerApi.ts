import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse } from '@/shared/types/api'
import type { StockLedgerEntry } from '../types'

export interface StockLedgerListParams {
  page: number
  search?: string
  warehouse_id?: string
  item_id?: string
  item_group_id?: string
  voucher_type?: string
  date_from?: string
  date_to?: string
  per_page?: number
}

/** Server-side paginated + filtered — every ledger entry across every item/warehouse. */
export async function fetchStockLedgerEntries(params: StockLedgerListParams): Promise<ApiListResponse<StockLedgerEntry>> {
  const { data } = await apiClient.get<ApiListResponse<StockLedgerEntry>>('/stock-ledger', { params })
  return data
}

/**
 * Summary + Detail .xlsx workbook. Reads the filename off Content-Disposition (which
 * Excel::download() sets server-side) instead of building it client-side — when Date From/To are
 * left empty, only the backend knows the actual earliest/latest transaction dates to name the file
 * after.
 */
export async function exportStockLedger(params: Omit<StockLedgerListParams, 'page' | 'per_page'>): Promise<{ blob: Blob; filename: string }> {
  const response = await apiClient.get('/stock-ledger/export', { params, responseType: 'blob' })
  const disposition = response.headers['content-disposition'] as string | undefined
  const filename = disposition?.match(/filename="?([^"]+)"?/)?.[1] ?? 'Stock_Ledger.xlsx'
  return { blob: response.data as Blob, filename }
}
