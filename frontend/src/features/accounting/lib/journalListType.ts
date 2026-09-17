/**
 * Journal List's single "Journal Type" filter — replaces the old 2-level
 * pill-tab + sub-tab navigation (Cash Book/General Journal/Sales Journal/
 * Purchase Journal, each with its own inner view toggle) with one flat
 * dropdown. Each value maps 1:1 to a previous tab+sub-tab combination —
 * see JournalListPage for how it's split back into (journal, view) to drive
 * the exact same panels/queries/columns as before. No "All journals"
 * option: General Journal/Sales Journal/Purchase Journal have incompatible
 * column shapes (no shared union query exists), unlike Cash Book's own
 * "All" (Official Receipt + Payment Voucher, already unioned server-side).
 */
export type JournalListType =
  | 'cashbook'
  | 'cashbook_receipt'
  | 'cashbook_payment'
  | 'general_journal'
  | 'sales_invoice'
  | 'sales_credit_note'
  | 'purchase_invoice'
  | 'purchase_return'

export const DEFAULT_JOURNAL_LIST_TYPE: JournalListType = 'cashbook'

export const JOURNAL_LIST_TYPE_OPTIONS: { value: JournalListType; label: string }[] = [
  { value: 'cashbook', label: 'Cash Book' },
  { value: 'cashbook_receipt', label: 'Cash Book Official Receipt' },
  { value: 'cashbook_payment', label: 'Cash Book Payment Voucher' },
  { value: 'general_journal', label: 'General Journal' },
  { value: 'sales_invoice', label: 'Sales Journal Invoice' },
  { value: 'sales_credit_note', label: 'Sales Journal Credit Note' },
  { value: 'purchase_invoice', label: 'Purchase Journal Invoice' },
  { value: 'purchase_return', label: 'Purchase Journal Return' },
]
