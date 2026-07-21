import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse } from '@/shared/types/api'
import type { StockLedgerEntry } from '../types'

export interface StockLedgerListParams {
  page: number
  search?: string
  warehouse_id?: string
  item_id?: string
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
