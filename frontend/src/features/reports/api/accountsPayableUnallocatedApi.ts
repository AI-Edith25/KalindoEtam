import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { UnallocatedPaymentVoucher } from '../types'

/** "Uang Muka / Belum Teralokasi" panel — Supplier Payment Vouchers not yet applied to any invoice. No AR equivalent exists. */
export async function fetchUnallocatedPaymentVouchers(): Promise<UnallocatedPaymentVoucher[]> {
  const { data } = await apiClient.get<ApiResponse<UnallocatedPaymentVoucher[]>>('/accounts-payables/unallocated')
  return data.data
}
