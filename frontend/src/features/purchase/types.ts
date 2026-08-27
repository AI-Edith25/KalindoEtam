import type { ApprovalFlow } from '../approval/types'

export type DocumentStatus = 'draft' | 'submitted' | 'cancelled'

export interface PurchaseOrderItem {
  id: string
  item_id: string
  item_code: string | null
  item_name: string | null
  qty: string | number
  rate: string | number
  amount: string | number
  tax_id: string | null
  tax: { id: string; code: string; name: string; type: string; rate: string | number; calculation_mode: string } | null
  tax_amount: string | number
  received_qty: string | number
  outstanding_qty: string | number
  /** From the line's Item — lets GoodsReceiptLineItemTable allow "Receive Now" to exceed Remaining. */
  allow_over_receipt?: boolean
  /** From the line's Item — decides whether "Receive Now" must be a whole number or may carry decimals. */
  item_qty_category?: 'unit' | 'weight'
  /** From the line's Item — shown as a suffix next to the Receive Now input. */
  item_uom?: string | null
}

export interface PurchaseOrder {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  supplier_id: string
  supplier: { id: string; supplier_code: string; supplier_name: string } | null
  order_date: string
  expected_delivery_date: string | null
  total_amount: string | number
  tax_id: string | null
  tax: { id: string; code: string; name: string; type: string; rate: string | number; calculation_mode: string } | null
  tax_amount: string | number
  grand_total: string | number
  remarks: string | null
  items: PurchaseOrderItem[]
  is_fully_received: boolean | null
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
  requires_approval: boolean
  latest_approval: ApprovalFlow | null
}

export interface PurchaseOrderLineFormValues {
  item_id: string
  qty: string
  rate: string
  tax_id: string
}

export interface PurchaseOrderFormValues {
  supplier_id: string
  order_date: string
  expected_delivery_date: string | null
  tax_id: string | null
  remarks: string | null
  items: { item_id: string; qty: number; rate: number; tax_id?: string | null }[]
}

export interface PurchaseOrderFilterValues {
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
}

export interface GoodsReceiptItem {
  id: string
  purchase_order_item_id: string | null
  item_id: string
  item_code: string
  item_name: string
  uom: string
  qty: string | number
  over_receipt_qty: string | number
  qty_category: 'unit' | 'weight'
  rate: string | number
  amount: string | number
}

export interface GoodsReceipt {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  /** Null for a standalone/direct receipt with no source Purchase Order. */
  purchase_order_id: string | null
  supplier_id: string
  supplier: { id: string; supplier_code: string; supplier_name: string } | null
  warehouse_id: string
  warehouse: { id: string; name: string; code: string } | null
  receipt_date: string
  due_date: string
  remarks: string | null
  items: GoodsReceiptItem[]
  is_invoiced: boolean | null
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

export interface GoodsReceiptFormValues {
  /** Omitted/null for a standalone/direct receipt — supplier_id is required instead. */
  purchase_order_id?: string | null
  supplier_id?: string
  warehouse_id: string
  receipt_date: string
  due_date: string
  remarks: string | null
  /** From-PO lines carry purchase_order_item_id; direct-mode lines carry item_id + rate instead. */
  items: { purchase_order_item_id?: string; item_id?: string; rate?: number; qty: number }[]
  /** Confirms a Weight-category line's excess over the outstanding PO qty is intentional — see QtyCategoryValidator::assertWeightOverReceiptAllowed. */
  confirm_over_receipt?: boolean
}

export interface GoodsReceiptFilterValues {
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
}

export type PurchaseInvoiceDisplayStatus = 'draft' | 'unpaid' | 'partial' | 'paid' | 'cancelled'

export interface PurchaseInvoiceItem {
  id: string
  goods_receipt_item_id: string
  item_id: string
  item_code: string
  item_name: string
  uom: string
  /** From the line's Item — decides whether qty_returned must be a whole number or may carry decimals. */
  item_qty_category?: 'unit' | 'weight'
  rate: string | number
  qty: string | number
  amount: string | number
  returned_qty: string | number
  returned_amount: string | number
  returnable_qty: string | number
  returnable_amount: string | number
}

export interface PurchaseInvoicePurchaseReturnHistoryLine {
  id: string
  document_number: string | null
  return_date: string | null
  reason: PurchaseReturnReason
  total_amount: string | number
  status: DocumentStatus
  is_reversed: boolean
}

export interface PurchaseInvoice {
  id: string
  document_number: string | null
  status: DocumentStatus
  display_status: PurchaseInvoiceDisplayStatus
  revision: number
  goods_receipt_id: string
  goods_receipt: { id: string; document_number: string | null; warehouse: { id: string; name: string; code: string } | null } | null
  goods_receipts: { id: string; document_number: string | null }[]
  purchase_order_id: string
  purchase_orders: { id: string; document_number: string | null }[]
  supplier_id: string
  supplier: { id: string; supplier_code: string; supplier_name: string } | null
  invoice_date: string
  due_date: string
  subtotal: string | number
  tax_amount: string | number
  grand_total: string | number
  paid_amount: string | number
  outstanding_amount: string | number
  credited_amount: string | number
  returnable_amount: string | number
  reference_number: string | null
  remarks: string | null
  items: PurchaseInvoiceItem[]
  purchase_return_history: PurchaseInvoicePurchaseReturnHistoryLine[]
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

export interface PurchaseInvoiceFormValues {
  goods_receipt_ids?: string[]
  invoice_date: string
  due_date: string
  tax_amount: number | null
  reference_number: string | null
  remarks: string | null
}

export interface PurchaseInvoiceFilterValues {
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
}

/**
 * Sprint (Purchase Invoices & Returns): the only accounting-correction path
 * for a posted Purchase Invoice — PurchaseInvoiceService::cancel()
 * deliberately never touches the ledger/stock. Single document type
 * (no Purchase Debit Note equivalent — see plan Context), reason is a
 * classification only, mirrors CreditNoteReason's shape.
 */
export type PurchaseReturnReason = 'damaged_goods' | 'wrong_item' | 'quantity_discrepancy' | 'price_correction' | 'late_delivery'

export interface PurchaseReturnItem {
  id: string
  purchase_invoice_item_id: string
  item_id: string
  warehouse_id: string
  item_code: string
  item_name: string
  uom: string
  qty_returned: string | number
  qty_category: 'unit' | 'weight'
  rate: string | number
  amount: string | number
}

export interface PurchaseReturn {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  purchase_invoice_id: string
  purchase_invoice: { id: string; document_number: string | null; grand_total: string | number } | null
  supplier_id: string
  supplier: { id: string; supplier_code: string; supplier_name: string } | null
  return_date: string
  reason: PurchaseReturnReason
  subtotal: string | number
  tax_amount: string | number
  total_amount: string | number
  remarks: string | null
  is_reversed: boolean
  reversed_at: string | null
  items: PurchaseReturnItem[]
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

export interface PurchaseReturnFormValues {
  purchase_invoice_id: string
  return_date: string
  reason: PurchaseReturnReason
  tax_amount: number | null
  remarks: string | null
  items: { purchase_invoice_item_id: string; qty_returned: number; amount: number }[]
}

export interface PurchaseReturnFilterValues {
  status: DocumentStatus | null
  reason: PurchaseReturnReason | null
  dateFrom: string
  dateTo: string
}
