import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { DriverCommissionReportRow, DriverCommissionReportSummary, DriverCommissionStatusFilter } from '../types'

export interface DriverCommissionReportParams {
  date_from?: string
  date_to?: string
  fleet_id?: string
  item_id?: string
  delivery_status?: DriverCommissionStatusFilter
}

interface DriverCommissionReportResponse {
  rows: DriverCommissionReportRow[]
  summary: DriverCommissionReportSummary
}

/** Unpaginated — DriverCommissionReportService::rows() computes commission at read time, never stored. */
export async function fetchDriverCommissionReport(params: DriverCommissionReportParams): Promise<DriverCommissionReportResponse> {
  const { data } = await apiClient.get<ApiResponse<DriverCommissionReportResponse>>('/reports/driver-commission', { params })
  return data.data
}

/** Jenis Barang filter's options — only items actually commissioned (master Driver Commission Rates), never the whole Item catalog. */
export async function fetchDriverCommissionCommissionableItems(): Promise<{ id: string; item_code: string; item_name: string }[]> {
  const { data } = await apiClient.get<ApiResponse<{ id: string; item_code: string; item_name: string }[]>>('/reports/driver-commission/items')
  return data.data
}

export async function exportDriverCommissionReport(params: DriverCommissionReportParams, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/reports/driver-commission/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
