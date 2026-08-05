import type { DiscountType } from '../types'

/** "Discount (10%)" for percentage-mode invoices, plain "Discount" for amount-mode — used by the editor, detail, and print pages. */
export function discountLabel(discountType: DiscountType, discountPercentage: string | number | null): string {
  if (discountType === 'percentage' && discountPercentage != null) {
    return `Discount (${Number(discountPercentage)}%)`
  }
  return 'Discount'
}
