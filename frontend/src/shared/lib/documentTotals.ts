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
