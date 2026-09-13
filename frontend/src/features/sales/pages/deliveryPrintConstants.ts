/**
 * Every mm/pt value here was measured directly off the two reference PDFs' own text/vector layers
 * (pdfplumber word boxes + line/rect geometry — see PR description), not eyeballed off a rendered
 * page. A4 = DeliveryOrder_potrait.pdf, Half = DeliveryOrder_lanscape.pdf. Both share the exact
 * same content box: 190mm wide, 10mm from each side — only the paper height/vertical margin and
 * every element inside differ between the two, so there is no shared layout structure to factor
 * out beyond this file's own constants and the two fonts already shared with Invoice
 * (DEJAVU_FONT_STACK/DEJAVU_FONT_FACES in invoicePrintConstants.ts).
 */

export const A4 = {
  pageWidthMm: 210,
  pageHeightMm: 297,
  /** Real @page margin (uniform 10mm) — unlike Invoice's Portrait layout, both Delivery templates
      use a real margin here (not margin:0 + own padding), since the reference PDFs themselves were
      generated that way and every measured coordinate above is already content-box-relative. */
  marginMm: '10mm',
  /** Logo (delivery-order-logo.jpg — a tighter crop than the shared /kalindo-etam-logo.png used by
      SO/Tanda Terima, extracted directly from the reference PDF's own embedded image) — height and
      left offset measured directly; width is left to the image's own aspect ratio. */
  logoHeightMm: 6.8,
  logoLeftMm: 2,
  /** Two-column split for both the customer/meta row and the E&O.E/TOTAL QTY footer row — the
      right column's own left edge lands in the same place for both (132.42mm from content edge),
      confirmed against the PDF's own coordinates for each independently. */
  rightColStartMm: 132.42,
  /** Right meta column's own internal label|colon|value split, relative to the column's own left
      edge (i.e. already relative to rightColStartMm above). Stored as label/colon track widths
      (not the raw 26.81mm/29.45mm offsets) so grid-template-columns never has to subtract two
      float mm values at render time (24.5 - 21.6 in JS is 2.9000000000000004, not 2.9). */
  metaLabelWidthMm: 26.81,
  metaColonWidthMm: 2.64,
} as const

export const HALF = {
  pageWidthMm: 210,
  pageHeightMm: 148.5,
  marginMm: '8mm 10mm',
  /** Left meta block's own label|colon|value split (content-edge relative), stored as track
      widths — see A4.metaLabelWidthMm's own comment on why (not raw 21.6mm/24.5mm offsets). */
  leftLabelWidthMm: 21.6,
  leftColonWidthMm: 2.9,
  /** Right meta block starts here (content-edge relative); colon/value below are relative to
      THIS column's own left edge, matching how A4's rightColStartMm/metaLabelWidthMm pair works. */
  rightColStartMm: 116.9,
  rightLabelWidthMm: 21.9,
  rightColonWidthMm: 2.8,
} as const

/** Item table column widths, percent of CONTENT_WIDTH_MM — measured separately per template since
    A4 has 4 header groups (NO/ITEM NO./DESCRIPTION/QUANTITYUOM, the last spanning 2 data columns)
    while Half has 5 (No/PKode/Nama Barang/Quantity/UOM) at completely different proportions; there
    is no shared "generic" table geometry between the two despite both sharing the same 190mm
    content width. */
export const A4_ITEM_COLS = { no: 4, itemNo: 24.9, description: 51.1, qty: 9, uom: 11 } as const
export const HALF_ITEM_COLS = { no: 5, pkode: 20, namaBarang: 62, qty: 6, uom: 7 } as const

export const COLORS = { text: '#000', rule: '#000' } as const

/** Both reference PDFs show plain en-US grouping ("50", "50.000") with no currency symbol — same reasoning as the SO/Invoice print family's own formatNum, not the shared id-ID formatMoney/formatQty. */
export function formatNum(value: number | string, decimals: number): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

/** OFF -> whole number ("25"), ON -> 3 decimals ("25.000") — formatting only, never changes the underlying qty value. */
export function formatQty(value: number | string, decimalsOn: boolean): string {
  return formatNum(value, decimalsOn ? 3 : 0)
}

/** The stored company name ("PT Kalindo Etam") is plain title case — both reference PDFs print it
    "PT. KALINDO ETAM" (all caps, period after "PT"), same convention InvoicePortraitLayout.tsx's
    own legacyCompanyName() already applies for Invoice. */
export function legacyCompanyName(name: string): string {
  return name.toUpperCase().replace(/^PT\s+/, 'PT. ')
}

/** delivery_date arrives as a plain YYYY-MM-DD string — split it directly rather than re-parsing through a Date object, which shifts the calendar date in any timezone ahead of UTC (same pitfall dateMath.ts's addDays() already documents). */
export function formatDdMmYyyy(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${day}/${month}/${year}`
}
