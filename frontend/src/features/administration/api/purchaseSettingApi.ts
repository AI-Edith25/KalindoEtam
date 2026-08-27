import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { PurchaseSetting } from '../types'

export async function fetchPurchaseSetting(): Promise<PurchaseSetting> {
  const { data } = await apiClient.get<ApiResponse<PurchaseSetting>>('/purchase-settings')
  return data.data
}

export async function updatePurchaseSetting(payload: { weight_over_receipt_tolerance_percent: number | null }): Promise<PurchaseSetting> {
  const { data } = await apiClient.put<ApiResponse<PurchaseSetting>>('/purchase-settings', payload)
  return data.data
}
