import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse, ApiResponse } from '@/shared/types/api'
import type { TaxReportRow, TaxReportSummary } from '../types'

export interface TaxReportListParams {
  date_from?: string
  date_to?: string
  tax_id?: string
  customer_id?: string
  supplier_id?: string
  branch_id?: string
  warehouse_id?: string
  page?: number
  per_page?: number
}

/** PPN Keluaran — Sales Invoice (Goods per-line, Transportation header) + Credit Note reductions, synthesized server-side. */
export async function fetchOutputTax(params: TaxReportListParams): Promise<ApiListResponse<TaxReportRow>> {
  const { data } = await apiClient.get<ApiListResponse<TaxReportRow>>('/tax-report/output', { params })
  return data
}

/** PPN Masukan — Purchase Invoice + Purchase Return reductions. tax_id/tax_code/tax_rate always null (see the report's own data-gap finding). */
export async function fetchInputTax(params: TaxReportListParams): Promise<ApiListResponse<TaxReportRow>> {
  const { data } = await apiClient.get<ApiListResponse<TaxReportRow>>('/tax-report/input', { params })
  return data
}

/** 3 summary cards (Total PPN Keluaran / Total PPN Masukan / Selisih) — same underlying queries as the two lists above, never a separately-computed figure. */
export async function fetchTaxReportSummary(params: Omit<TaxReportListParams, 'page' | 'per_page'>): Promise<TaxReportSummary> {
  const { data } = await apiClient.get<ApiResponse<TaxReportSummary>>('/tax-report/summary', { params })
  return data.data
}

export async function exportOutputTax(params: Omit<TaxReportListParams, 'page' | 'per_page'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/tax-report/output/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}

export async function exportInputTax(params: Omit<TaxReportListParams, 'page' | 'per_page'>, format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/tax-report/input/export', { params: { ...params, format }, responseType: 'blob' })
  return data as Blob
}
