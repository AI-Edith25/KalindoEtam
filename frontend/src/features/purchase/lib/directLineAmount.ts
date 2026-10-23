import { lineAmount } from '@/shared/lib/documentTotals'
import type { ChartOfAccount } from '@/features/master/types'
import type { PurchaseInvoiceLineRow } from './purchaseInvoiceFormSchema'

interface DirectLineLike {
  chart_of_account_id: string
  qty: string | number
  rate: string | number
}

export function isLiabilityLine(item: DirectLineLike, accounts: ChartOfAccount[]): boolean {
  return accounts.find((account) => account.id === item.chart_of_account_id)?.account_type === 'liability'
}

/**
 * A Liability line (e.g. Hutang PPh 23) deducts from what's owed to the supplier — user always
 * types a positive rate, this is the one place the sign flip happens for both the row display
 * and the header totals preview, mirroring PurchaseInvoiceService::buildDirectLines() server-side.
 */
export function directLineAmount(item: DirectLineLike, accounts: ChartOfAccount[]): number {
  const amount = lineAmount(item)

  return isLiabilityLine(item, accounts) ? -amount : amount
}

/** Stock (Goods Receipt-sourced) rows are always qty*rate — the Liability sign flip only ever applies to a manually chosen Chart-of-Accounts line. */
export function purchaseInvoiceLineAmount(row: PurchaseInvoiceLineRow, accounts: ChartOfAccount[]): number {
  if (row.kind === 'stock') return Number(row.qty) * Number(row.rate || 0)
  return directLineAmount(row, accounts)
}
