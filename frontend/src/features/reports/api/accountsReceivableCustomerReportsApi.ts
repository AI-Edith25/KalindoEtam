import { apiClient } from '@/shared/services/apiClient'
import type { AccountsReceivableListParams } from '@/features/payment/api/accountsReceivableApi'
import type { AccountsReceivable } from '@/features/payment/types'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { CustomerGroupedDetail } from '../types'

type Filters = Omit<AccountsReceivableListParams, 'page' | 'per_page'>

/** F1 (UAT review 2026-08-12) — "Tanda Terima Invoice": unpaginated, same filter shape as fetchAccountsReceivables(). */
export async function fetchAccountsReceivablesAll(params: Filters): Promise<AccountsReceivable[]> {
  const { data } = await apiClient.get<ApiListResponse<AccountsReceivable>>('/accounts-receivables/list-all', { params })
  return data.data
}

/** F2 (UAT review 2026-08-12) — "Laporan Penagihan Harian": grouped by Customer, same filter shape. */
export async function fetchAccountsReceivablesGroupedByCustomer(params: Filters): Promise<CustomerGroupedDetail> {
  const { data } = await apiClient.get<ApiResponse<CustomerGroupedDetail>>('/accounts-receivables/grouped-by-customer', { params })
  return data.data
}
