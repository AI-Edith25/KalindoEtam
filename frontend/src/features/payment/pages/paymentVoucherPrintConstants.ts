/**
 * Payment Voucher print's own layout constants — page-local, not shared/lib, following
 * salesOrderPrintConstants.ts's own convention (each classic print page keeps its own copy so
 * touching one page's numbers never risks another's). Unlike Sales Order/Delivery/Sales Invoice,
 * this page's reference (a TCPDF-generated legacy PDF) is plain HTML-table-style output, not a
 * vector-precise document, so these are reasonable eyeballed values, not pdfplumber-measured ones.
 */

export const PAGE_WIDTH_MM = 210
export const PAGE_HEIGHT_MM = 297
export const MARGIN_MM = '12mm'

/** Body font size per Font Size print option — Small/Medium/Large map to this template's own pt scale (spec: ~8.5/9.5/11pt). Other sizes (title, table header) scale off `body` via em in the component, not listed separately here. */
export const FONT_PT: Record<'small' | 'medium' | 'large', number> = {
  small: 8.5,
  medium: 9.5,
  large: 11,
}

/** Table 2 (Doc Date/Document #/Reference #/Particulars/Org. Amt/Paid Amt) keeps a fixed minimum
    row count so the footer/signature block lands at a consistent vertical position across short
    vouchers — filler blank rows are appended up to this count. ~150px / ~40mm at this row height. */
export const DETAIL_TABLE_MIN_ROWS = 6
export const DETAIL_TABLE_ROW_HEIGHT_MM = 6.5

/** en-US grouping (comma thousands, dot decimal), no currency symbol — matches the reference PDF's "18,079,680.00", not the shared id-ID formatMoney/formatQty in shared/lib/printOptions.ts. */
export function formatNum(value: number | string, decimals: number): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

/** date arrives as a plain YYYY-MM-DD string — split it directly rather than re-parsing through a Date object, which shifts the calendar date in any timezone ahead of UTC. */
export function formatDdMmYyyy(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${day}/${month}/${year}`
}
