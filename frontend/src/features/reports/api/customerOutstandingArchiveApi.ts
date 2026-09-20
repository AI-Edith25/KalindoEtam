import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { CustomerOutstandingArchiveDetail, CustomerOutstandingArchiveFilterValues, CustomerOutstandingSnapshot } from '../types'

function filterParams(filters: Partial<CustomerOutstandingArchiveFilterValues>) {
  return {
    ...(filters.customer ? { customer: filters.customer } : {}),
    ...(filters.invoiceDateFrom ? { invoice_date_from: filters.invoiceDateFrom } : {}),
    ...(filters.invoiceDateTo ? { invoice_date_to: filters.invoiceDateTo } : {}),
    ...(filters.dueDateFrom ? { due_date_from: filters.dueDateFrom } : {}),
    ...(filters.dueDateTo ? { due_date_to: filters.dueDateTo } : {}),
    ...(filters.status ? { status: filters.status } : {}),
  }
}

export async function fetchCustomerOutstandingSnapshots(): Promise<CustomerOutstandingSnapshot[]> {
  const { data } = await apiClient.get<ApiResponse<CustomerOutstandingSnapshot[]>>('/customer-outstanding-archive/snapshots')
  return data.data
}

export async function storeCustomerOutstandingSnapshot(file: File): Promise<CustomerOutstandingSnapshot> {
  const formData = new FormData()
  formData.append('file', file)

  const { data } = await apiClient.post<ApiResponse<CustomerOutstandingSnapshot>>('/customer-outstanding-archive/snapshots', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function fetchCustomerOutstandingArchiveDetail(
  snapshotId: string,
  filters: Partial<CustomerOutstandingArchiveFilterValues>,
): Promise<CustomerOutstandingArchiveDetail> {
  const { data } = await apiClient.get<ApiResponse<CustomerOutstandingArchiveDetail>>(`/customer-outstanding-archive/snapshots/${snapshotId}`, {
    params: filterParams(filters),
  })
  return data.data
}

export async function exportCustomerOutstandingArchive(
  snapshotId: string,
  filters: Partial<CustomerOutstandingArchiveFilterValues>,
  format: 'xlsx' | 'csv',
): Promise<Blob> {
  const { data } = await apiClient.get(`/customer-outstanding-archive/snapshots/${snapshotId}/export`, {
    params: { ...filterParams(filters), format },
    responseType: 'blob',
  })
  return data
}
