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
  warehouse_id: string
  status: PurchaseDocumentStatus | null
  dateFrom: string
  dateTo: string
}

/** By Supplier tab — one row per supplier, sourced from Goods Receipt (net of Returns), never Purchase Order. */
export interface PurchaseBySupplierRow {
  id: string
  supplier_code: string
  supplier_name: string
  receipt_count: number
  qty: number
  amount: number
}

export interface PurchaseBySupplierKpis {
  total_purchases: number
  active_supplier_count: number
  top_supplier_name: string | null
  top_supplier_amount: number
}

/** By Item tab — one row per item, netted the same way as By Supplier; price fields are null when the item had no in-period Goods Receipt. */
export interface PurchaseByItemRow {
  id: string
  item_code: string | null
  item_name: string
  uom: string | null
  qty: number
  amount: number
  avg_price: number | null
  last_price: number | null
  lowest_price: number | null
  highest_price: number | null
}

export interface PurchaseByItemHistoryRow {
  date: string
  gr_number: string | null
  po_number: string | null
  supplier_name: string
  qty: number
  rate: number
  amount: number
}

export type ReceivingStatus = 'not_received' | 'partial' | 'complete'

/** Distinguishes a real, manually-created PO from one fabricated by the Purchase History import — the two import origins are never conflated even though both are "PurchaseOrder-with-placeholder-item". */
export type PoImportSourceType = 'historical_invoice' | 'po_tracking_amount'

/**
 * PO Tracking tab — submitted Purchase Orders whose Goods Receipts haven't fully arrived yet.
 * Every qty-based field is null for a row with import_source_type set — its qty came from a
 * fabricated placeholder line, never a real "ordered N" fact, so the UI renders "-" instead of a
 * misleading 0/1. fulfillment_pct_value is only ever non-null for 'po_tracking_amount' rows
 * (straight from the source file's own AMOUNT BILLED / AMOUNT, no qty involved at all).
 */
export interface PoTrackingRow {
  id: string
  order_date: string
  document_number: string | null
  supplier_name: string
  total_amount: number
  ordered_qty: number | null
  received_qty: number | null
  remaining_qty: number | null
  fulfillment_pct: number | null
  receiving_status: ReceivingStatus | null
  is_overdue: boolean
  import_source_type: PoImportSourceType | null
  amount_billed: number | null
  outstanding_grn_value: number | null
  outstanding_po_value: number | null
  fulfillment_pct_value: number | null
}

export interface PoTrackingItemRow {
  item_name: string
  ordered_qty: number
  received_qty: number
  remaining_qty: number
}

