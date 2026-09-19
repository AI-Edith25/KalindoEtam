import { apiClient } from '@/shared/services/apiClient'
import { fetchLookupList } from '@/shared/services/lookupApi'
import type { ApiListResponse } from '@/shared/types/api'
import type { Branch, ChartOfAccount, Company, Customer, Item, ItemGroup, MiscellaneousItem, SalesPerson, Supplier, Tax, TermsOfPayment, Uom, Warehouse } from '../types'

export const fetchItemGroups = () => fetchLookupList<ItemGroup>('/item-groups', { per_page: '200' })
export const fetchUoms = () => fetchLookupList<Uom>('/uoms', { per_page: '200' })
export const fetchBranches = () => fetchLookupList<Branch>('/branches', { per_page: '200' })
export const fetchCompaniesLookup = () => fetchLookupList<Company>('/companies')
export const fetchSalesPersonsLookup = () => fetchLookupList<SalesPerson>('/sales-persons', { per_page: '200' })

/**
 * Cross-feature reuse: Purchase's and Sales's editors need these same page-1 lookups.
 * `warehouseId` (Sales Order only) makes each item's `effective_rate` reflect that
 * warehouse's override — see ItemController::index. Omit it and behavior is identical to before.
 */
export const fetchItemsLookup = (warehouseId?: string) =>
  fetchLookupList<Item>('/items', { per_page: '200', ...(warehouseId ? { warehouse_id: warehouseId } : {}) })
/** Server-side search for SearchableSelect's async mode — the Item master can run into the thousands. */
export const searchItemsLookup = (search: string, warehouseId?: string) =>
  fetchLookupList<Item>('/items', { per_page: '30', search, ...(warehouseId ? { warehouse_id: warehouseId } : {}) })
/**
 * Re-fetches available_qty for a Sales Order's already-selected line items after the header
 * Warehouse changes — reuses ItemService::list()'s own ItemStockResolver so this stays identical
 * to whatever the item picker itself would show, rather than drifting via a separate calculation.
 */
export async function fetchItemsByIds(itemIds: string[], warehouseId: string): Promise<Item[]> {
  if (itemIds.length === 0) return []

  const { data } = await apiClient.get<ApiListResponse<Item>>('/items', {
    params: { item_ids: itemIds, warehouse_id: warehouseId, per_page: itemIds.length },
  })
  return data.data
}
export const fetchSuppliersLookup = () => fetchLookupList<Supplier>('/suppliers')
export const searchSuppliersLookup = (search: string) => fetchLookupList<Supplier>('/suppliers', { per_page: '30', search })
export const fetchWarehousesLookup = () => fetchLookupList<Warehouse>('/warehouses', { per_page: '200' })
export const fetchCustomersLookup = () => fetchLookupList<Customer>('/customers')
export const searchCustomersLookup = (search: string) => fetchLookupList<Customer>('/customers', { per_page: '30', search })
export const fetchChartOfAccountsLookup = () => fetchLookupList<ChartOfAccount>('/chart-of-accounts', { per_page: '300' })
export const fetchTermsOfPaymentLookup = () => fetchLookupList<TermsOfPayment>('/terms-of-payments', { per_page: '200' })
/** Invoice/Purchase Order editors filter to is_active client-side — only a handful of taxes ever exist. */
export const fetchTaxesLookup = () => fetchLookupList<Tax>('/taxes', { per_page: '200' })
/** Server-side search for SearchableSelect's async mode — Transportation Invoice's Description picker. */
export const searchMiscellaneousItemsLookup = (search: string) => fetchLookupList<MiscellaneousItem>('/miscellaneous-items', { per_page: '30', search })
