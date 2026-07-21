/**
 * Journal Entry never exposes a raw reference_type class name — this
 * resolves the display link from the already-clean reference_label the
 * API returns (see JournalEntryResource::toArray()). Same pure-function
 * shape as features/payment/lib/sourceDocumentLink.ts.
 */
export function resolveJournalReferenceLink(referenceLabel: string, referenceId: string): string | null {
  if (referenceLabel === 'Invoice') return `/sales/invoices/${referenceId}`
  if (referenceLabel === 'Receipt Entry') return `/finance/incoming/${referenceId}`
  if (referenceLabel === 'Credit Note') return `/sales/credit-notes/${referenceId}`
  if (referenceLabel === 'Debit Note') return `/sales/debit-notes/${referenceId}`
  return null
}
