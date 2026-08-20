interface LineLike {
  qty: string | number
  rate: string | number
}

export function lineAmount(line: LineLike): number {
  return Number(line.qty || 0) * Number(line.rate || 0)
}

export function computeSubtotal(lines: LineLike[]): number {
  return lines.reduce((sum, line) => sum + lineAmount(line), 0)
}

/**
 * Goods Receipt has no tax field on the backend — Tax is fixed at 0 as a
 * placeholder, so Grand Total always equals Subtotal there. Sales Order,
 * Delivery, Invoice, and Purchase Order all carry a real tax_id/tax and
 * compute their own tax preview locally instead (see their Editor pages).
 */
export function computeTax(): number {
  return 0
}

export function computeGrandTotal(lines: LineLike[]): number {
  return computeSubtotal(lines) + computeTax()
}

interface TaxLike {
  type: string
  rate: string | number
  calculation_mode: string
}

/**
 * Client-side preview of TaxService::calculate() — mirrors the backend's
 * Exclusive/Inclusive formula exactly. Preview only; the backend always
 * recomputes and returns the authoritative amount on save.
 */
export function lineTaxAmount(amount: number, tax: TaxLike | null | undefined): number {
  if (!tax || tax.type !== 'vat') return 0

  const rate = Number(tax.rate)

  if (tax.calculation_mode === 'inclusive') {
    const net = amount / (1 + rate / 100)

    return Math.round((amount - net) * 100) / 100
  }

  return Math.round(((amount * rate) / 100) * 100) / 100
}

export function computeLineTaxTotal<T extends LineLike>(lines: T[], resolveTax: (line: T) => TaxLike | null | undefined): number {
  return lines.reduce((sum, line) => sum + lineTaxAmount(lineAmount(line), resolveTax(line)), 0)
}
