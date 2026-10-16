/**
 * Official Receipt print's own layout constants — mirrors paymentVoucherPrintConstants.ts (same
 * page-local-copy convention). The reference ("mpdf (1).pdf") is plain HTML-table-style mPDF
 * output like the Payment Voucher reference, not a vector-precise document, so these are
 * reasonable eyeballed values, not pdfplumber-measured ones.
 */

export const PAGE_WIDTH_MM = 210
export const PAGE_HEIGHT_MM = 297
export const PAGE_PADDING = '8mm 10mm 10mm 10mm'

export const FONT_PT: Record<'small' | 'medium' | 'large', number> = {
  small: 8,
  medium: 9,
  large: 10.5,
}

/** Same spacer-instead-of-filler-rows approach as Payment Voucher's own DETAIL_TABLE_*
    constants — keeps the TOTAL rule landing at roughly the reference's position regardless of
    how many allocation rows the sub-table has. */
export const DETAIL_TABLE_BASE_GAP_MM = 45
export const DETAIL_TABLE_ROW_HEIGHT_MM = 5.1

export { formatDdMmYyyy, formatNum } from './paymentVoucherPrintConstants'
