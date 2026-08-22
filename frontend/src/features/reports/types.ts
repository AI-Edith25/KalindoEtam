import type { DocumentStatus as PurchaseDocumentStatus } from '@/features/purchase/types'
import type { SalesOrderStatus } from '@/features/sales/types'
import type { SettlementStatus } from '@/features/payment/types'

/**
 * Reports is read-only and consumes Purchase/Sales/Inventory data directly
 * (their types + api functions), rather than duplicating entity shapes a
 * third time. Inventory Movement and Inventory Balance reports reuse
 * StockLedgerFilterValues / StockBalanceFilterValues and their existing
 * FiltersBar components as-is — only the 4 document reports below need new
 * filter shapes, since none of the 4 existing document FiltersBars expose
 * a supplier/customer dropdown today.
 */

export interface PurchaseReportFilterValues {
  supplier_id: string
  status: PurchaseDocumentStatus | null
  dateFrom: string
  dateTo: string
}

export interface GoodsReceiptReportFilterValues {
  warehouse_id: string
  dateFrom: string
  dateTo: string
}

export interface SalesReportFilterValues {
  customer_id: string
  item_id: string
  sales_person_id: string
  branch_id: string
  status: SalesOrderStatus | null
  dateFrom: string
  dateTo: string
}

export interface DeliveryReportFilterValues {
  customer_id: string
  item_id: string
  warehouse_id: string
  dateFrom: string
  dateTo: string
}

/** Ceiling filter ("overdue up to N days"), deliberately overlapping — 60 is a superset of 30, not a discrete bucket. over_180 is the one floor (unbounded above). */
export type AgingBucketValue = '30' | '45' | '60' | '90' | 'over_180'

/** C3 (UAT review 2026-08-12) — "Perincian Piutang": AR Detail rows grouped Sales Person -> Customer, with a subtotal at each level. */
export interface ArDetailGroupedRow {
  invoice_id: string | null
  document_no: string | null
  date: string | null
  due_date: string | null
  total_outstanding: number
  overdue_days: number
  overdue_amount: number
}

export interface ArDetailGroupedCustomer {
  customer_id: string
  customer_name: string
  rows: ArDetailGroupedRow[]
  customer_subtotal: number
}

export interface ArDetailGroupedSalesPerson {
  sales_person_name: string
  customers: ArDetailGroupedCustomer[]
  sales_person_subtotal: number
}

export interface ArDetailGroupedDetail {
  groups: ArDetailGroupedSalesPerson[]
  grand_total: number
}

export interface ArDetailReportFilterValues {
  customer_id: string
  status: SettlementStatus | null
  agingBucket: AgingBucketValue | null
  /** Due Date range — the pre-existing "From/To" filter, relabeled for clarity now that Invoice Date is a second, independent range. */
  dateFrom: string
  dateTo: string
  invoiceDateFrom: string
  invoiceDateTo: string
  branch_id: string
  sales_person_id: string
}
