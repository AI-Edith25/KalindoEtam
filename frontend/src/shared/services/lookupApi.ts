import { apiClient } from './apiClient'
import type { ApiListResponse } from '@/shared/types/api'

/**
 * Populates dropdowns (Item Group, UOM, Branch, ...) from a paginated
 * list endpoint, taking only page 1. Every master-data list endpoint
 * defaults to 15/page with no override, so this silently truncates past
 * 15 records — acceptable for today's reference data, called out in
 * docs/ERP_DESIGN_SYSTEM.md as the seam to revisit if any of these
 * lists outgrows one page.
 */
export async function fetchLookupList<T>(path: string): Promise<T[]> {
  const { data } = await apiClient.get<ApiListResponse<T>>(path)
  return data.data
}
