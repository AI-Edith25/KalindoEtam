import type { ApprovalFlow } from '../approval/types'

export type DocumentStatus = 'draft' | 'submitted' | 'cancelled'

/** Sales Order's own lifecycle — Submitted (awaiting approval) -> Approved (locked, deliverable) -> Cancelled. */
export type SalesOrderStatus = 'submitted' | 'approved' | 'cancelled'

/** Delivery's own lifecycle — Pending (created, stock not yet moved) -> Complete (stock moved). No Cancel/Void. */
export type DeliveryStatus = 'pending' | 'complete' | 'cancelled'

/** Drives which Naming Series generates an Invoice's document_number — see Invoice::documentType() on the backend. */
export type InvoiceType = 'goods' | 'transportation'

/** Drives whether Invoice.discount_amount is a fixed Rupiah figure or derived from discount_percentage — see InvoiceService::resolveDiscount() on the backend. */
export type DiscountType = 'amount' | 'percentage'

export interface SalesOrderItem {
  id: string
  item_id: string
  item_code: string | null
  item_name: string | null
  uom: string | null
  /** null = the item's base UOM. qty/rate/delivered_qty are in this UOM; uom_factor = base units per 1 of it. */
  uom_id?: string | null
  uom_factor?: string | number
  /** The item's current UOM choices (base first) — repopulates the editor's UOM picker. */
  item_uoms?: import('@/features/master/types').ItemUomChoice[] | null
  qty: number
  rate: string | number
  amount: string | number
  discount_type: DiscountType
  discount_value: string | number
  discount_amount: string | number
  /** amount - discount_amount — the base PPN is actually computed against (DPP). */
  net_amount: string | number
  tax_id: string | null
  tax: { id: string; code: string; name: string; type: string; rate: string | number; calculation_mode: string } | null
  tax_amount: string | number
  delivered_qty: number
  outstanding_qty: number
  /** True once any Delivery references this line — locked against edit/removal on an Approved order. */
  is_locked: boolean
}

export interface SalesOrder {
  id: string
  document_number: string | null
  status: SalesOrderStatus
  revision: number
  customer_id: string
  customer: {
    id: string
    customer_code: string
    customer_name: string
    terms_of_payment_id: string | null
    phone: string | null
    address: string | null
  } | null
  sales_person_id: string | null
  sales_person: { id: string; code: string; name: string } | null
  branch_id: string | null
  branch: { id: string; code: string; name: string } | null
  warehouse_id: string | null
  warehouse: { id: string; code: string; name: string } | null
  order_date: string
  expected_delivery_date: string | null
  total_amount: string | number
  total_discount: string | number
  /** total_amount - total_discount — the base PPN is actually computed against (DPP). */
  tax_base: string | number
  tax_id: string | null
  tax: { id: string; code: string; name: string; type: string; rate: string | number; calculation_mode: string } | null
  tax_amount: string | number
  grand_total: string | number
  remarks: string | null
  attention: string | null
  tel: string | null
  fax: string | null
  reference: string | null
  terms_of_payment_id: string | null
  terms_of_payment: { id: string; code: string; name: string; days: number } | null
  items: SalesOrderItem[]
  is_fully_delivered: boolean | null
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
  requires_approval: boolean
  latest_approval: ApprovalFlow | null
}

export interface SalesOrderFormValues {
  customer_id: string
  sales_person_id: string | null
  branch_id: string
  warehouse_id: string
  order_date: string
  expected_delivery_date: string | null
  remarks: string | null
  attention?: string | null
  tel?: string | null
  fax?: string | null
  reference?: string | null
  terms_of_payment_id?: string | null
  tax_id?: string | null
  items: { id?: string; item_id: string; uom_id?: string | null; qty: number; rate: number; discount_type?: DiscountType; discount_value?: number; tax_id?: string | null }[]
  override_credit_block?: boolean
  override_reason?: string | null
  override_stock_block?: boolean
  stock_override_reason?: string | null
}

/** Sales Order credit/overdue block — see CustomerCreditService on the backend. additional_amount is always 0 (the customer-select-time check); the "would this order's own value push it over" layer is computed client-side, see evaluateCreditBlock(). */
export interface CustomerCreditStatus {
  is_overdue: boolean
  is_over_limit: boolean
  is_blocked: boolean
  overdue_invoices: { id: string; reference_number: string | null; due_date: string; outstanding_amount: number }[]
  outstanding_total: number
  credit_limit: number | null
  available_credit: number | null
  message: string
}

