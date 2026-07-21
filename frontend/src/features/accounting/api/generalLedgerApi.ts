import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse, PaginationMeta } from '@/shared/types/api'
import type { AccountLedgerData, LedgerAccountSummary } from '../types'

export interface GeneralLedgerListParams {
  status?: string
  reference_type?: string
  branch_id?: string
  company_id?: string
  date_from?: string
  date_to?: string
}

export interface AccountLedgerParams extends GeneralLedgerListParams {
  page: number
  reference_number?: string
  per_page?: number
}

/** Ledger List — one row per Chart of Account, never paginated (dozens of accounts at most). See docs/GENERAL_LEDGER_DESIGN.md §5/§6. */
export async function fetchLedgerAccounts(params: GeneralLedgerListParams): Promise<LedgerAccountSummary[]> {
  const { data } = await apiClient.get<ApiResponse<LedgerAccountSummary[]>>('/general-ledger/accounts', { params })
  return data.data
}

/** Ledger Detail / Account Drill-down — paginated transaction lines with a running balance, plus the account's opening/ending balance for the filtered range. */
export async function fetchAccountLedger(accountId: string, params: AccountLedgerParams): Promise<{ data: AccountLedgerData; meta: PaginationMeta }> {
  const { data } = await apiClient.get<ApiResponse<AccountLedgerData> & { meta: PaginationMeta }>(`/general-ledger/accounts/${accountId}`, { params })
  return { data: data.data, meta: data.meta }
}
