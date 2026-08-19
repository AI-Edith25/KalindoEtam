import { apiClient } from '@/shared/services/apiClient'
import type { AccountsReceivableListParams } from '@/features/payment/api/accountsReceivableApi'
import type { AccountsReceivable } from '@/features/payment/types'
import type { ApiListResponse } from '@/shared/types/api'

type Filters = Omit<AccountsReceivableListParams, 'page' | 'per_page'>

/** Unpaginated, same filter shape as fetchAccountsReceivables(). Used by AR Detail's export, and
 *  by Sales > Invoices' checkbox print flow (Tanda Terima Invoice / Laporan Penagihan Harian,
 *  2026-08-19) via the `invoice_ids` filter — formerly also customer_id/date-range filtered by
 *  the old /reports/tanda-terima-invoice page before it moved into Sales. */
export async function fetchAccountsReceivablesAll(params: Filters): Promise<AccountsReceivable[]> {
  const { data } = await apiClient.get<ApiListResponse<AccountsReceivable>>('/accounts-receivables/list-all', { params })
  return data.data
}
