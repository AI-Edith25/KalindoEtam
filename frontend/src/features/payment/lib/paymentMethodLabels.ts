import type { PaymentMethod } from '../types'

export const PAYMENT_METHOD_LABELS: Record<PaymentMethod, string> = {
  cash: 'Cash',
  bank_transfer: 'Bank Transfer',
  qris: 'QRIS',
  credit_card: 'Credit Card',
  cheque: 'Giro / Cheque',
}

export const PAYMENT_METHOD_OPTIONS = Object.entries(PAYMENT_METHOD_LABELS) as [PaymentMethod, string][]
