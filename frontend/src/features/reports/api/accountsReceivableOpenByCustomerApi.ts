import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'

export interface OpenBillLine {
  invoice_date: string | null
  document_number: string | null
  reference_1: string | null
  amount: number
  paid_amount: number
  unpaid_amount: number
  terms_days: number | null
  due_date: string | null
  overdue_days: number
  overdue_amount: number
}

export interface OpenBillCustomer {
  customer_code: string | null
  customer_name: string | null
  rows: OpenBillLine[]
  total_unpaid: number
  total_overdue: number
}

export interface OpenBillsByCustomer {
  as_at: string
  customers: OpenBillCustomer[]
  grand_total_unpaid: number
  grand_total_overdue: number
}

/** Customer Outstanding Bills (live) — still-owed AR rows per customer, as at a date. */
export async function fetchOpenBillsByCustomer(asAt?: string): Promise<OpenBillsByCustomer> {
  const { data } = await apiClient.get<ApiResponse<OpenBillsByCustomer>>('/accounts-receivables/open-by-customer', {
    params: asAt ? { as_at: asAt } : undefined,
  })
  return data.data
}

/** Same figures as fetchOpenBillsByCustomer(), every customer and every open document, as an xlsx file. */
export async function exportOpenBillsByCustomer(asAt?: string): Promise<Blob> {
  const { data } = await apiClient.get('/accounts-receivables/open-by-customer/export', {
    params: asAt ? { as_at: asAt } : undefined,
    responseType: 'blob',
  })
  return data
}
