import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type {
  SupplierOutstandingArchiveDetail,
  SupplierOutstandingArchiveFilterValues,
  SupplierOutstandingArchiveImportBatch,
  SupplierOutstandingSnapshot,
} from '../types'

function filterParams(filters: Partial<SupplierOutstandingArchiveFilterValues>) {
  return {
    ...(filters.supplier ? { supplier: filters.supplier } : {}),
    ...(filters.invoiceDateFrom ? { invoice_date_from: filters.invoiceDateFrom } : {}),
    ...(filters.invoiceDateTo ? { invoice_date_to: filters.invoiceDateTo } : {}),
    ...(filters.dueDateFrom ? { due_date_from: filters.dueDateFrom } : {}),
    ...(filters.dueDateTo ? { due_date_to: filters.dueDateTo } : {}),
    ...(filters.status ? { status: filters.status } : {}),
  }
}

export async function fetchSupplierOutstandingSnapshots(): Promise<SupplierOutstandingSnapshot[]> {
  const { data } = await apiClient.get<ApiResponse<SupplierOutstandingSnapshot[]>>('/supplier-outstanding-archive/snapshots')
  return data.data
}

/** Preflight only -- parses and validates, returns a batch awaiting confirmation. Nothing is committed yet. */
export async function storeSupplierOutstandingSnapshot(file: File): Promise<SupplierOutstandingArchiveImportBatch> {
  const formData = new FormData()
  formData.append('file', file)

  const { data } = await apiClient.post<ApiResponse<SupplierOutstandingArchiveImportBatch>>('/supplier-outstanding-archive/snapshots', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

/** Commits the previously previewed batch -- runs synchronously and returns the finished snapshot immediately. */
export async function resolveSupplierOutstandingImport(batchId: string): Promise<SupplierOutstandingSnapshot> {
  const { data } = await apiClient.post<ApiResponse<SupplierOutstandingSnapshot>>(`/supplier-outstanding-archive/batches/${batchId}/resolve`)
  return data.data
}

export async function fetchSupplierOutstandingArchiveDetail(
  snapshotId: string,
  filters: Partial<SupplierOutstandingArchiveFilterValues>,
): Promise<SupplierOutstandingArchiveDetail> {
  const { data } = await apiClient.get<ApiResponse<SupplierOutstandingArchiveDetail>>(`/supplier-outstanding-archive/snapshots/${snapshotId}`, {
    params: filterParams(filters),
  })
  return data.data
}

export async function exportSupplierOutstandingArchive(
  snapshotId: string,
  filters: Partial<SupplierOutstandingArchiveFilterValues>,
  format: 'xlsx' | 'csv',
): Promise<Blob> {
  const { data } = await apiClient.get(`/supplier-outstanding-archive/snapshots/${snapshotId}/export`, {
    params: { ...filterParams(filters), format },
    responseType: 'blob',
  })
  return data
}
