import type { DebitNoteReason } from '../types'

export const DEBIT_NOTE_REASON_LABELS: Record<DebitNoteReason, string> = {
  under_billed_invoice: 'Under-Billed Invoice',
  price_correction: 'Price Correction',
  additional_service_charge: 'Additional Service Charge',
  freight_adjustment: 'Freight Adjustment',
  tax_adjustment: 'Tax Adjustment',
}

export const DEBIT_NOTE_REASON_OPTIONS = Object.entries(DEBIT_NOTE_REASON_LABELS) as [DebitNoteReason, string][]

/**
 * Decides which line-entry mode a reason implies — see docs/DEBIT_NOTE_DESIGN.md
 * §1/§6. 'item' = adjust an existing InvoiceItem (qty/rate correction).
 * 'freestanding' = a new charge with no InvoiceItem (description + amount only).
 * 'none' = header-only, no lines at all (Tax Adjustment).
 */
export function lineModeForReason(reason: DebitNoteReason): 'item' | 'freestanding' | 'none' {
  if (reason === 'under_billed_invoice' || reason === 'price_correction') return 'item'
  if (reason === 'tax_adjustment') return 'none'
  return 'freestanding'
}
