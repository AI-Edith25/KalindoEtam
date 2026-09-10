import { apiClient } from './apiClient'
import type { ApiListResponse } from '@/shared/types/api'

/**
 * Populates dropdowns (Item Group, UOM, Branch, ...) from a paginated
 * list endpoint, taking only page 1. All lookup fetchers in lookupsApi.ts
 * now pass an explicit `per_page` (200-300) so a single page covers
 * realistic master-data sizes — still a page-1-only ceiling, revisit per
 * docs/ERP_DESIGN_SYSTEM.md if any of these lists outgrows it. Item's
 * fetcher instead forwards `search` for server-side filtering (see
 * fetchItemsLookup) since its dataset is unbounded.
 */
export async function fetchLookupList<T>(path: string, params?: Record<string, string>): Promise<T[]> {
  const { data } = await apiClient.get<ApiListResponse<T>>(path, { params })
  return data.data
}