/** PO Tracking's per-row drill-down — a real PO's item breakdown, or (for an imported row) the optional legacy fields instead, never the fabricated placeholder line. */
export interface PoTrackingItemsResponse {
  is_import: boolean
  import_source_type: PoImportSourceType | null
  extra: Record<string, string | null> | null
  items: PoTrackingItemRow[]
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
  // null in Sales Archive mode -- Qty isn't a column in the "01 Sales Listing" export.
  qty: number | null
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

/**
 * Open Orders tab — one row per Sales Order line still outstanding. delivery_status/invoice_status
 * are two independent labels (a line can be, say, fully delivered but only partially invoiced) —
 * see OpenOrdersRowResource on the backend for why "outstanding" is defined off qty_invoiced, not
 * qty_delivered.
 */
export type DeliveryLineStatus = 'not_delivered' | 'partially_delivered' | 'fully_delivered'
export type InvoiceLineStatus = 'not_invoiced' | 'partially_invoiced' | 'fully_invoiced'
export type AgingBucket = '0-7' | '8-30' | '31-60' | 'over_60'

export interface OpenOrdersRow {
  id: string
  sales_order_id: string
  document_number: string | null
  order_date: string
  expected_delivery_date: string | null
  customer_name: string
  sales_person_name: string
  branch_name: string | null
  item_code: string | null
  item_name: string
  qty_ordered: number
  qty_delivered: number
  qty_invoiced: number
  qty_outstanding: number
  outstanding_value: number
  delivery_status: DeliveryLineStatus
  invoice_status: InvoiceLineStatus
  age_in_days: number
  is_overdue: boolean
}

export interface OpenOrdersKpis {
  total_outstanding_value: number
  open_so_count: number
  overdue_value: number
  avg_age_days: number
}

/**
 * Sales Listing tab — one row per Invoice or Credit Note document. A Credit Note row's amount/
 * discount/tax/amount_incl_tax are already negative (see SalesListingRowResource on the backend),
 * so a plain sum nets correctly. payment_status/outstanding_ar are null for Credit Note rows.
 */
// Archive mode can surface a raw, unrecognized Skybiz type code verbatim (see
// SalesListingArchiveService::mapTypeLabel) rather than force it into the 2 known values --
// `(string & {})` keeps autocomplete for the 2 literals while still accepting any string.
export type SalesListingType = 'invoice' | 'credit_note' | (string & {})
export type PaymentStatus = 'unpaid' | 'partially_paid' | 'paid'

export interface SalesListingRow {
  id: string
  type: SalesListingType
  document_number: string | null
  date: string
  reference_so_number: string | null
  reference_do_number: string | null
  customer_code: string
  customer_name: string
  sales_person_name: string
  branch_name: string | null
  amount: number
  discount: number
  tax: number
  amount_incl_tax: number
  payment_status: PaymentStatus | null
  outstanding_ar: number | null
}

export interface SalesListingKpis {
  net_sales: number
  total_tax: number
  gross: number
  invoice_count: number
  paid_value: number
  unpaid_value: number
}

/**
 * Gross Profit report — Profit = Penjualan (excl. tax) - HPP, sourced from validated Sales Invoice
 * lines net of Credit Notes. Split out of Sales Report's old Margin tab (2026-09-19) into its own
 * top-level report — same shapes, unchanged. One shared row shape covers all 3 grouping modes
 * (fields the active mode doesn't use come back null from GrossProfitRowResource) — same approach
 * ProductSalesRow already uses for its item/item_group toggle. `hpp_missing` (cost_amount summed to
 * exactly 0 — e.g. a Transportation line, or a Goods line the FIFO backfill couldn't resolve)
 * drives the "HPP belum tercatat" warning icon; it's already excluded from `avg_margin_pct`
 * server-side.
 */
export type GrossProfitGroupBy = 'item' | 'customer' | 'invoice'

export interface GrossProfitRow {
  id: string
  item_code: string | null
  item_name: string | null
  customer_code: string | null
  customer_name: string | null
  invoice_count: number | null
  date: string | null
  document_number: string | null
  sales_person_name: string | null
  qty: number | null
  amount: number
  cost_amount: number
  profit: number
  margin_pct: number
  hpp_missing: boolean
}

export interface GrossProfitKpis {
  total_sales: number
  total_cost: number
  total_profit: number
  avg_margin_pct: number
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

/** Kartu Piutang — one customer's running ledger row. document_type matches the exact labels features/accounting/lib/journalReferenceLink.ts already switches on, reused as-is for the clickable Nomor Dokumen link. */
export interface ArLedgerRow {
  date: string
  document_type: 'Invoice' | 'Receipt Entry' | 'Credit Note' | 'Debit Note'
  document_number: string | null
  reference_id: string | null
  description: string | null
  due_date: string | null
  debit: number
  credit: number
  running_balance: number
}

/** Same due-date-anchored bucket cutoffs as AP Detail's "Perincian Hutang" (not_due/1-30/31-60/61-90/>90), applied to one customer's live outstanding AR rows. */
export interface ArLedgerAging {
  not_due: number
  due_1_30: number
  due_31_60: number
  due_61_90: number
  due_over_90: number
}

/** Branch/Sales Person aren't real Customer attributes in this schema — both are derived server-side from the customer's most recent Invoice in period (or overall), see AccountsReceivableRepository::latestInvoiceContext(). */
export interface ArLedgerHeader {
  customer_id: string
  customer_code: string
  customer_name: string
  customer_address: string | null
  branch_name: string | null
  sales_person_name: string | null
  terms_of_payment_name: string | null
}

export interface ArLedger {
  header: ArLedgerHeader
  opening_balance: number
  rows: ArLedgerRow[]
  closing_balance: number
  aging: ArLedgerAging
}

/** AP Detail's "Perincian Hutang" — one flat row per supplier with due-date-anchored aging buckets. Unlike AR's grouped view (Sales Person -> Customer, no bucket columns), AP has no Sales Person concept, so this is a single level, and the buckets themselves come from the ticket's own spec, not AR's legacy export scheme. */
export interface ApAgingBucketRow {
  supplier_id: string
  supplier_name: string
  not_due: number
  due_1_30: number
  due_31_60: number
  due_61_90: number
  due_over_90: number
  total: number
}

export interface ApAgingBucketTotals {
  not_due: number
  due_1_30: number
  due_31_60: number
  due_61_90: number
  due_over_90: number
  total: number
}

export interface ApDetailGroupedDetail {
  rows: ApAgingBucketRow[]
  total: ApAgingBucketTotals
}

export interface ApDetailReportFilterValues {
  supplier_id: string
  warehouse_id: string
  status: SettlementStatus | null
  agingBucket: AgingBucketValue | null
  dateFrom: string
  dateTo: string
  invoiceDateFrom: string
  invoiceDateTo: string
}

/** AP Detail's 4 summary cards — see AccountsPayableService::summaryCards(), same ground truth as the main report, never a separately-computed figure. */
export interface ApDetailSummary {
  total_outstanding: number
  due_this_week: number
  overdue: number
  unallocated_total: number
}

/** "Uang Muka / Belum Teralokasi" panel — Supplier Payment Vouchers not yet applied to any invoice. No AR equivalent exists. */
export interface UnallocatedPaymentVoucher {
  id: string
  payment_date: string | null
  document_number: string | null
  supplier_name: string | null
  unallocated_amount: number
  payment_method: string | null
}

/**
 * Tax report ("PPN Keluaran"/"PPN Masukan") — one row per document
 * (Invoice/Purchase Invoice) or reduction (Credit Note/Purchase Return),
 * synthesized server-side, never a stored document of its own. Purchase
 * Invoice has no tax-code trail anywhere in its schema, so tax_id/
 * tax_code/tax_rate are always null on `document_type: 'purchase_invoice'
 * | 'purchase_return'` rows — not a bug, a real data gap (see the report's
 * own D-0a finding).
 */
export interface TaxReportRow {
  document_id: string
  document_type: 'invoice' | 'credit_note' | 'purchase_invoice' | 'purchase_return'
  document_number: string | null
  document_date: string
  party_id: string
  party_name: string
  branch_id: string | null
  warehouse_id: string | null
  tax_id: string | null
  tax_code: string | null
  tax_rate: number | null
  dpp: number
  ppn: number
  total: number
}

/** Selisih = output_ppn - input_ppn. Positive = "Kurang Bayar", negative = "Lebih Bayar" — the label is resolved from this sign client-side, never stored. */
export interface TaxReportSummary {
  output_dpp: number
  output_ppn: number
  input_dpp: number
  input_ppn: number
  selisih: number
}

export interface TaxReportFilterValues {
  dateFrom: string
  dateTo: string
  tax_id: string
  customer_id: string
  supplier_id: string
  branch_id: string
  warehouse_id: string
}

/**
 * Purchase Report's smart import (Purchase Orders tab) — one click, auto-detects Supplier Purchase
 * Listing / Product Purchase Report / Purchase Order Tracking, no manual column mapping. See
 * PurchaseHistoryImportService on the backend. store() always leaves the batch `previewed` with
 * the mandatory pre-import summary (shown before anything is queued, ticket requirement) —
 * resolve() is the universal confirm-and-queue step, called whether or not `needs_resolution`
 * came back non-empty.
 */
export type PurchaseHistoryImportBatchStatus = 'previewed' | 'queued' | 'processing' | 'completed' | 'failed'
export type PurchaseHistoryImportType = 'supplier_purchase_listing' | 'product_purchase_report' | 'purchase_order_tracking'
export type PurchaseHistoryResolutionCategory = 'supplier' | 'item' | 'duplicate'
export type PurchaseHistoryResolutionAction = 'create' | 'map' | 'skip' | 'proceed'

export interface PurchaseHistoryFkSuggestion {
  id: string
  value: string
  score: number
}

export interface PurchaseHistoryResolutionEntry {
  category: PurchaseHistoryResolutionCategory
  value: string
  status: string
  suggestions: PurchaseHistoryFkSuggestion[]
}

export interface PurchaseHistoryImportOutcome {
  document_number: string
  status: 'success' | 'needs_review' | 'failed'
  reason: string | null
}

export interface PurchaseHistoryImportPreviewSummary {
  type_label?: string
  valid_count?: number
  skipped_count?: number
  computed_defaults?: string[]
  needs_resolution?: PurchaseHistoryResolutionEntry[]
  warnings?: string[]
  needs_review_rows?: number
  vouchers?: PurchaseHistoryImportOutcome[]
  item_snapshot?: PurchaseHistoryItemSnapshotRow[]
  period_from?: string | null
  period_to?: string | null
}

export interface PurchaseHistoryImportBatch {
  id: string
  status: PurchaseHistoryImportBatchStatus
  total_rows: number
  processed_rows: number
  success_rows: number
  failed_rows: number
  failure_reason: string | null
  preview_summary: PurchaseHistoryImportPreviewSummary | null
}

/** By Item tab's separate "Data Import Historis" section — a period-level aggregate from a Product Purchase Report import, never merged into the live Goods-Receipt-based table above. */
export interface PurchaseHistoryItemSnapshotRow {
  item_code: string
  item_name: string
  qty: number
  amount: number
  avg_price: number
  item_id: string | null
  uom: string | null
  resolved: boolean
}

export interface PurchaseHistoryImportSnapshotBatch {
  batch_id: string
  period_from: string | null
  period_to: string | null
  imported_at: string | null
  items: PurchaseHistoryItemSnapshotRow[]
}

// "Piutang Customer (Arsip Import)" -- standalone archive of imported legacy AR export
// snapshots, not connected to the live Sales/Invoice/Customer/AR module. See
// CustomerOutstandingArchiveImportService on the backend.
// 'lunas' deliberately excluded -- this file only ever contains unpaid invoices, so no line can
// ever actually be settled; see CustomerOutstandingSnapshotLine::status() on the backend.
export type CustomerOutstandingArchiveStatus = 'outstanding' | 'overdue'

export interface CustomerOutstandingSnapshot {
  id: string
  source_filename: string
  company_name: string | null
  snapshot_as_of_date: string
  total_rows: number
  total_customers: number
  grand_total_unpaid: string
  grand_total_overdue: string
  importer: { id: string; name: string } | null
  created_at: string
}

export interface CustomerOutstandingArchiveLine {
  id: string
  txn_date: string
  ref_no: string
  invoice_amount: number
  paid_amount: number
  unpaid_amount: number
  terms_days: number | null
  due_date: string
  overdue_amount: number
  overdue_days: number
  status: CustomerOutstandingArchiveStatus
}

export interface CustomerOutstandingArchiveCustomerGroup {
  customer_code: string
  customer_name: string
  rows: CustomerOutstandingArchiveLine[]
  subtotal_unpaid: number
  subtotal_overdue: number
}

export interface CustomerOutstandingArchiveSummary {
  total_unpaid: number
  due_this_week: number
  overdue: number
}

export interface CustomerOutstandingArchiveDetail {
  snapshot: CustomerOutstandingSnapshot
  summary: CustomerOutstandingArchiveSummary
  customers: CustomerOutstandingArchiveCustomerGroup[]
  grand_total_unpaid: number
  grand_total_overdue: number
}

export interface CustomerOutstandingArchiveFailedRow {
  row: number
  reason: string
}

export interface CustomerOutstandingArchiveSubtotalMismatch {
  customer_code: string
  customer_name: string
  row: number
  file_unpaid: number | null
  file_overdue: number | null
  computed_unpaid: number
  computed_overdue: number
}

export interface CustomerOutstandingArchiveGrandTotalMismatch {
  file_unpaid: number
  file_overdue: number
  computed_unpaid: number
  computed_overdue: number
}

/** ImportBatch.preview_summary shape while status='previewed' -- from CustomerOutstandingArchiveImportService::preflight(). */
export interface CustomerOutstandingArchivePreflight {
  company_name: string | null
  snapshot_as_of_date: string
  total_rows: number
  total_customers: number
  total_unpaid: number
  total_overdue: number
  failed_rows: CustomerOutstandingArchiveFailedRow[]
  subtotal_mismatches: CustomerOutstandingArchiveSubtotalMismatch[]
  grand_total_mismatch: CustomerOutstandingArchiveGrandTotalMismatch | null
}

export interface CustomerOutstandingArchiveImportBatch {
  id: string
  status: 'previewed' | 'completed' | 'failed'
  preview_summary: CustomerOutstandingArchivePreflight | null
}

export interface CustomerOutstandingArchiveFilterValues {
  customer: string
  invoiceDateFrom: string
  invoiceDateTo: string
  dueDateFrom: string
  dueDateTo: string
  status: CustomerOutstandingArchiveStatus | null
}

// "Supplier Outstanding Bills" -- AP mirror of the Customer Outstanding Bills archive above. See
// SupplierOutstandingArchiveImportService on the backend.
export type SupplierOutstandingArchiveStatus = 'outstanding' | 'overdue'

export interface SupplierOutstandingSnapshot {
  id: string
  source_filename: string
  company_name: string | null
  snapshot_as_of_date: string
  total_rows: number
  total_suppliers: number
  grand_total_unpaid: string
  grand_total_overdue: string
  importer: { id: string; name: string } | null
  created_at: string
}

export interface SupplierOutstandingArchiveLine {
  id: string
  txn_date: string
  ref_no: string
  invoice_amount: number
  paid_amount: number
  unpaid_amount: number
  terms_days: number | null
  due_date: string
  overdue_amount: number
  overdue_days: number
  status: SupplierOutstandingArchiveStatus
}

export interface SupplierOutstandingArchiveSupplierGroup {
  supplier_code: string
  supplier_name: string
  rows: SupplierOutstandingArchiveLine[]
  subtotal_unpaid: number
  subtotal_overdue: number
}

export interface SupplierOutstandingArchiveSummary {
  total_unpaid: number
  due_this_week: number
  overdue: number
}

export interface SupplierOutstandingArchiveDetail {
  snapshot: SupplierOutstandingSnapshot
  summary: SupplierOutstandingArchiveSummary
  suppliers: SupplierOutstandingArchiveSupplierGroup[]
  grand_total_unpaid: number
  grand_total_overdue: number
}

export interface SupplierOutstandingArchiveFailedRow {
  row: number
  reason: string
}

export interface SupplierOutstandingArchiveSubtotalMismatch {
  supplier_code: string
  supplier_name: string
  row: number
  file_unpaid: number | null
  file_overdue: number | null
  computed_unpaid: number
  computed_overdue: number
}

export interface SupplierOutstandingArchiveGrandTotalMismatch {
  file_unpaid: number
  file_overdue: number
  computed_unpaid: number
  computed_overdue: number
}

export interface SupplierOutstandingArchivePreflight {
  company_name: string | null
  snapshot_as_of_date: string
  total_rows: number
  total_suppliers: number
  total_unpaid: number
  total_overdue: number
  failed_rows: SupplierOutstandingArchiveFailedRow[]
  subtotal_mismatches: SupplierOutstandingArchiveSubtotalMismatch[]
  grand_total_mismatch: SupplierOutstandingArchiveGrandTotalMismatch | null
}

export interface SupplierOutstandingArchiveImportBatch {
  id: string
  status: 'previewed' | 'completed' | 'failed'
  preview_summary: SupplierOutstandingArchivePreflight | null
}

export interface SupplierOutstandingArchiveFilterValues {
  supplier: string
  invoiceDateFrom: string
  invoiceDateTo: string
  dueDateFrom: string
  dueDateTo: string
  status: SupplierOutstandingArchiveStatus | null
}

/**
 * Sales Report's import archive -- 2 file types, never merged, feeding Sales Listing/Customer
 * Sales (File A, "01 Sales Listing") and Product Sales (File B, "13 Product Sales Report -
 * Detail"). Unlike the AR/AP archives above, snapshots stack per period rather than "latest
 * wins" -- see SalesArchiveMeta. Row shapes for the 3 read endpoints deliberately reuse the
 * existing live SalesListingRow/CustomerSalesRow/ProductSalesRow/ProductSalesKpis/etc. types
 * above, since the backend shapes archive rows to match them exactly.
 */
export type SalesArchiveFileType = 'sales_listing' | 'product_sales_detail'

export interface SalesArchiveFileTypeMeta {
  has_snapshot: boolean
  period_start: string | null
  period_end: string | null
}

export interface SalesArchiveMeta {
  sales_listing: SalesArchiveFileTypeMeta
  product_sales_detail: SalesArchiveFileTypeMeta
}

export interface SalesArchiveHistoryEntry {
  id: string
  file_type: SalesArchiveFileType
  period_start: string
  period_end: string
  source_filename: string
  total_rows: number
  total_value: number
  created_at: string
}

export interface SalesArchiveFailedRow {
  row: number
  reason: string
}

export interface SalesArchiveSubtotalMismatch {
  item_code: string
  item_description: string
  row: number
  file_qty: number
  file_amount: number
  computed_qty: number
  computed_amount: number
}

// Shape differs by file type: Product Sales Detail mismatches on a single amount (file_amount vs
// computed_amount); Sales Listing's trailing "TOTAL" row carries both excl./incl. tax columns.
export interface SalesArchiveGrandTotalMismatch {
  file_amount?: number
  computed_amount?: number
  file_amount_excl_tax?: number
  file_amount_incl_tax?: number
  computed_amount_excl_tax?: number
  computed_amount_incl_tax?: number
}

export interface SalesArchivePreflight {
  file_type: SalesArchiveFileType
  period_start: string
  period_end: string
  company_name: string | null
  total_rows: number
  total_documents?: number
  total_items?: number
  grand_total_amount_excl_tax: number
  grand_total_amount_incl_tax?: number
  grand_total_qty?: number
  failed_rows: SalesArchiveFailedRow[]
  subtotal_mismatches?: SalesArchiveSubtotalMismatch[]
  grand_total_mismatch?: SalesArchiveGrandTotalMismatch | null
}

export interface SalesArchiveImportBatch {
  id: string
  status: 'previewed' | 'completed' | 'failed'
  preview_summary: SalesArchivePreflight | null
}
