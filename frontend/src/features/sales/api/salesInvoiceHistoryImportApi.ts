import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type {
  SalesInvoiceHistoryImportBatch,
  SalesInvoiceHistoryResolutionAction,
  SalesInvoiceHistoryResolutionCategory,
} from '../types'

export interface SalesInvoiceHistoryResolutionInput {
  category: SalesInvoiceHistoryResolutionCategory
  value: string
  action: SalesInvoiceHistoryResolutionAction
  target_id?: string | null
}

/** warehouseId is only a formality field for Goods rows — stock never actually moves, see the backend's SalesInvoiceImportService. */
export async function storeSalesInvoiceHistoryImport(file: File, warehouseId: string): Promise<SalesInvoiceHistoryImportBatch> {
  const formData = new FormData()
  formData.append('file', file)
  formData.append('warehouse_id', warehouseId)

  const { data } = await apiClient.post<ApiResponse<SalesInvoiceHistoryImportBatch>>('/sales-invoice-history/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function resolveSalesInvoiceHistoryImport(
  batchId: string,
  resolutions: SalesInvoiceHistoryResolutionInput[],
): Promise<SalesInvoiceHistoryImportBatch> {
  const { data } = await apiClient.post<ApiResponse<SalesInvoiceHistoryImportBatch>>(`/sales-invoice-history/import/${batchId}/resolve`, { resolutions })
  return data.data
}

export async function fetchSalesInvoiceHistoryImportBatch(batchId: string): Promise<SalesInvoiceHistoryImportBatch> {
  const { data } = await apiClient.get<ApiResponse<SalesInvoiceHistoryImportBatch>>(`/sales-invoice-history/import/${batchId}`)
  return data.data
}