/** Sales Order Detail page's own Approve button pre-check — see SalesOrderService::stockStatusFor(). No client-side recompute possible here (no live form/line items to denormalize available_qty onto), unlike the Editor page's evaluateStockBlock(). */
export interface SalesOrderStockStatus {
  is_blocked: boolean
  message: string
  lines: {
    item_id: string
    item_name: string
    requested_qty: number
    physical_qty: number
    available_qty: number
    is_insufficient: boolean
  }[]
}

export interface DeliveryItem {
  id: string
  // Null for a Direct Delivery line (no source Sales Order) — see DeliveryService::createDirect().
  sales_order_item_id: string | null
  item_id: string
  item_code: string
  item_name: string
  uom: string
  qty: number
  rate: string | number
  amount: string | number
  // SO-sourced lines derive this from the Sales Order line (never user-entered) — see
  // DeliveryService::buildDeliveryLineAttributes()'s allocation rule. Direct Delivery lines accept it directly.
  discount_type: DiscountType
  discount_value: string | number
  discount_amount: string | number
  net_amount: string | number
  tax_id: string | null
  tax: { id: string; code: string; name: string; type: string; rate: string | number; calculation_mode: string } | null
  tax_amount: string | number
  // Locks this row against removal on the edit screen once true — see DeliveryService::updateComplete().
  is_invoiced: boolean
}

export interface Delivery {
  id: string
  document_number: string | null
  status: DeliveryStatus
  revision: number
  lock_version: number
  // Null for a Direct Delivery (no source Sales Order) — see DeliveryService::createDirect().
  sales_order_id: string | null
  sales_order: {
    id: string
    document_number: string | null
    attention: string | null
    tel: string | null
    fax: string | null
    reference: string | null
    sales_person: { id: string; code: string; name: string } | null
    tax_id: string | null
    tax: { id: string; code: string; name: string; type: string; rate: string | number; calculation_mode: string } | null
    remarks: string | null
  } | null
  // Every Sales Order this Delivery was created from, one or many — sales_order/sales_order_id
  // above stay the anchor (earliest order_date) for backward compatibility. See DeliveryService::create().
  sales_orders: { id: string; document_number: string | null }[]
  customer_id: string
  customer: {
    id: string
    customer_code: string
    customer_name: string
    terms_of_payment_id: string | null
    phone: string | null
    address: string | null
  } | null
  warehouse_id: string
  warehouse: { id: string; name: string; code: string } | null
  // This Delivery's own override — null falls back to sales_order.sales_person/attention/tel/fax
  // for display. A non-null value here is a per-Delivery correction (DeliveryService::updateComplete()).
  sales_person_id: string | null
  sales_person: { id: string; code: string; name: string } | null
  attention: string | null
  tel: string | null
  fax: string | null
  delivery_date: string
  due_date: string
  terms_of_payment_id: string | null
  terms_of_payment: { id: string; code: string; name: string; days: number } | null
  remarks: string | null
  fleet: string | null
  driver: string | null
  items: DeliveryItem[]
  amount: string | number
  // Computed live from items — a Delivery has no header total columns (never has).
  discount_amount: string | number
  tax_base: string | number
  // Per-line now — only resolves to a single value here when every line shares the same
  // Tax; a mixed-tax shipment leaves these null while tax_amount stays an accurate sum. See DeliveryResource.
  tax_id: string | null
  tax: { id: string; code: string; name: string; type: string; rate: string | number; calculation_mode: string } | null
  tax_amount: string | number
  is_invoiced: boolean | null
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
  updated_at: string
  updater: { id: string; name: string } | null
}

export interface DeliveryFormValues {
  // Omitted for a Direct Delivery (no source Sales Order) — customer_id is required instead.
  // One or more Sales Orders may be combined into a single Delivery (mirrors delivery_ids on
  // Invoice) — see DeliveryService::create().
  sales_order_ids?: string[]
  customer_id?: string
  warehouse_id: string
  sales_person_id?: string | null
  attention?: string | null
  tel?: string | null
  fax?: string | null
  delivery_date: string
  due_date: string
  terms_of_payment_id: string | null
  remarks: string | null
  fleet: string | null
  driver: string | null
  // Required once editing a Complete Delivery (DeliveryService::updateComplete()'s optimistic-lock check).
  lock_version?: number
  // discount_type/discount_value are only meaningful for a Direct Delivery line (no sales_order_id)
  // — an SO-linked line always derives its discount from the linked Sales Order line instead.
  items: { id?: string; sales_order_item_id?: string | null; item_id?: string; qty: number; rate?: number; discount_type?: DiscountType; discount_value?: number; tax_id?: string | null }[]
}

