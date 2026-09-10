import { fetchLookupList } from '@/shared/services/lookupApi'
import type { Branch, ChartOfAccount, Company, Customer, Item, ItemGroup, SalesPerson, Supplier, Tax, TermsOfPayment, Uom, Warehouse } from '../types'

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
export const fetchSuppliersLookup = () => fetchLookupList<Supplier>('/suppliers')
export const searchSuppliersLookup = (search: string) => fetchLookupList<Supplier>('/suppliers', { per_page: '30', search })
export const fetchWarehousesLookup = () => fetchLookupList<Warehouse>('/warehouses', { per_page: '200' })
export const fetchCustomersLookup = () => fetchLookupList<Customer>('/customers')
export const searchCustomersLookup = (search: string) => fetchLookupList<Customer>('/customers', { per_page: '30', search })
export const fetchChartOfAccountsLookup = () => fetchLookupList<ChartOfAccount>('/chart-of-accounts', { per_page: '300' })
export const fetchTermsOfPaymentLookup = () => fetchLookupList<TermsOfPayment>('/terms-of-payments', { per_page: '200' })
/** Invoice/Purchase Order editors filter to is_active client-side — only a handful of taxes ever exist. */
export const fetchTaxesLookup = () => fetchLookupList<Tax>('/taxes', { per_page: '200' })
