import { apiClient } from './apiClient'
import type { ApiListResponse } from '@/shared/types/api'

/**
 * Populates dropdowns (Item Group, UOM, Branch, ...) from a paginated
 * list endpoint, taking only page 1. Most master-data list endpoints still
 * default to 15/page with no override — Customer, Supplier, Chart of
 * Accounts and Item (via an explicit per_page) were raised to 200 once
 * their dropdowns needed searchable comboboxes over the full list, but the
 * others still silently truncate past 15 records. Acceptable for today's
 * reference data, called out in docs/ERP_DESIGN_SYSTEM.md as the seam to
 * revisit if any of these lists outgrows one page.
 */
export async function fetchLookupList<T>(path: string, params?: Record<string, string>): Promise<T[]> {
  const { data } = await apiClient.get<ApiListResponse<T>>(path, { params })
  return data.data
}
