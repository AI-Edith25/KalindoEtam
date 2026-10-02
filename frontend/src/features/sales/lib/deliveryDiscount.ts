import { lineDiscountAmount, lineNetAmount } from '@/shared/lib/documentTotals'

/**
 * Client-side preview of DeliveryService::buildDeliveryLineAttributes()'s own allocation rule —
 * a percentage discount carries over as-is (it scales naturally against this line's own gross
 * amount), a nominal (Rp) discount is pro-rated by this delivery's share of the SO line's total
 * qty. Preview only; the backend recomputes and persists the authoritative figure on save.
 */
export function allocateSoLineDiscount(
  soItem: { qty: number; rate: string | number; discount_type: string; discount_value: string | number; discount_amount: string | number },
  qty: number,
  rate: string | number = soItem.rate,
): { discount_amount: number; net_amount: number } {
  const grossAmount = qty * Number(rate)
  const discountType = soItem.discount_type || 'amount'
  const discountValue =
    discountType === 'percentage' ? Number(soItem.discount_value || 0) : Math.round((Number(soItem.discount_amount || 0) * (qty / soItem.qty)) * 100) / 100

  const line = { qty, rate, discount_type: discountType, discount_value: discountValue }

  return { discount_amount: lineDiscountAmount(grossAmount, line), net_amount: lineNetAmount(line) }
}
