import type { Branch, ChartOfAccount, Customer, Supplier } from '@/features/master/types'

export type DocumentStatus = 'draft' | 'submitted' | 'cancelled'
export type SettlementStatus = 'unpaid' | 'partially_paid' | 'paid'
export type PaymentEntryType = 'supplier' | 'general_expense'
/** Distinct from cash_account_id (the Chart-of-Accounts picker, labeled "Cash/Bank Account") — this is the actual "Payment Method" field (D2, UAT review 2026-08-12). */
export type PaymentEntryMethod = 'cash' | 'bank_transfer' | 'cheque' | 'giro' | 'qris' | 'credit_card'

export interface AccountsPayable {
  id: string
  supplier_id: string
  supplier: Supplier | null
  invoice_id: string | null
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

/** Evolves PaymentEntryItem — a specific amount of a specific Payment applied to a specific supplier bill's payable, on a specific date. Mirrors PaymentAllocation (Official Receipt's AR side) field-for-field. */
export interface PaymentEntryAllocation {
  id: string
  payment_entry_id: string
  accounts_payable_id: string
  accounts_payable: AccountsPayable
  allocated_amount: string | number
  allocation_date: string | null
  is_reversed: boolean
}

export interface PaymentEntry {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  payment_type: PaymentEntryType
  supplier_id: string | null
  supplier: Supplier | null
  expense_account_id: string | null
  expense_account: ChartOfAccount | null
  description: string | null
  payment_date: string
  cash_account_id: string | null
  cash_account: ChartOfAccount | null
  branch_id: string | null
  branch: Branch | null
  reference_number: string | null
  remarks: string | null
  total_amount: string | number
  allocated_amount: string | number
  unallocated_amount: string | number
  items: PaymentEntryAllocation[]
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

export interface PaymentEntryFilterValues {
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
  unallocatedOnly: boolean
}

export interface AccountsReceivable {
  id: string
  customer_id: string
  customer: Customer | null
  invoice_id: string | null
  invoice: {
    id: string
    document_number: string | null
    invoice_date: string
    status: string
    reference_1: string | null
    reference_2: string | null
    /** Delivery document numbers — same source as Sales > Invoices' own "Reference" column. */
    deliveries: string[]
  } | null
  sales_order_id: string
  delivery_id: string
  delivery: { id: string; document_number: string | null } | null
  branch_id: string | null
  // Goods invoices' branch lives on their Sales Order; Transportation invoices' own branch_id
  // is the fallback (they have no Sales Order at all) — already resolved server-side.
  branch_name: string | null
  terms_of_payment_days: number | null
  age_in_days: number | null
  sales_person_name: string | null
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
  cash_account_id: string | null
  cash_account: ChartOfAccount | null
  payment_method: PaymentEntryMethod | null
  giro_number: string | null
  giro_due_date: string | null
  branch_id: string | null
  branch: Branch | null
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
  unallocatedOnly: boolean
}
