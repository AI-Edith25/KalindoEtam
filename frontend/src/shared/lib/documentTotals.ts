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
 * Sales Order, Delivery, and Goods Receipt have no tax field on the
 * backend — Tax is fixed at 0 as a placeholder. Grand Total therefore
 * always equals Subtotal for these documents. Invoice and Purchase Order
 * compute their own tax preview locally instead (see their Editor pages)
 * since they do have a tax_id.
 */
export function computeTax(): number {
  return 0
}

export function computeGrandTotal(lines: LineLike[]): number {
  return computeSubtotal(lines) + computeTax()
}
