/**
 * Every mm/pt value here was measured directly off SalesOrder.pdf's own text/vector layers
 * (pdfplumber word boxes + line/rect geometry), not eyeballed off a rendered page. Sales Order has
 * exactly one paper type (A4 portrait) — unlike Invoice/Delivery there is no per-paper-type
 * variance to model, so this file is flatter than invoicePrintConstants.ts/deliveryPrintConstants.ts.
 */

export const PAGE_WIDTH_MM = 210
export const PAGE_HEIGHT_MM = 297
/** Real @page margin (uniform 10mm, all four sides) — content width is therefore 190mm. */
export const MARGIN_MM = '10mm'

/** Logo: SalesOrder.pdf embeds the exact same source image as DeliveryOrder_potrait.pdf (identical
    embedded-image byte range and page position — confirmed by cross-checking both PDFs' own
    `page.images` bounding boxes) — reuse that already-extracted asset rather than re-extracting a
    byte-identical duplicate. Height/left offset measured directly; width follows the image's own
    aspect ratio. */
export const LOGO_URL = '/delivery-order-logo.jpg'
export const LOGO_HEIGHT_MM = 6.8
export const LOGO_LEFT_MM = 2

/** Two-column split for the customer/meta block — right column's own label edge starts here
    (content-edge relative). */
export const RIGHT_COL_START_MM = 126.83

/** FONT_PT keys read top-to-bottom as the document itself flows. metaBody/metaNo are the
    customer-and-meta two-column block's own two distinct sizes (PDF measured: 9.36pt regular rows,
    10.08pt bold for the NO row specifically — confirmably larger, not a rounding artifact). */
export const FONT_PT = {
  companyName: 15.75,
  title: 14.25,
  kopBody: 9,
  metaBody: 9.36,
  metaNo: 10.08,
  tableHeader: 9,
  tableBody: 9,
  terbilang: 8.25,
  eoeTitle: 10.5,
  eoeBody: 8.25,
  totalsBoxLabel: 9,
  totalsBoxValueFinal: 9.75,
  forCompany: 9.75,
  signatureLabel: 9,
} as const

/** Item table columns, percent of CONTENT_WIDTH_MM — boundaries measured from the PDF's own header
    label positions and right-aligned data columns' own text edges (NO starts 0, ITEM NO. starts
    7.8mm, DESCRIPTION starts 40.5mm, QTY right edge at 121.5mm, UOM starts 122.3mm, U.PRICE right
    edge at 161.7mm, AMOUNT right edge at 190mm = flush with the content's own right edge). QTY and
    U.PRICE are right-aligned so only their own column's right boundary is load-bearing — the exact
    left edge is this file's own choice (enough width for realistic values), same reasoning
    PORTRAIT_ITEM_COLS in invoicePrintConstants.ts already documents for its own HCUnitCost/HCTax. */
export const ITEM_COLS: { key: string; label: string; align: 'left' | 'right'; percent: number }[] = [
  { key: 'no', label: 'NO', align: 'left', percent: 4.1 },
  { key: 'itemNo', label: 'ITEM NO.', align: 'left', percent: 17.2 },
  { key: 'description', label: 'DESCRIPTION', align: 'left', percent: 32.7 },
  { key: 'qty', label: 'QTY', align: 'right', percent: 10.0 },
  { key: 'uom', label: 'UOM', align: 'left', percent: 13.1 },
  { key: 'unitPrice', label: 'U.PRICE', align: 'right', percent: 8.0 },
  { key: 'amount', label: 'AMOUNT', align: 'right', percent: 14.9 },
]

/** Totals box (D5) — outer border + 3 internal column dividers (label | RP | amount), measured from
    the PDF's own vector rects: box spans content-relative 114.73–188.95mm (74.22mm wide, i.e.
    60.38%–99.44% of content width), with dividers at 154.13mm and 161.49mm giving column splits of
    53.08% / 9.92% / 37.0% of the BOX's own width (not content width). A blank spacer row (no text,
    no border) sits between "Add Total Tax Amount" and "Total Amount Due", with the one separating
    rule directly above "Total Amount Due" only — confirmed from the PDF's own rule position sitting
    flush against that row's own top, not between the first two rows. */
export const TOTALS_BOX = {
  leftPercent: 60.38,
  widthPercent: 39.06,
  labelColPercent: 53.08,
  rpColPercent: 9.92,
  amountColPercent: 37.0,
  rowPaddingVerticalMm: 1.2,
  rowPaddingHorizontalMm: 2,
  blankRowHeightMm: 6.5,
  borderMm: 0.26, // 0.75pt
} as const

/** Signature lines (D-final, both centered under their own line per explicit sign-off) — left line
    spans content-relative 0.92–39.46mm, right line spans 64.55–90.19mm; NOT a symmetric 2-column
    grid (the right line is narrower and doesn't mirror the left), so both are placed by their own
    absolute mm offset rather than a generic `1fr 1fr` split. Gap from "For <company>" to the line
    itself (~20.4mm, PDF-measured) is the blank space reserved for a wet signature. */
export const SIGNATURE = {
  leftStartMm: 0.92,
  leftWidthMm: 38.54,
  rightStartMm: 64.55,
  rightWidthMm: 25.64,
  gapAboveLineMm: 20.4,
} as const

/** Bank-clause text (D6) transcribed character-for-character from SalesOrder.pdf's own text layer,
    not retyped from memory — identical wording to Delivery Order's own footer (same legacy source
    document), duplicated here rather than imported since the two print pages must never share a
    literal runtime dependency per the "don't touch Invoice/DO" constraint (a pure-data string isn't
    layout code, but keeping each print page's own text self-contained avoids ever needing to touch
    this file when only Delivery's footer changes, or vice versa). */
export const BANK_CLAUSE_1 = 'All cheque and payment should be crossed and made payable to'
export const BANK_CLAUSE_2 = 'All cash payment must be made directly to Account Department.'
export const BANK_CLAUSE_3 =
  'The property of the goods in this bill shall remain with the seller until full payment has been received and the seller shall have a right of entry of seizure to retake possession in the event that full payment is not made on its due date.'

/** SalesOrder.pdf shows en-US grouping (comma thousands, dot decimal) with no currency symbol — same reasoning as Invoice/Delivery's own formatNum, not the shared id-ID formatMoney/formatQty. */
export function formatNum(value: number | string, decimals: number): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

/** order_date arrives as a plain YYYY-MM-DD string — split it directly rather than re-parsing through a Date object, which shifts the calendar date in any timezone ahead of UTC (same pitfall dateMath.ts's addDays() already documents). */
export function formatDdMmYyyy(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${day}/${month}/${year}`
}