export type InvoiceDisplayStatus = 'draft' | 'unpaid' | 'partial' | 'paid' | 'cancelled'

export interface InvoiceItem {
  id: string
  delivery_item_id: string | null
  item_id: string | null
  item_code: string | null
  item_name: string
  uom: string | null
  qty: string | number
  // Snapshot at creation — drives whole-vs-decimal qty input/display, mirrors App\Enums\QtyCategory.
  qty_category: 'unit' | 'weight' | null
  rate: string | number
  amount: string | number
  discount_type: DiscountType
  discount_value: string | number
  discount_amount: string | number
  net_amount: string | number
  tax_id: string | null
  tax: { id: string; code: string; name: string; type: string; rate: string | number; calculation_mode: string } | null
  tax_amount: string | number
  credited_qty: number
  credited_amount: string | number
  creditable_qty: number
  creditable_amount: string | number
  sales_person: { id: string; code: string; name: string } | null
}

export interface InvoicePaymentHistoryLine {
  id: string
  received_amount: string | number
  receipt_entry_id: string
  receipt_entry_document_number: string | null
  receipt_date: string | null
  cash_account_name: string | null
  payment_method: 'cash' | 'bank_transfer' | 'cheque' | 'qris' | 'credit_card' | null
}

export interface Invoice {
  id: string
  document_number: string | null
  invoice_type: InvoiceType
  status: DocumentStatus
  display_status: InvoiceDisplayStatus
  revision: number
  delivery_id: string | null
  delivery: { id: string; document_number: string | null; warehouse: { id: string; name: string; code: string } | null } | null
  deliveries: { id: string; document_number: string | null }[]
  // Goods (Direct) only — captured directly at creation (no Delivery to inherit it from). Null for every other flow.
  warehouse_id: string | null
  warehouse: { id: string; name: string; code: string } | null
  // The printed/displayed Location — every invoice has one (defaults from the Delivery's own
  // warehouse, or warehouse_id for Direct Goods), independently editable at any status. Purely
  // cosmetic, never affects stock — unlike warehouse_id above.
  location_warehouse_id: string | null
  location_warehouse: { id: string; name: string; code: string } | null
  // Imported invoices only — see Invoice::movesStock() on the backend. import_source_type is
  // set once at import and never changes; affects_stock is the toggle editable afterward.
  import_source_type: string | null
  affects_stock: boolean
  sales_order_id: string | null
  sales_orders: { id: string; document_number: string | null }[]
  sales_order: {
    id: string
    document_number: string | null
    attention: string | null
    tel: string | null
    fax: string | null
    sales_person: { id: string; code: string; name: string } | null
    branch: { id: string; name: string; code: string } | null
  } | null
  // Transportation only — captured directly at creation (no Sales Order to derive it from). Null for Goods.
  branch_id: string | null
  branch: { id: string; name: string; code: string } | null
  customer_id: string
  customer: { id: string; customer_code: string; customer_name: string; phone: string | null; address: string | null } | null
  sales_person_id: string | null
  sales_person: { id: string; code: string; name: string } | null
  invoice_date: string
  due_date: string
  terms_of_payment_id: string | null
  terms_of_payment: { id: string; code: string; name: string; days: number } | null
  subtotal: string | number
  discount_amount: string | number
  discount_type: DiscountType
  discount_percentage: string | number | null
  /** subtotal - discount_amount — the base PPN is actually computed against (DPP). */
  tax_base: string | number
  tax_id: string | null
  tax: { id: string; code: string; name: string; type: string; rate: string | number; calculation_mode: string } | null
  tax_amount: string | number
  grand_total: string | number
  paid_amount: string | number
  outstanding_amount: string | number
  credited_amount: string | number
  debited_amount: string | number
  creditable_amount: string | number
  remarks: string | null
  reference_1: string | null
  reference_2: string | null
  attention: string | null
  tel: string | null
  fax: string | null
  customer_address: string | null
  customer_phone: string | null
  lock_version: number
  source: 'manual' | 'import'
  imported_at: string | null
  items: InvoiceItem[]
  payment_history: InvoicePaymentHistoryLine[]
  credit_note_history: InvoiceCreditNoteHistoryLine[]
  debit_note_history: InvoiceDebitNoteHistoryLine[]
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
  updated_at: string
  updater: { id: string; name: string } | null
}

