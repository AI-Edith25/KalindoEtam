import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { PaymentAllocation } from '../types'

export interface PaymentAllocationLine {
  accounts_receivable_id: string
  amount: number
}

/** Applies an already-received Payment (ReceiptEntry) to one or more outstanding Invoices' receivables — a separate step from receiving the money itself. */
export async function allocatePayment(receiptEntryId: string, allocations: PaymentAllocationLine[]): Promise<PaymentAllocation[]> {
  const { data } = await apiClient.post<ApiResponse<PaymentAllocation[]>>(`/receipt-entries/${receiptEntryId}/allocate`, { allocations })
  return data.data
}

export async function reverseAllocation(paymentAllocationId: string): Promise<PaymentAllocation> {
  const { data } = await apiClient.post<ApiResponse<PaymentAllocation>>(`/payment-allocations/${paymentAllocationId}/reverse`)
  return data.data
}
