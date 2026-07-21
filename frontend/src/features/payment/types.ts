import type { Customer, Supplier } from '@/features/master/types'

export type DocumentStatus = 'draft' | 'submitted' | 'cancelled'
export type SettlementStatus = 'unpaid' | 'partially_paid' | 'paid'
export type PaymentMethod = 'cash' | 'bank_transfer' | 'cheque' | 'qris' | 'credit_card'

export interface AccountsPayable {
  id: string
  supplier_id: string
  supplier: Supplier | null
  purchase_order_id: string
  goods_receipt_id: string
  reference_number: string
  amount: string | number
  paid_amount: string | number
  outstanding_amount: string | number
  due_date: string
  status: SettlementStatus
  created_at: string
}

export interface PaymentEntryItem {
  id: string
  accounts_payable_id: string
  accounts_payable: AccountsPayable
  paid_amount: string | number
}

export interface PaymentEntry {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  supplier_id: string
  supplier: Supplier | null
  payment_date: string
  payment_method: PaymentMethod
  reference_number: string | null
  remarks: string | null
  total_amount: string | number
  items: PaymentEntryItem[]
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

export interface PaymentEntryFilterValues {
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
}

export interface AccountsReceivable {
  id: string
  customer_id: string
  customer: Customer | null
  invoice_id: string | null
  invoice: { id: string; document_number: string | null; invoice_date: string; status: string } | null
  sales_order_id: string
  delivery_id: string
  delivery: { id: string; document_number: string | null } | null
  reference_number: string
  amount: string | number
  paid_amount: string | number
  outstanding_amount: string | number
  due_date: string
  status: SettlementStatus
  created_at: string
}

/** One applied allocation, embedded in ReceiptEntry.items — `received_amount` kept as the field name for backward compatibility with existing API consumers, even though the backend column is now `allocated_amount`. */
export interface ReceiptEntryItem {
  id: string
  accounts_receivable_id: string
  accounts_receivable: AccountsReceivable
  received_amount: string | number
  allocation_date: string | null
  is_reversed: boolean
}

export interface ReceiptEntry {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  customer_id: string
  customer: Customer | null
  receipt_date: string
  payment_method: PaymentMethod
  reference_number: string | null
  remarks: string | null
  total_amount: string | number
  allocated_amount: string | number
  unallocated_amount: string | number
  items: ReceiptEntryItem[]
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

/**
 * Sprint 12: response shape of POST /receipt-entries/{id}/allocate and
 * POST /payment-allocations/{id}/reverse — applying an already-received
 * Payment to a specific Invoice's receivable. Receiving money (ReceiptEntry)
 * and applying it (PaymentAllocation) are separate operations.
 */
export interface PaymentAllocation {
  id: string
  receipt_entry_id: string
  accounts_receivable_id: string
  accounts_receivable: AccountsReceivable
  allocated_amount: string | number
  allocation_date: string | null
  is_reversed: boolean
  created_at: string
}

export interface ReceiptEntryFilterValues {
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
}