export interface InvoiceFormValues {
  // Delivery-based Goods only.
  delivery_ids?: string[]
  // Transportation and Goods (Direct) only — picked directly instead of derived from a Delivery.
  customer_id?: string
  // Transportation: manual freestanding lines (no Item/inventory link). Goods (Direct): real
  // Item-backed lines, same master data a Sales Order line resolves against. Submitted-edit
  // (InvoiceService::updateSubmitted()): existing line id + qty/rate/tax_id only, no add/remove.
  items?:
    | { description: string; qty: number; rate: number; uom?: string | null; discount_type?: DiscountType; discount_value?: number; tax_id?: string | null }[]
    | { item_id: string; qty: number; rate: number; discount_type?: DiscountType; discount_value?: number; tax_id?: string | null }[]
    | { id: string; qty?: number; rate?: number; discount_type?: DiscountType; discount_value?: number; tax_id?: string | null }[]
  // Transportation and Goods (Direct), create-only — no Sales Order to derive Branch from.
  // Also editable on a Submitted Invoice (InvoiceService::updateSubmitted()), hence the
  // nullable variant — null clears the override back to the Sales Order's own Branch.
  branch_id?: string | null
  // Goods (Direct) only — no Delivery to inherit a Location from. invoice_type stays 'goods'
  // either way (see Invoice::isDirectGoods()); this field alone is what the backend uses to
  // route to the Direct sub-flow.
  warehouse_id?: string
  invoice_type?: InvoiceType
  invoice_date: string
  due_date: string
  terms_of_payment_id: string | null
  // Goods invoices omit this entirely — the backend always inherits it from the Sales Order.
  tax_id?: string | null
  tax_amount: number | null
  remarks: string | null
  sales_person_id?: string | null
  reference_1?: string | null
  reference_2?: string | null
  attention?: string | null
  tel?: string | null
  fax?: string | null
  customer_address?: string | null
  customer_phone?: string | null
  // Required once editing a Submitted Invoice (InvoiceService::updateSubmitted()'s optimistic-lock check).
  lock_version?: number
  // Imported invoices only — see Invoice::movesStock() on the backend.
  affects_stock?: boolean
}

export interface InvoiceFilterValues {
  status: DocumentStatus | null
  source: 'manual' | 'import' | null
  dateFrom: string
  dateTo: string
}

/**
 * Approval-gated, one-time nominal unlock for a Submitted Transportation
 * Invoice — see InvoiceChangeRequestService on the backend. Deliberately not
 * an ApprovalFlow: that's a pre-submit "can this document be submitted"
 * gate, a different concept from this post-submit temporary edit window.
 */
export interface InvoiceChangeRequest {
  id: string
  invoice_id: string
  status: 'pending' | 'approved' | 'rejected'
  requested_by: { id: string; name: string; email: string } | null
  request_reason: string
  decided_by: { id: string; name: string; email: string } | null
  decision_remarks: string | null
  decided_at: string | null
  consumed_at: string | null
  created_at: string
}

export interface InvoiceCreditNoteHistoryLine {
  id: string
  document_number: string | null
  credit_note_date: string | null
  reason: CreditNoteReason
  total_amount: string | number
  status: DocumentStatus
  is_reversed: boolean
}

/**
 * Sprint 13B: the only accounting-correction path for a posted Invoice —
 * Invoice cancellation deliberately never touches the ledger. See
 * docs/CREDIT_NOTE_DESIGN.md. Reason is a classification only — the
 * mechanism (credit lines + optional header discount/tax adjustment) is
 * the same for every reason; it only drives this editor's defaults.
 */
export type CreditNoteReason = 'full_credit' | 'partial_credit' | 'price_adjustment' | 'returned_goods' | 'service_refund' | 'tax_adjustment'

export interface CreditNoteItem {
  id: string
  invoice_item_id: string
  item_id: string
  item_code: string
  item_name: string
  uom: string
  qty_credited: number
  rate: string | number
  amount: string | number
  restock: boolean
  inventory_impact: string | null
}

