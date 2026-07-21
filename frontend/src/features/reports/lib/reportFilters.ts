import type {
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
  status: null,
  dateFrom: '',
  dateTo: '',
}

export function hasActiveSalesReportFilters(filters: SalesReportFilterValues): boolean {
  return filters.customer_id !== '' || filters.status !== null || filters.dateFrom !== '' || filters.dateTo !== ''
}

export const emptyDeliveryReportFilters: DeliveryReportFilterValues = { warehouse_id: '', dateFrom: '', dateTo: '' }

export function hasActiveDeliveryReportFilters(filters: DeliveryReportFilterValues): boolean {
  return filters.warehouse_id !== '' || filters.dateFrom !== '' || filters.dateTo !== ''
}
