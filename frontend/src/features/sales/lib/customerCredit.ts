import { formatCurrency } from '@/lib/utils'
import type { CustomerCreditStatus } from '../types'

/**
 * Combines the server's customer-select-time check (overdue, or outstanding
 * alone already over limit) with the "would this order's own value push it
 * over" layer, which only exists once line items/an order total are known —
 * see docs on CustomerCreditStatus. One function, reused by every screen
 * that can create/submit a Sales Order instead of three copies of the same
 * comparison.
 */
export function evaluateCreditBlock(status: CustomerCreditStatus, orderAmount: number): { blocked: boolean; message: string } {
  if (status.is_blocked) {
    return { blocked: true, message: status.message }
  }

  const wouldExceed = status.credit_limit != null && status.outstanding_total + orderAmount > status.credit_limit
  if (wouldExceed) {
    return {
      blocked: true,
      message: `This order (${formatCurrency(orderAmount)}) combined with existing outstanding (${formatCurrency(status.outstanding_total)}) would exceed the credit limit (${formatCurrency(status.credit_limit!)}).`,
    }
  }

  return { blocked: false, message: '' }
}