export interface CreditNote {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  invoice_id: string
  invoice: { id: string; document_number: string | null; grand_total: string | number } | null
  customer_id: string
  customer: { id: string; customer_code: string; customer_name: string } | null
  credit_note_date: string
  reason: CreditNoteReason
  subtotal: string | number
  discount_amount: string | number
  tax_amount: string | number
  total_amount: string | number
  remarks: string | null
  is_reversed: boolean
  reversed_at: string | null
  items: CreditNoteItem[]
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

export interface CreditNoteFormValues {
  invoice_id: string
  credit_note_date: string
  reason: CreditNoteReason
  discount_amount: number | null
  tax_amount: number | null
  remarks: string | null
  items: { invoice_item_id: string; qty_credited: number; amount: number; restock: boolean }[]
}

export interface InvoiceDebitNoteHistoryLine {
  id: string
  document_number: string | null
  debit_note_date: string | null
  reason: DebitNoteReason
  total_amount: string | number
  status: DocumentStatus
  is_reversed: boolean
}

/**
 * Sprint 14B: increases a customer's receivable after a posted Invoice —
 * the counterpart to Credit Note, with no upper bound (see
 * docs/DEBIT_NOTE_DESIGN.md). Reason is a classification only; the
 * mechanism is decided by line shape (item-linked vs. freestanding), never
 * by reason.
 */
export type DebitNoteReason = 'under_billed_invoice' | 'price_correction' | 'additional_service_charge' | 'freight_adjustment' | 'tax_adjustment'

export interface DebitNoteItem {
  id: string
  invoice_item_id: string | null
  item_id: string | null
  item_code: string | null
  item_name: string | null
  uom: string | null
  description: string
  qty_adjusted: number
  rate: string | number | null
  amount: string | number
}

export interface DebitNote {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  invoice_id: string
  invoice: { id: string; document_number: string | null; grand_total: string | number } | null
  customer_id: string
  customer: { id: string; customer_code: string; customer_name: string } | null
  debit_note_date: string
  reason: DebitNoteReason
  subtotal_goods: string | number
  subtotal_other: string | number
  tax_amount: string | number
  total_amount: string | number
  remarks: string | null
  is_reversed: boolean
  reversed_at: string | null
  items: DebitNoteItem[]
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

export interface DebitNoteFormValues {
  invoice_id: string
  debit_note_date: string
  reason: DebitNoteReason
  tax_amount: number | null
  remarks: string | null
  items: { invoice_item_id: string | null; description: string | null; qty_adjusted: number; rate: number | null; amount: number }[]
}

/**
 * Sales Invoice historical import — one click, one file shape ("Sales Invoice Listing - Detail"),
 * no manual column mapping. See SalesInvoiceImportService on the backend. Creates real, submitted
 * Invoice/InvoiceItem rows but never touches stock/AR/GL (AR/GL already backfilled by a separate
 * Customer Outstanding import) — same "previewed first, resolve() is the universal confirm-and-
 * queue step" contract as Purchase History import.
 */
export type SalesInvoiceHistoryImportBatchStatus = 'previewed' | 'queued' | 'processing' | 'completed' | 'failed'
export type SalesInvoiceHistoryResolutionCategory = 'customer' | 'item' | 'location' | 'duplicate'
export type SalesInvoiceHistoryResolutionAction = 'map' | 'skip' | 'proceed'

export interface SalesInvoiceHistoryFkSuggestion {
  id: string
  value: string
  score: number
}

export interface SalesInvoiceHistoryResolutionEntry {
  category: SalesInvoiceHistoryResolutionCategory
  value: string
  status: string
  suggestions: SalesInvoiceHistoryFkSuggestion[]
}

export interface SalesInvoiceHistoryImportOutcome {
  document_number: string
  status: 'success' | 'needs_review' | 'failed'
  reason: string | null
}

export interface SalesInvoiceHistoryImportPreviewSummary {
  valid_count?: number
  skipped_count?: number
  needs_resolution?: SalesInvoiceHistoryResolutionEntry[]
  warnings?: string[]
  needs_review_rows?: number
  vouchers?: SalesInvoiceHistoryImportOutcome[]
}

export interface SalesInvoiceHistoryImportBatch {
  id: string
  status: SalesInvoiceHistoryImportBatchStatus
  total_rows: number
  processed_rows: number
  success_rows: number
  failed_rows: number
  failure_reason: string | null
  preview_summary: SalesInvoiceHistoryImportPreviewSummary | null
  has_failed_rows: boolean
}

