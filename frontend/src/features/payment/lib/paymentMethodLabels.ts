import type { PaymentEntryMethod } from '../types'

/** D2 (UAT review 2026-08-12) — Incoming Payment's "Payment Method" field (distinct from cash_account_id, labeled "Cash/Bank Account"). */
export const PAYMENT_METHOD_LABELS: Record<PaymentEntryMethod, string> = {
  cash: 'Cash',
  bank_transfer: 'Bank Transfer',
  giro: 'Giro',
  cheque: 'Cek/Cheque',
  qris: 'QRIS',
  credit_card: 'Credit Card',
}

export const PAYMENT_METHOD_OPTIONS: { value: PaymentEntryMethod; label: string }[] = (
  Object.keys(PAYMENT_METHOD_LABELS) as PaymentEntryMethod[]
).map((value) => ({ value, label: PAYMENT_METHOD_LABELS[value] }))
