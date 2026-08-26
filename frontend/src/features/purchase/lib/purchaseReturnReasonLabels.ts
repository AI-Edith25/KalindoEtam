import type { PurchaseReturnReason } from '../types'

export const PURCHASE_RETURN_REASON_LABELS: Record<PurchaseReturnReason, string> = {
  damaged_goods: 'Damaged Goods',
  wrong_item: 'Wrong Item',
  quantity_discrepancy: 'Quantity Discrepancy',
  price_correction: 'Price Correction',
  late_delivery: 'Late Delivery',
}

export const PURCHASE_RETURN_REASON_OPTIONS = Object.entries(PURCHASE_RETURN_REASON_LABELS) as [PurchaseReturnReason, string][]

/** A price correction has no physical goods coming back — qty stays 0 so PurchaseReturnService's stock leg is naturally skipped. Every other reason defaults to the line's full remaining returnable qty. */
export function reasonAllowsQuantity(reason: PurchaseReturnReason): boolean {
  return reason !== 'price_correction'
}
