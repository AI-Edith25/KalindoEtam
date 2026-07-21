import { apiClient } from '@/shared/services/apiClient'
import type { ApiListResponse } from '@/shared/types/api'
import type { AuditLog, AuditLogFilterValues } from '../types'

export async function fetchAuditLogsPaged(page: number, filters: AuditLogFilterValues): Promise<ApiListResponse<AuditLog>> {
  const { data } = await apiClient.get<ApiListResponse<AuditLog>>('/audit-logs', { params: { page, ...filters } })
  return data
}
