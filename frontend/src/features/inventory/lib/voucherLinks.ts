import type { VoucherType } from '../types'

/**
 * Maps a Stock Ledger entry's voucher back to the document page that
 * created it. `stock_in` has no frontend page yet — returns null rather
 * than linking to a route that doesn't exist (same principle already
 * applied once, when Sales Order's Deliveries card predated the Delivery
 * module).
 */
export function resolveVoucherLink(voucherType: VoucherType, voucherId: string): string | null {
  switch (voucherType) {
    case 'goods_receipt':
      return `/purchase/goods-receipts/${voucherId}`
    case 'delivery':
      return `/sales/deliveries/${voucherId}`
    case 'stock_adjustment':
      return `/inventory/adjustments/${voucherId}`
    case 'stock_in':
      return null
  }
}
