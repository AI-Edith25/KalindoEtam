import type { PurchaseOrder } from '../types'

export type ReceivingStatus = 'waiting' | 'partial' | 'completed'

export interface ReceivingTotals {
  ordered: number
  received: number
  percentage: number
}

export function computeReceivingTotals(order: PurchaseOrder): ReceivingTotals {
  const ordered = order.items.reduce((sum, item) => sum + item.qty, 0)
  const received = order.items.reduce((sum, item) => sum + item.received_qty, 0)
  const percentage = ordered > 0 ? (received / ordered) * 100 : 0

  return { ordered, received, percentage }
}

/**
 * Receiving only makes sense for a submitted order — a draft PO has
 * nothing to receive against yet, and a cancelled one never will.
 * Returns null for both so callers can render "—" instead of a
 * misleading "Waiting" on an order nothing will ever be received for.
 */
export function computeReceivingStatus(order: PurchaseOrder): ReceivingStatus | null {
  if (order.status !== 'submitted') return null

  const { received } = computeReceivingTotals(order)

  if (received === 0) return 'waiting'
  if (order.is_fully_received) return 'completed'
  return 'partial'
}
