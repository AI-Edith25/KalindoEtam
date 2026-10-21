import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { DriverCommissionRate, DriverCommissionRateFormValues } from '../types'

export async function fetchDriverCommissionRates(fleetId: string): Promise<DriverCommissionRate[]> {
  const { data } = await apiClient.get<ApiListResponse<DriverCommissionRate>>('/driver-commission-rates', { params: { fleet_id: fleetId } })
  return data.data
}

/** Always an insert — rates are append-only history, see StoreDriverCommissionRateRequest / DriverCommissionRateService::create(). */
export async function createDriverCommissionRate(payload: DriverCommissionRateFormValues): Promise<DriverCommissionRate> {
  const { data } = await apiClient.post<ApiResponse<DriverCommissionRate>>('/driver-commission-rates', payload)
  return data.data
}

export async function deleteDriverCommissionRate(id: string): Promise<void> {
  await apiClient.delete(`/driver-commission-rates/${id}`)
}
