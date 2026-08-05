import type { InvoiceType } from '../types'

/** Drives which Naming Series generates document_number — see Invoice::documentType() on the backend. */
export const INVOICE_TYPE_LABELS: Record<InvoiceType, string> = {
  goods: 'Goods',
  transportation: 'Transportation',
}

export const INVOICE_TYPE_OPTIONS = Object.entries(INVOICE_TYPE_LABELS) as [InvoiceType, string][]
