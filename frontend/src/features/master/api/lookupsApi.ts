import { fetchLookupList } from '@/shared/services/lookupApi'
import type { Branch, ChartOfAccount, Company, Customer, Item, ItemGroup, Supplier, Tax, Uom, Warehouse } from '../types'

export const fetchItemGroups = () => fetchLookupList<ItemGroup>('/item-groups')
export const fetchUoms = () => fetchLookupList<Uom>('/uoms')
export const fetchBranches = () => fetchLookupList<Branch>('/branches')
export const fetchCompaniesLookup = () => fetchLookupList<Company>('/companies')

/** Cross-feature reuse: Purchase's and Sales's editors need these same page-1 lookups. */
export const fetchItemsLookup = () => fetchLookupList<Item>('/items')
export const fetchSuppliersLookup = () => fetchLookupList<Supplier>('/suppliers')
export const fetchWarehousesLookup = () => fetchLookupList<Warehouse>('/warehouses')
export const fetchCustomersLookup = () => fetchLookupList<Customer>('/customers')
export const fetchChartOfAccountsLookup = () => fetchLookupList<ChartOfAccount>('/chart-of-accounts')
/** Invoice/Purchase Order editors filter to is_active client-side — only a handful of taxes ever exist. */
export const fetchTaxesLookup = () => fetchLookupList<Tax>('/taxes')
