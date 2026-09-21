/**
 * Payment Voucher print's own layout constants — page-local, not shared/lib, following
 * salesOrderPrintConstants.ts's own convention (each classic print page keeps its own copy so
 * touching one page's numbers never risks another's). Unlike Sales Order/Delivery/Sales Invoice,
 * this page's reference (a TCPDF-generated legacy PDF) is plain HTML-table-style output, not a
 * vector-precise document, so these are reasonable eyeballed values, not pdfplumber-measured ones.
 */

export const PAGE_WIDTH_MM = 210
export const PAGE_HEIGHT_MM = 297
/** Vector-measured off the reference PDF's own coordinates: top margin (~5.3mm, where "Bank
    Account" first prints) is noticeably tighter than the left/right margin (~10mm, content spans
    x=10mm to x=199.9mm). Asymmetric on purpose, not a typo. */
export const PAGE_PADDING = '5.3mm 10mm 10mm 10mm'

/** Body font size (9pt at the reference PDF's own baseline) per Font Size print option —
    Small/Medium/Large map to this template's own pt scale. Every other element's own font size is
    expressed as a ratio of this in the component (title 14/9, Keterangan 8/9, etc.) so the Font
    Size toggle still scales the whole document proportionally, matching the vector-measured ratios
    at the Medium/default size. */
export const FONT_PT: Record<'small' | 'medium' | 'large', number> = {
  small: 8,
  medium: 9,
  large: 10.5,
}

/** Table 2's real data row(s) render with NO border at all (vector-confirmed against the reference
    PDF — only the header row is bordered; below the data there's pure whitespace, no filler grid).
    The footer/signature block still needs to land at a consistent position on a short voucher, so
    instead of bordered filler rows, a single unbordered spacer fills the remainder: BASE_GAP_MM is
    the measured gap (header bottom to the TOTAL rule) when there's exactly 1 data row; each
    additional row eats ROW_HEIGHT_MM of it. Clamped to 0 once real rows exceed what fits — the page
    then overflows normally, repeating the header via table-header-group. */
export const DETAIL_TABLE_BASE_GAP_MM = 52
export const DETAIL_TABLE_ROW_HEIGHT_MM = 5.1

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
