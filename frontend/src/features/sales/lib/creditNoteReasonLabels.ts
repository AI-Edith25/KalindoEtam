import type { CreditNoteReason } from '../types'

export const CREDIT_NOTE_REASON_LABELS: Record<CreditNoteReason, string> = {
  full_credit: 'Full Credit',
  partial_credit: 'Partial Credit',
  price_adjustment: 'Price Adjustment',
  returned_goods: 'Returned Goods',
  service_refund: 'Service Refund',
  tax_adjustment: 'Tax Adjustment',
}

export const CREDIT_NOTE_REASON_OPTIONS = Object.entries(CREDIT_NOTE_REASON_LABELS) as [CreditNoteReason, string][]

/** Defaults per reason — see docs/CREDIT_NOTE_DESIGN.md §1. Restock defaults on for physical-goods reasons, off otherwise; qty stays whatever the user already entered except when a reason makes qty meaningless. */
export function defaultRestockForReason(reason: CreditNoteReason): boolean {
  return reason === 'full_credit' || reason === 'partial_credit' || reason === 'returned_goods'
}

export function reasonAllowsQuantity(reason: CreditNoteReason): boolean {
  return reason !== 'price_adjustment' && reason !== 'service_refund' && reason !== 'tax_adjustment'
}
