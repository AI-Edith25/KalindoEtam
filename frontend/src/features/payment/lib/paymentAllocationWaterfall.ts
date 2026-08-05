import type { AccountsReceivable } from '../types'

export interface WaterfallLine {
  accounts_receivable_id: string
  amount: number
}

/**
 * Sprint 1 (Invoice Allocation): given the outstanding invoices a user
 * checked and a payment amount, decides how much goes to each — oldest due
 * date first, each capped at its own outstanding balance, stopping once the
 * payment is exhausted. Pure/no side effects: PaymentAllocationService::
 * allocateBatch() (the existing business layer) still owns every real
 * validation and the accounting postings; this only prepares its input so
 * the UI can show the split before the user confirms.
 */
export function computeWaterfallAllocations(
  receivables: AccountsReceivable[],
  selectedIds: Set<string>,
  paymentAmount: number,
): WaterfallLine[] {
  const ordered = [...receivables]
    .filter((ar) => selectedIds.has(ar.id))
    .sort((a, b) => a.due_date.localeCompare(b.due_date))

  let remaining = paymentAmount
  const lines: WaterfallLine[] = []

  for (const ar of ordered) {
    if (remaining <= 0) break

    const outstanding = Number(ar.outstanding_amount)
    const amount = Math.min(outstanding, remaining)

    if (amount > 0) {
      lines.push({ accounts_receivable_id: ar.id, amount })
      remaining -= amount
    }
  }

  return lines
}
