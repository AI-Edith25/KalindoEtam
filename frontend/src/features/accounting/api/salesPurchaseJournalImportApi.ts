import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { GeneralLedgerImportBatch } from '../types'

/** The 4 sub-types SalesPurchaseJournalImportService recognizes — see its GROUP_LABELS/DESCRIPTIONS. */
export type SalesPurchaseJournalImportView = 'sales_invoice' | 'sales_credit_note' | 'purchase_invoice' | 'purchase_return'

export type SalesPurchaseJournalDuplicatePolicy = 'skip' | 'create_anyway'

/**
 * Sales/Purchase Journal import — posts raw Journal Entries straight to the General Ledger rather
 * than fabricating Invoice/CreditNote/PurchaseInvoice/PurchaseReturn documents (see
 * SalesPurchaseJournalImportService). `view` tells the backend which section label to expect
 * ("Sales Journal"/"Sales Return Journal"/"Purchase Journal"/"Purchase Return Journal") — a
 * mismatch throws a 409 (see isJournalTypeMismatch) unless confirmJournalType is set, which the
 * caller does on a second call once the user confirms past that warning. `duplicatePolicy` is a
 * plain upfront choice (default "skip"), not a reactive gate — the real ~170k-row Sales file makes
 * scanning the whole file for duplicates before queuing too slow for a synchronous request; see the
 * controller's own docblock. Poll the result with fetchSalesPurchaseJournalImportBatch until status
 * is completed/failed.
 */
export async function importSalesPurchaseJournal(
  file: File,
  view: SalesPurchaseJournalImportView,
  confirmJournalType = false,
  duplicatePolicy: SalesPurchaseJournalDuplicatePolicy = 'skip',
): Promise<GeneralLedgerImportBatch> {
  const formData = new FormData()
  formData.append('file', file)
  formData.append('view', view)
  formData.append('duplicate_policy', duplicatePolicy)
  if (confirmJournalType) formData.append('confirm_journal_type', '1')

  const { data } = await apiClient.post<ApiResponse<GeneralLedgerImportBatch>>('/sales-purchase-journal/import', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function fetchSalesPurchaseJournalImportBatch(batchId: string): Promise<GeneralLedgerImportBatch> {
  const { data } = await apiClient.get<ApiResponse<GeneralLedgerImportBatch>>(`/sales-purchase-journal/import/${batchId}`)
  return data.data
}
