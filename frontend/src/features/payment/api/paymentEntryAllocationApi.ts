import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { PaymentEntryAllocation } from '../types'

export interface PaymentEntryAllocationLine {
  accounts_payable_id: string
  amount: number
}

/** Applies an already-paid Payment (PaymentEntry) to one or more outstanding supplier bills' payables — a separate step from paying the money itself. Mirrors paymentAllocationApi.ts. */
export async function allocatePaymentEntry(paymentEntryId: string, allocations: PaymentEntryAllocationLine[]): Promise<PaymentEntryAllocation[]> {
  const { data } = await apiClient.post<ApiResponse<PaymentEntryAllocation[]>>(`/payment-entries/${paymentEntryId}/allocate`, { allocations })
  return data.data
}

export async function reversePaymentEntryAllocation(paymentEntryAllocationId: string): Promise<PaymentEntryAllocation> {
  const { data } = await apiClient.post<ApiResponse<PaymentEntryAllocation>>(`/payment-entry-allocations/${paymentEntryAllocationId}/reverse`)
  return data.data
}
