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

/** Date range defaults to the last 30 days per the Sales Report ticket. */
export function emptySalesReportFilters(): SalesReportFilterValues {
  const to = new Date()
  const from = new Date()
  from.setDate(from.getDate() - 30)
  const iso = (d: Date) => d.toISOString().slice(0, 10)

  return {
    customer_id: '',
    item_id: '',
    item_group_id: '',
    sales_person_id: '',
    branch_id: '',
    status: null,
    dateFrom: iso(from),
    dateTo: iso(to),
  }
}

export function hasActiveSalesReportFilters(filters: SalesReportFilterValues): boolean {
  return (
    filters.customer_id !== '' ||
    filters.item_id !== '' ||
    filters.item_group_id !== '' ||
    filters.sales_person_id !== '' ||
    filters.branch_id !== '' ||
    filters.status !== null
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
  branch_id: '',
  sales_person_id: '',
}

export function hasActiveArDetailReportFilters(filters: ArDetailReportFilterValues): boolean {
  return (
    filters.customer_id !== '' ||
    filters.status !== null ||
    filters.agingBucket !== null ||
    filters.dateFrom !== '' ||
    filters.dateTo !== '' ||
    filters.invoiceDateFrom !== '' ||
    filters.invoiceDateTo !== '' ||
    filters.branch_id !== '' ||
    filters.sales_person_id !== ''
  )
}
