import type { SalesOrder } from '../types'

export type DeliveryProgressStatus = 'waiting' | 'partial' | 'completed'

export interface DeliveryTotals {
  ordered: number
  delivered: number
}

export function computeDeliveryTotals(order: SalesOrder): DeliveryTotals {
  const ordered = order.items.reduce((sum, item) => sum + item.qty, 0)
  const delivered = order.items.reduce((sum, item) => sum + item.delivered_qty, 0)
  return { ordered, delivered }
}

/** Mirrors computeReceivingStatus (Purchase Order) — draft/cancelled orders have nothing to deliver against yet, so they render a plain dash rather than a fabricated status. */
export function computeDeliveryStatus(order: SalesOrder): DeliveryProgressStatus | null {
  if (order.status !== 'submitted') return null
  const { delivered } = computeDeliveryTotals(order)
  if (delivered === 0) return 'waiting'
  if (order.is_fully_delivered) return 'completed'
  return 'partial'
}
