import type { DocumentStatus as PurchaseDocumentStatus } from '@/features/purchase/types'
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

/**
 * Sales Report rework — one shared filter shape across all 4 tabs (Product/Customer/Open Orders/
 * Listing); each tab's panel only shows the filter fields it actually uses. `status` is a plain
 * string rather than one specific enum since Product/Customer/Listing filter Invoice's
 * DocumentStatus (draft/submitted/cancelled) while Open Orders filters SalesOrderStatus
 * (submitted/approved/cancelled) — different value sets, so each panel supplies its own status
 * options to SalesReportFiltersBar rather than the shared type picking one.
 */
export interface SalesReportFilterValues {
  customer_id: string
  item_id: string
  item_group_id: string
  sales_person_id: string
  branch_id: string
  status: string | null
  dateFrom: string
  dateTo: string
}

/** Product Sales tab — one row per item (or per Item Group, when grouped). */
export type SalesReportGroupBy = 'item' | 'item_group'

export interface ProductSalesRow {
  id: string
  is_group: boolean
  item_code: string | null
  item_name: string
  item_group_name: string | null
  uom_name: string | null
  sku_count: number | null
  qty: number
  amount: number
  tax_amount: number
  amount_incl_tax: number
}

export interface ProductSalesKpis {
  total_qty: number
  total_revenue: number
  total_tax: number
  total_incl_tax: number
  sku_count: number
  top_item_name: string | null
  top_item_amount: number
}

export interface ProductSalesCustomerRow {
  customer_id: string
  customer_code: string
  customer_name: string
  qty: number
  amount: number
}

/** Customer Sales tab — one row per customer; branch_name/sales_person_name are null ("Multiple") when a customer's invoices don't all agree on one value. */
export interface CustomerSalesRow {
  id: string
  customer_code: string
  customer_name: string
  branch_name: string | null
  sales_person_name: string | null
  transaction_count: number
  qty: number
  amount: number
  tax_amount: number
  amount_incl_tax: number
  last_transaction_date: string | null
}

export interface CustomerSalesKpis {
  total_customers: number
  total_revenue: number
  total_tax: number
  total_incl_tax: number
  avg_per_customer: number
  top_customer_name: string | null
  top_customer_amount: number
}

export interface CustomerSalesDocumentRow {
  id: string
  date: string | null
  document_number: string | null
  reference_so_number: string | null
  type: string | null
  amount: number
  tax_amount: number
  amount_incl_tax: number
}

export interface CustomerSalesDocuments {
  documents: CustomerSalesDocumentRow[]
  subtotal: { amount: number; tax_amount: number; amount_incl_tax: number }
}

export interface SalesAchievementRow {
  sales_person_id: string | null
  sales_person_name: string
  qty: number
  amount: number
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
