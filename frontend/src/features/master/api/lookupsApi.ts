import { fetchLookupList } from '@/shared/services/lookupApi'
import type { Branch, ChartOfAccount, Company, Customer, Item, ItemGroup, SalesPerson, Supplier, Tax, TermsOfPayment, Uom, Warehouse } from '../types'

export const fetchItemGroups = () => fetchLookupList<ItemGroup>('/item-groups')
export const fetchUoms = () => fetchLookupList<Uom>('/uoms')
export const fetchBranches = () => fetchLookupList<Branch>('/branches')
export const fetchCompaniesLookup = () => fetchLookupList<Company>('/companies')
export const fetchSalesPersonsLookup = () => fetchLookupList<SalesPerson>('/sales-persons')

/**
 * Cross-feature reuse: Purchase's and Sales's editors need these same page-1 lookups.
 * `warehouseId` (Sales Order only) makes each item's `effective_rate` reflect that
 * warehouse's override — see ItemController::index. Omit it and behavior is identical to before.
 */
export const fetchItemsLookup = (warehouseId?: string) =>
  fetchLookupList<Item>('/items', warehouseId ? { warehouse_id: warehouseId } : undefined)
export const fetchSuppliersLookup = () => fetchLookupList<Supplier>('/suppliers')
export const fetchWarehousesLookup = () => fetchLookupList<Warehouse>('/warehouses')
export const fetchCustomersLookup = () => fetchLookupList<Customer>('/customers')
export const fetchChartOfAccountsLookup = () => fetchLookupList<ChartOfAccount>('/chart-of-accounts')
export const fetchTermsOfPaymentLookup = () => fetchLookupList<TermsOfPayment>('/terms-of-payments')
/** Invoice/Purchase Order editors filter to is_active client-side — only a handful of taxes ever exist. */
export const fetchTaxesLookup = () => fetchLookupList<Tax>('/taxes')
