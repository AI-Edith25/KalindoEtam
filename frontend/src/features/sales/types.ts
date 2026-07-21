import type { ApprovalFlow } from '../approval/types'

export type DocumentStatus = 'draft' | 'submitted' | 'cancelled'

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
  customer: { id: string; customer_code: string; customer_name: string } | null
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
  order_date: string
  expected_delivery_date: string | null
  remarks: string | null
  items: { item_id: string; qty: number; rate: number }[]
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
  customer: { id: string; customer_code: string; customer_name: string } | null
  warehouse_id: string
  warehouse: { id: string; name: string; code: string } | null
  delivery_date: string
  due_date: string
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
  delivery_item_id: string
  item_id: string
  item_code: string
  item_name: string
  uom: string
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
  payment_method: string
}

export interface Invoice {
  id: string
  document_number: string | null
  status: DocumentStatus
  display_status: InvoiceDisplayStatus
  revision: number
  delivery_id: string
  delivery: { id: string; document_number: string | null } | null
  sales_order_id: string
  customer_id: string
  customer: { id: string; customer_code: string; customer_name: string; phone: string | null; address: string | null } | null
  invoice_date: string
  due_date: string
  subtotal: string | number
  discount_amount: string | number
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
  delivery_id: string
  invoice_date: string
  due_date: string
  discount_amount: number | null
  tax_id: string | null
  tax_amount: number | null
  remarks: string | null
}

export interface InvoiceFilterValues {
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
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
