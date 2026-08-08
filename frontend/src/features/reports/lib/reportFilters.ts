import type {
  ArDetailReportFilterValues,
  DeliveryReportFilterValues,
  GoodsReceiptReportFilterValues,
  PurchaseReportFilterValues,
  SalesReportFilterValues,
} from '../types'

export const emptyPurchaseReportFilters: PurchaseReportFilterValues = {
  supplier_id: '',
  status: null,
  dateFrom: '',
  dateTo: '',
}

export function hasActivePurchaseReportFilters(filters: PurchaseReportFilterValues): boolean {
  return filters.supplier_id !== '' || filters.status !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}

export const emptyGoodsReceiptReportFilters: GoodsReceiptReportFilterValues = { warehouse_id: '', dateFrom: '', dateTo: '' }

export function hasActiveGoodsReceiptReportFilters(filters: GoodsReceiptReportFilterValues): boolean {
  return filters.warehouse_id !== '' || filters.dateFrom !== '' || filters.dateTo !== ''
}

export const emptySalesReportFilters: SalesReportFilterValues = {
  customer_id: '',
  item_id: '',
  sales_person_id: '',
  branch_id: '',
  status: null,
  dateFrom: '',
  dateTo: '',
}

export function hasActiveSalesReportFilters(filters: SalesReportFilterValues): boolean {
  return (
    filters.customer_id !== '' ||
    filters.item_id !== '' ||
    filters.sales_person_id !== '' ||
    filters.branch_id !== '' ||
    filters.status !== null ||
    filters.dateFrom !== '' ||
    filters.dateTo !== ''
  )
}

export const emptyDeliveryReportFilters: DeliveryReportFilterValues = {
  customer_id: '',
  item_id: '',
  warehouse_id: '',
  dateFrom: '',
  dateTo: '',
}

export function hasActiveDeliveryReportFilters(filters: DeliveryReportFilterValues): boolean {
  return (
    filters.customer_id !== '' ||
    filters.item_id !== '' ||
    filters.warehouse_id !== '' ||
    filters.dateFrom !== '' ||
    filters.dateTo !== ''
  )
}

export const emptyArDetailReportFilters: ArDetailReportFilterValues = {
  customer_id: '',
  status: null,
  agingBucket: null,
  dateFrom: '',
  dateTo: '',
  invoiceDateFrom: '',
  invoiceDateTo: '',
}

export function hasActiveArDetailReportFilters(filters: ArDetailReportFilterValues): boolean {
  return (
    filters.customer_id !== '' ||
    filters.status !== null ||
    filters.agingBucket !== null ||
    filters.dateFrom !== '' ||
    filters.dateTo !== '' ||
    filters.invoiceDateFrom !== '' ||
    filters.invoiceDateTo !== ''
  )
}
