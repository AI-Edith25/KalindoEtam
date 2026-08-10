import type { ApprovalFlow } from '../approval/types'

export type DocumentStatus = 'draft' | 'submitted' | 'cancelled'

/** Drives which Naming Series generates an Invoice's document_number — see Invoice::documentType() on the backend. */
export type InvoiceType = 'goods' | 'transportation'

/** Drives whether Invoice.discount_amount is a fixed Rupiah figure or derived from discount_percentage — see InvoiceService::resolveDiscount() on the backend. */
export type DiscountType = 'amount' | 'percentage'

export interface SalesOrderItem {
  id: string
  item_id: string
  item_code: string | null
  item_name: string | null
  qty: number
  rate: string | number
  amount: string | number
  delivered_qty: number
  outstanding_qty: number
}

export interface SalesOrder {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  customer_id: string
  customer: { id: string; customer_code: string; customer_name: string; terms_of_payment_id: string | null } | null
  sales_person_id: string | null
  sales_person: { id: string; code: string; name: string } | null
  branch_id: string | null
  branch: { id: string; code: string; name: string } | null
  order_date: string
  expected_delivery_date: string | null
  total_amount: string | number
  remarks: string | null
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
  order_date: string
  expected_delivery_date: string | null
  remarks: string | null
  items: { item_id: string; qty: number; rate: number }[]
  override_credit_block?: boolean
  override_reason?: string | null
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

export interface SalesOrderFilterValues {
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
}

export interface DeliveryItem {
  id: string
  sales_order_item_id: string
  item_id: string
  item_code: string
  item_name: string
  uom: string
  qty: number
  rate: string | number
  amount: string | number
}

export interface Delivery {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  sales_order_id: string
  customer_id: string
  customer: { id: string; customer_code: string; customer_name: string; terms_of_payment_id: string | null } | null
  warehouse_id: string
  warehouse: { id: string; name: string; code: string } | null
  delivery_date: string
  due_date: string
  terms_of_payment_id: string | null
  terms_of_payment: { id: string; code: string; name: string; days: number } | null
  remarks: string | null
  items: DeliveryItem[]
  is_invoiced: boolean | null
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

export interface DeliveryFormValues {
  sales_order_id: string
  warehouse_id: string
  delivery_date: string
  due_date: string
  terms_of_payment_id: string | null
  remarks: string | null
  items: { sales_order_item_id: string; qty: number }[]
}

export interface DeliveryFilterValues {
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
}

export type InvoiceDisplayStatus = 'draft' | 'submitted' | 'partially_paid' | 'paid' | 'cancelled'

export interface InvoiceItem {
  id: string
  delivery_item_id: string | null
  item_id: string | null
  item_code: string | null
  item_name: string
  uom: string | null
  qty: number
  rate: string | number
  amount: string | number
  credited_qty: number
  credited_amount: string | number
  creditable_qty: number
  creditable_amount: string | number
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
  delivery: { id: string; document_number: string | null } | null
  deliveries: { id: string; document_number: string | null }[]
  sales_order_id: string | null
  sales_orders: { id: string; document_number: string | null }[]
  sales_order: {
    id: string
    document_number: string | null
    sales_person: { id: string; code: string; name: string } | null
    branch: { id: string; name: string; code: string } | null
  } | null
  customer_id: string
  customer: { id: string; customer_code: string; customer_name: string; phone: string | null; address: string | null } | null
  invoice_date: string
  due_date: string
  terms_of_payment_id: string | null
  terms_of_payment: { id: string; code: string; name: string; days: number } | null
  subtotal: string | number
  discount_amount: string | number
  discount_type: DiscountType
  discount_percentage: string | number | null
  tax_id: string | null
  tax: { id: string; code: string; name: string; type: string; rate: string | number } | null
  tax_amount: string | number
  grand_total: string | number
  paid_amount: string | number
  outstanding_amount: string | number
  credited_amount: string | number
  debited_amount: string | number
  creditable_amount: string | number
  remarks: string | null
  items: InvoiceItem[]
  payment_history: InvoicePaymentHistoryLine[]
  credit_note_history: InvoiceCreditNoteHistoryLine[]
  debit_note_history: InvoiceDebitNoteHistoryLine[]
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

export interface InvoiceFormValues {
  // Goods only.
  delivery_ids?: string[]
  // Transportation only — picked directly instead of derived from a Delivery, with
  // manual freestanding line items (no Item/inventory link).
  customer_id?: string
  items?: { description: string; qty: number; rate: number }[]
  invoice_type?: InvoiceType
  invoice_date: string
  due_date: string
  terms_of_payment_id: string | null
  discount_type: DiscountType
  discount_amount: number | null
  discount_percentage: number | null
  tax_id: string | null
  tax_amount: number | null
  remarks: string | null
}

export interface InvoiceFilterValues {
  status: DocumentStatus | null
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

export interface CreditNoteFilterValues {
  status: DocumentStatus | null
  reason: CreditNoteReason | null
  dateFrom: string
  dateTo: string
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

export interface DebitNoteFilterValues {
  status: DocumentStatus | null
  reason: DebitNoteReason | null
  dateFrom: string
  dateTo: string
}
