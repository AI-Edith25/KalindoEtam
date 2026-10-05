import type { CompanyPrintHeader } from '@/features/administration/types'
import { terbilangIdr } from '@/shared/lib/numberToWords'
import { qtyDecimalPlaces } from '@/shared/lib/qty'
import type { Invoice } from '../types'
import {
  DEJAVU_FONT_FACES,
  DEJAVU_FONT_STACK,
  FONT_PT,
  LANDSCAPE_LEFT_COLUMN_BOTTOM_MM,
  LANDSCAPE_SIGNATURE_GAP_MM,
  legacyCompanyName,
  LINE_HEIGHT,
  META_COLON_WIDTH_MM,
  META_GAP_MM,
  META_LABEL_WIDTH_LEFT_MM,
  META_LABEL_WIDTH_RIGHT_MM,
  TOTALS_BOX,
  TOTALS_BOX_ROW_HEIGHT_MM,
  NOTES_TO_WORDS_GAP_MM,
} from './invoicePrintConstants'

export { DEJAVU_FONT_STACK }

/**
 * Precise replica of the legacy SkyBiz salesinvoice.php print (DocumentTemplate=23) for the
 * LANDSCAPE layout — Half (A5 landscape, 210x148.5mm) and Dot Matrix Half (same canvas, a shorter
 * tunable sheet height) are the only paper types that use it; A4 and Continuous use the separate
 * PORTRAIT layout (InvoicePortraitLayout.tsx), per the clouderp legacy system's own two-template
 * split. See invoice-print-spec.md at the repo root, which is the single source of truth for every
 * mm value below (extracted from the PDF's own content stream, not estimated). Absolute
 * positioning throughout per that spec's own Section 0 rule 3: mPDF places every text baseline
 * independently, which flow/flex layout cannot reproduce.
 *
 * Multi-page (explicit, not browser auto-break): the header block (company/customer info, judul,
 * rules, item-table column headers) and its own top coordinates are frozen exactly as the spec
 * measured them and repeat byte-identical on every physical page — only the item table's row slice
 * and the footer cluster (terbilang onward) vary per page. The footer's own internal geometry
 * (terbilangTop, totals-box top, computed signature position) is UNCHANGED from the original
 * single-page design; it was already independent of item row count (item area and footer cluster
 * never shared layout math), which is exactly what makes "stop packing items once they'd reach the
 * footer's fixed top" a correct multi-page split rather than a hack. Row capacity per page is
 * computed from the same frozen row/header geometry (ITEM_TABLE_TOP_MM/ITEM_THEAD_HEIGHT_MM/
 * ITEM_ROW_HEIGHT_MM below) — never hardcoded per invoice — so it stays correct if those numbers
 * ever change. ItemCode/Description get `overflow:hidden` + ellipsis (ponytail: this is the whole
 * fix for a too-narrow column — no measurement pass needed since Half's row height is frozen, and a
 * physical dot-matrix/A5 sheet can't show an arbitrarily long line anyway).
 *
 * Three deliberate departures from the frozen baseline table, all imported from
 * invoicePrintConstants.ts (the shared constants file) rather than hand-tuned here:
 *  - Header label/":"/value geometry (META_*) — the frozen table's own label-box widths were too
 *    narrow for the real DejaVu Sans Condensed webfont's rendering of "Payment Term"/"Sales
 *    Person", letting them run into the ":" that followed. Real measurements (see that file's own
 *    comment) drive the widths now; labels stay LEFT-aligned as in the real legacy layout — a
 *    previous attempt right-aligned them instead, which was itself a regression, not a fix.
 *  - Totals box — the legacy reference (Section 8) has NO internal rule between rows and renders
 *    every row bold; a previous change added a separator + selective bold that matched neither the
 *    reference nor this ticket's own requirement. Rebuilt as a real `<table>` (border-collapse) so
 *    height auto-fits whatever rows actually show, with only the table's own outer border.
 *  - Signature block's vertical position is computed (LANDSCAPE_SIGNATURE_GAP_MM), not the frozen
 *    table's own fixed 110.25mm — that fixed value only ever matched the ONE row-count case
 *    (TOTAL/TAX/Grand Total) it was measured from; at other Tax/Discount combinations it either
 *    overlapped the totals box or overlapped the E&O.E note above it, pushing content past the
 *    148.5mm page bottom. The computed version reproduces 110.25mm exactly for that same reference
 *    case (see the constant's own derivation comment) and stays within the page for every other
 *    combination — verified for the worst case (Tax+Discount both on, 4 rows) in the component body.
 *
 * Font Size / Font Style / Tax / Decimal / Discount stay live Print Options here too (real Half
 * invoices routinely have no tax — hardcoding the reference sample's tax-on look was wrong), but
 * every value above is still the spec's exact-replica default: fontFamily unset renders DejaVu,
 * showTax/showDiscount unset render off (no HCTax column, no TAX/DISC row — Grand Total alone),
 * matching how a typical untaxed Half invoice actually prints. The Font Size (pt) control itself
 * has been removed entirely (frozen at the spec's own 1:1 scale) — there is no more scaling
 * transform in this component at all.
 */

/** Absolutely-positioned single-line text — Section 11's `.t` (nowrap, line-height 1.164 so the precomputed `top` values land the baseline correctly). */
function T({
  top,
  left,
  width,
  size,
  bold,
  italic,
  align,
  children,
}: {
  top: number
  left: number
  width?: number
  size: number
  bold?: boolean
  italic?: boolean
  align?: 'left' | 'right' | 'center'
  children: React.ReactNode
}) {
  return (
    <div
      style={{
        position: 'absolute',
        top: `${top}mm`,
        left: `${left}mm`,
        width: width != null ? `${width}mm` : undefined,
        whiteSpace: 'nowrap',
        lineHeight: LINE_HEIGHT,
        fontSize: `${size}pt`,
        fontWeight: bold ? 700 : 400,
        fontStyle: italic ? 'italic' : 'normal',
        textAlign: align,
      }}
    >
      {children}
    </div>
  )
}

/** Section 5 horizontal rules. */
function Line({ top, left, width, height, color }: { top: number; left: number; width: number; height: number; color: string }) {
  return <div style={{ position: 'absolute', top: `${top}mm`, left: `${left}mm`, width: `${width}mm`, height: `${height}mm`, background: color }} />
}

/**
 * One label/":"/value row (BUG 1) — three fixed-width columns, label LEFT-aligned (matching the
 * real legacy layout) inside a box sized to fit the widest real label with room to spare (see
 * invoicePrintConstants.ts), so no label can ever reach the ":" that follows it.
 */
function MetaField({
  top,
  labelLeft,
  labelWidth,
  label,
  value,
  size,
  bold,
  valueBold,
}: {
  top: number
  labelLeft: number
  labelWidth: number
  label: string
  value: React.ReactNode
  size: number
  bold?: boolean
  /** Defaults to `bold` — only the customer "Tel" row needs its value NOT bold while its own
      label/colon stay bold, matching the original design. */
  valueBold?: boolean
}) {
  const colonLeft = labelLeft + labelWidth
  const valueLeft = colonLeft + META_COLON_WIDTH_MM + META_GAP_MM
  return (
    <>
      <T top={top} left={labelLeft} size={size} bold={bold}>
        {label}
      </T>
      <T top={top} left={colonLeft} size={size} bold={bold}>
        :
      </T>
      <T top={top} left={valueLeft} size={size} bold={valueBold ?? bold}>
        {value}
      </T>
    </>
  )
}

function fmt(value: number | string, decimals = 2): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

function ddmmyyyy(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${day}/${month}/${year}`
}

type ColAlign = 'left' | 'right'
type ItemCol = { key: string; label: string; align: ColAlign; width: number; pad: number }

/**
 * Section 7 column geometry. `width` is this column's box width; `pad` is the header's own
 * inward padding from that box's leading edge (left-aligned) or trailing edge (right-aligned) —
 * 0 wherever the header anchor itself defines the box edge. Data cells always add +0.32mm on
 * top of the header pad (Section 7's documented "data selalu bergeser 0.32mm ke dalam").
 * Widths sum to exactly 210mm (the full page width) — see getItemCols for how the HCTax column
 * is dropped when Tax is off.
 */
const ITEM_COLS: ItemCol[] = [
  { key: 'no', label: 'No', align: 'left', width: 19.99, pad: 10.53 },
  { key: 'itemCode', label: 'ItemCode', align: 'left', width: 27.45, pad: 0 },
  { key: 'description', label: 'Description', align: 'left', width: 48.99, pad: 0 },
  { key: 'qty', label: 'Qty', align: 'right', width: 12.53, pad: 0.53 },
  { key: 'uom', label: 'UOM', align: 'left', width: 24.04, pad: 0 },
  { key: 'unitCost', label: 'HCUnitCost', align: 'right', width: 17.07, pad: 0 },
  { key: 'tax', label: 'HCTax', align: 'right', width: 24.6, pad: 0 },
  { key: 'lineAmt', label: 'HCLineAmt', align: 'right', width: 35.33, pad: 10.72 },
]

/**
 * Drops the HCTax column when Tax is off and gives its width to HCLineAmt instead of leaving a
 * blank gap — safe because `pad` is an absolute mm padding, not a percentage, so widening the
 * column moves only its left edge; the right-aligned figure's on-page position is unchanged.
 */
function getItemCols(showTax: boolean): ItemCol[] {
  if (showTax) return ITEM_COLS
  const taxCol = ITEM_COLS.find((c) => c.key === 'tax')!
  return ITEM_COLS.filter((c) => c.key !== 'tax').map((c) => (c.key === 'lineAmt' ? { ...c, width: c.width + taxCol.width } : c))
}

function cellPadStyle(col: ItemCol, isData: boolean): React.CSSProperties {
  const pad = col.pad + (isData ? 0.32 : 0)
  return col.align === 'left' ? { paddingLeft: `${pad}mm` } : { paddingRight: `${pad}mm` }
}

/** Totals box outer geometry — frozen from invoice-print-spec.md Section 8. */
const TOTALS_TABLE_LEFT_MM = 119.27
const TOTALS_TABLE_WIDTH_MM = 79.08
const TOTALS_TABLE_TOP_MM = 89.08
const TOTALS_NOMINAL_COL_MM = TOTALS_TABLE_WIDTH_MM - TOTALS_BOX.labelColMm - TOTALS_BOX.rpColMm

function buildTotalsRows(invoice: Invoice, showTax: boolean, showDiscount: boolean) {
  const rows: { label: string; amount: number | string; isFinal?: boolean }[] = []
  if (showTax || showDiscount) rows.push({ label: 'TOTAL', amount: invoice.subtotal })
  if (showTax) rows.push({ label: 'TAX', amount: invoice.tax_amount })
  if (showDiscount) rows.push({ label: 'DISC', amount: invoice.discount_amount })
  rows.push({ label: 'Grand Total', amount: invoice.grand_total, isFinal: true })
  return rows
}

/** Frozen item-table geometry (see file doc comment) — drives pagination capacity, not just render. */
const ITEM_TABLE_TOP_MM = 47.51
const ITEM_THEAD_HEIGHT_MM = 6.4
const ITEM_ROW_HEIGHT_MM = 5.92
/** Terbilang's own frozen top (before any dot-matrix bottomShiftMm) — the footer cluster's fixed
    start, and therefore the last page's item-area bottom limit. */
const TERBILANG_TOP_MM = 82.44
/** Invoice remarks ("Notes") sit directly above terbilang on the last page. Each line takes this
    height, and the last page's item area shrinks by the same amount so the notes never overlap rows. */
const NOTE_LINE_HEIGHT_MM = 3.6
const NOTE_FONT_PT = 8
/** Conservative characters per printed line at 8pt across the 190mm column — deliberately low so the
    reserved height never falls short of what the browser actually wraps to. */
const NOTE_CHARS_PER_LINE = 110
/** Font size for the HCUnitCost / HCTax / HCLineAmt figures in the item table (see its use in the row cell). */
const MONEY_FONT_PT = 7.5

/** Printed line count for the notes: each paragraph wraps by length, not just by its own newlines. */
function countNoteLines(remarks: string): number {
  return remarks.split('\n').reduce((total, paragraph) => total + Math.max(1, Math.ceil(paragraph.length / NOTE_CHARS_PER_LINE)), 0)
}
/** Non-last pages have no footer to stop for, so the item area can run down to the physical page
    bottom instead — minus a small bottom margin and room for the "CONTINUE TO NEXT PAGE" line. */
const PAGE_BOTTOM_MARGIN_MM = 3
const CONTINUE_ROW_HEIGHT_MM = 6
const CONTINUE_TEXT = 'CONTINUE TO NEXT PAGE ...'

/**
 * Splits `itemCount` rows into page-sized index groups. `lastCapacity` (smaller — the footer
 * needs room) is reserved for the final page; every page before it packs up to `middleCapacity`
 * rows. Unlike InvoicePortraitLayout's own bin-packer (variable, measured row heights, needs a
 * cascading re-check), every row here is the same frozen height, so simple arithmetic is both
 * correct and enough — no need for the heavier measure-then-cascade machinery.
 */
export function paginateHalfInvoiceItems(itemCount: number, middleCapacity: number, lastCapacity: number): number[][] {
  if (itemCount === 0) return [[]]
  const range = (start: number, end: number) => Array.from({ length: end - start }, (_, k) => start + k)
  if (itemCount <= lastCapacity) return [range(0, itemCount)]

  const nonLastPageCount = Math.ceil((itemCount - lastCapacity) / middleCapacity)
  const pages: number[][] = []
  let i = 0
  for (let p = 0; p < nonLastPageCount; p++) {
    const end = Math.min(i + middleCapacity, itemCount - lastCapacity)
    pages.push(range(i, end))
    i = end
  }
  pages.push(range(i, itemCount))
  return pages
}

export interface InvoiceLandscapeLayoutProps {
  invoice: Invoice
  companyName: string
  printHeader: CompanyPrintHeader | undefined
  customerTel: string
  location: string
  signatureLeftLabel: string
  signatureRightLabel: string
  /** Font Style dropdown — unset renders the spec's own DejaVu Sans Condensed (the exact-replica default). */
  fontFamily?: string
  /** Tampilkan Tax — unset/false renders the spec-default "no tax" look (no HCTax column, no TAX row): real Half invoices are usually untaxed. On reproduces the spec sample's own exact geometry. */
  showTax: boolean
  /** Tampilkan Diskon — adds a DISC row (see buildTotalsRows); off by default, matching every other paper type's own default. */
  showDiscount: boolean
  /** Tampilkan Desimal, totals box only (item-table money columns always show 2 decimals regardless, per Section 9) — unset defaults to ON (2 decimals), matching the legacy Half output the spec was extracted from. */
  showDecimalTotals?: boolean
  /**
   * Dot-matrix mode only — omitted (undefined) for every other paper type, which keeps every
   * formula below a no-op (`bottomShiftMm` is 0) and this component's output byte-identical to
   * before. When set, the whole bottom cluster (terbilang, E&O.E, bank notes, totals box — and by
   * extension the signature block, since its position is computed FROM the totals box) shifts up
   * by exactly `148.5 - heightMm`, a uniform translation, not a rescale — content above that
   * cluster (header, item table) is untouched. Also shrinks every non-last page's own item area to
   * match this shorter physical sheet.
   */
  heightMm?: number
  /** Dot-matrix mode only — the Print Options "Offset Left/Top" tuning, applied to every page. */
  offsetLeftMm?: number
  offsetTopMm?: number
}

export function InvoiceLandscapeLayout({
  invoice,
  companyName,
  printHeader,
  customerTel,
  location,
  signatureLeftLabel,
  signatureRightLabel,
  fontFamily,
  showTax,
  showDiscount,
  showDecimalTotals,
  heightMm,
  offsetLeftMm,
  offsetTopMm,
}: InvoiceLandscapeLayoutProps) {
  const effectiveFontFamily = fontFamily ?? DEJAVU_FONT_STACK
  const itemCols = getItemCols(showTax)
  const totalsDecimals = (showDecimalTotals ?? true) ? 2 : 0
  const totalsRows = buildTotalsRows(invoice, showTax, showDiscount)
  const sheetHeightMm = heightMm ?? 148.5
  const bottomShiftMm = 148.5 - sheetHeightMm
  const totalsTableTopMm = TOTALS_TABLE_TOP_MM - bottomShiftMm

  // Predicts the totals table's own rendered height (real <table> below, auto-fit) so the
  // signature block can be positioned with real clearance regardless of row count — see
  // LANDSCAPE_SIGNATURE_GAP_MM's own derivation comment. Verified fit at the worst case (Tax +
  // Discount both on, 4 rows): totalsBoxBottom ≈ 113.5mm, signatureNameTop ≈ 116.2mm, signature
  // caption ends ≈145.4mm — inside the 148.5mm page with margin to spare.
  const totalsBoxBottom = totalsTableTopMm + totalsRows.length * TOTALS_BOX_ROW_HEIGHT_MM
  const signatureNameTop = Math.max(LANDSCAPE_LEFT_COLUMN_BOTTOM_MM - bottomShiftMm, totalsBoxBottom) + LANDSCAPE_SIGNATURE_GAP_MM

  // Pagination — see file doc comment. lastPageItemBottomMm is the footer's own fixed top
  // (terbilang), unaffected by row count; middlePageItemBottomMm is just "physical sheet bottom
  // minus a margin and the continue-row's own height."
  const notesHeightMm = (invoice.remarks ? countNoteLines(invoice.remarks) : 0) * NOTE_LINE_HEIGHT_MM
  // Notes sit above terbilang with the shared gap, so the reserved band is the notes plus that gap.
  const notesReservedMm = invoice.remarks ? notesHeightMm + NOTES_TO_WORDS_GAP_MM : 0
  const lastPageItemBottomMm = TERBILANG_TOP_MM - bottomShiftMm - notesReservedMm
  const middlePageItemBottomMm = sheetHeightMm - PAGE_BOTTOM_MARGIN_MM - CONTINUE_ROW_HEIGHT_MM
  const lastCapacity = Math.max(1, Math.floor((lastPageItemBottomMm - ITEM_TABLE_TOP_MM - ITEM_THEAD_HEIGHT_MM) / ITEM_ROW_HEIGHT_MM))
  const middleCapacity = Math.max(lastCapacity, Math.floor((middlePageItemBottomMm - ITEM_TABLE_TOP_MM - ITEM_THEAD_HEIGHT_MM) / ITEM_ROW_HEIGHT_MM))
  const pages = paginateHalfInvoiceItems(invoice.items.length, middleCapacity, lastCapacity)

  return (
    <>
      {pages.map((rowIndexes, pageIndex) => {
        const isLastPage = pageIndex === pages.length - 1
        const continueRowTop = ITEM_TABLE_TOP_MM + ITEM_THEAD_HEIGHT_MM + rowIndexes.length * ITEM_ROW_HEIGHT_MM + 1

        return (
          <div
            key={pageIndex}
            data-testid="invoice-landscape-canvas"
            style={{
              position: 'relative',
              width: '210mm',
              height: `${sheetHeightMm}mm`,
              overflow: 'hidden', // pagination above guarantees every page's own content fits — this is a safety net, not the mechanism
              marginLeft: offsetLeftMm ? `${offsetLeftMm}mm` : undefined,
              marginTop: offsetTopMm ? `${offsetTopMm}mm` : undefined,
              breakAfter: isLastPage ? 'avoid' : 'page',
              pageBreakAfter: isLastPage ? 'avoid' : 'always',
              fontFamily: effectiveFontFamily,
              color: '#000',
              lineHeight: LINE_HEIGHT,
            }}
          >
            <style>{DEJAVU_FONT_FACES}</style>

            {/* ---------- BLOK KIRI (company + customer) — repeats on every page ---------- */}
            <T top={5.94} left={10} size={FONT_PT.companyName} bold>{legacyCompanyName(companyName)}</T>
            {printHeader?.address && <T top={12.65} left={10} size={FONT_PT.metaLeft}>{printHeader.address}</T>}
            <MetaField top={16.88} labelLeft={10} labelWidth={META_LABEL_WIDTH_LEFT_MM} label="TEL" value={printHeader?.phone ?? ''} size={FONT_PT.metaLeft} />
            <MetaField top={20.85} labelLeft={10} labelWidth={META_LABEL_WIDTH_LEFT_MM} label="EMAIL" value={printHeader?.email ?? ''} size={FONT_PT.metaLeft} />
            <T top={26.46} left={10} size={FONT_PT.customerName} bold>{invoice.customer?.customer_name ?? '—'}</T>
            {invoice.customer?.address && <T top={31.7} left={10} size={FONT_PT.metaLeft}>{invoice.customer.address}</T>}
            <MetaField top={36.99} labelLeft={10} labelWidth={META_LABEL_WIDTH_LEFT_MM} label="Tel" value={customerTel} size={FONT_PT.metaLeft} bold valueBold={false} />

            {/* ---------- BLOK KANAN (info kanan) — Page No added, computed, repeats every page; omitted entirely when there's only 1 page ---------- */}
            {(
              [
                ['NO', invoice.document_number ?? '—', 5.26, true],
                ['Date', ddmmyyyy(invoice.invoice_date), 9.76, false],
                ['Reference 1', invoice.reference_1 ?? '', 14.25, false],
                ['Payment Term', invoice.terms_of_payment?.name ?? '', 18.23, false],
                ['Jatuh Tempo', ddmmyyyy(invoice.due_date), 22.73, false],
                ['Sales Person', invoice.sales_person?.name ?? '', 26.95, false],
                ['Location', location, 31.46, false],
                ...(pages.length > 1 ? [['Page No', `${pageIndex + 1} of ${pages.length}`, 35.96, false] as [string, string, number, boolean]] : []),
              ] as [string, string, number, boolean][]
            ).map(([label, value, top, bold]) => (
              <MetaField key={label} top={top} labelLeft={127.21} labelWidth={META_LABEL_WIDTH_RIGHT_MM} label={label} value={value} size={FONT_PT.metaRight} bold={bold} />
            ))}

            {/* ---------- JUDUL ---------- */}
            <T top={37.75} left={10} width={190} size={FONT_PT.title} bold align="center">INVOICE</T>

            {/* ---------- GARIS ---------- */}
            <Line top={45.22} left={10} width={190} height={0.8} color="#000" />
            <Line top={46.79} left={10.66} width={188.49} height={0.26} color="#383838" />
            <Line top={52.34} left={10.66} width={188.49} height={0.26} color="#383838" />

            {/* ---------- TABEL ITEM (this page's row slice only) ---------- */}
            <table
              style={{
                position: 'absolute',
                left: 0,
                top: '47.51mm',
                width: '210mm',
                borderCollapse: 'collapse',
                tableLayout: 'fixed',
                fontSize: '9.99pt',
              }}
            >
              <colgroup>
                {itemCols.map((col) => (
                  <col key={col.key} style={{ width: `${col.width}mm` }} />
                ))}
              </colgroup>
              <thead>
                <tr>
                  {itemCols.map((col) => (
                    <th
                      key={col.key}
                      style={{
                        height: '6.40mm',
                        verticalAlign: 'top',
                        fontWeight: 400,
                        textAlign: col.align,
                        padding: 0,
                        ...cellPadStyle(col, false),
                      }}
                    >
                      {col.label}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rowIndexes.map((index) => {
                  const item = invoice.items[index]
                  return (
                    <tr key={item.id}>
                      {itemCols.map((col) => {
                        const isTruncatable = col.key === 'itemCode' || col.key === 'description'
                        // The three money columns sit side by side with no gutter between them, so
                        // at table size their figures ran into each other. A slightly smaller size
                        // opens the gap without touching the frozen column widths or row height.
                        const isMoneyCol = col.key === 'unitCost' || col.key === 'tax' || col.key === 'lineAmt'
                        const style: React.CSSProperties = {
                          height: '5.92mm',
                          verticalAlign: 'top',
                          lineHeight: 1.2,
                          textAlign: col.align,
                          padding: 0,
                          ...(isMoneyCol ? { fontSize: `${MONEY_FONT_PT}pt` } : undefined),
                          // Every column stays single-line (not just the two truncatable ones
                          // below) — pagination capacity above is computed from this exact 5.92mm
                          // row height + `overflow:hidden` on the page canvas; a column that wraps
                          // to 2 lines would silently grow past what was budgeted and get clipped.
                          whiteSpace: 'nowrap',
                          ...cellPadStyle(col, true),
                          // ItemCode/Description guard against a too-narrow column overrunning its
                          // neighbor (the exact A4/Continuous bug this ticket also reports) — an
                          // ellipsis is a correct, honest "this line is longer than the physical
                          // sheet can show," not silent data loss like the clip above would be.
                          ...(isTruncatable ? { overflow: 'hidden', textOverflow: 'ellipsis' } : undefined),
                        }
                        let content: React.ReactNode = ''
                        switch (col.key) {
                          case 'no':
                            content = <span style={{ fontSize: '8.991pt' }}>{index + 1}</span>
                            break
                          case 'itemCode':
                            content = item.item_code ?? ''
                            break
                          case 'description':
                            content = item.item_name
                            break
                          case 'qty':
                            content = fmt(item.qty, qtyDecimalPlaces(item.qty_category ?? 'unit'))
                            break
                          case 'uom':
                            content = item.uom ?? ''
                            break
                          case 'unitCost':
                            content = fmt(item.rate, 2)
                            break
                          case 'tax':
                            content = fmt(item.tax_amount, 2)
                            break
                          case 'lineAmt':
                            // Net of this line's own discount — reconciles with the Tax column,
                            // which is already computed against net.
                            content = fmt(item.net_amount, 2)
                            break
                        }
                        return (
                          <td key={col.key} style={style}>
                            {content}
                          </td>
                        )
                      })}
                    </tr>
                  )
                })}
              </tbody>
            </table>

            {!isLastPage && (
              <T top={continueRowTop} left={10} width={190} size={FONT_PT.tableBody} bold italic align="right">
                {CONTINUE_TEXT}
              </T>
            )}

            {isLastPage && (
              <>
                {/* ---------- TERMS KIRI ---------- */}
                {invoice.remarks && (
                  <div
                    style={{
                      position: 'absolute',
                      top: `${TERBILANG_TOP_MM - bottomShiftMm - notesReservedMm}mm`,
                      left: '10mm',
                      width: '190mm',
                      fontSize: `${NOTE_FONT_PT}pt`,
                      lineHeight: `${NOTE_LINE_HEIGHT_MM}mm`,
                      whiteSpace: 'pre-line',
                      // Long unbroken tokens (e.g. comma-separated numbers) must wrap downward, not run sideways.
                      overflowWrap: 'anywhere',
                    }}
                  >
                    {invoice.remarks}
                  </div>
                )}
                <T top={82.44 - bottomShiftMm} left={10} size={FONT_PT.words}>{terbilangIdr(invoice.grand_total)}</T>
                <Line top={88.02 - bottomShiftMm} left={10} width={190} height={0.2} color="#000" />
                <T top={88.58 - bottomShiftMm} left={10} size={FONT_PT.eoeNote} bold italic>E. &amp; O.E</T>
                <T top={92.79 - bottomShiftMm} left={10} size={9}>1. All cheque and payment should be crossed and made payable to</T>
                <T top={97.29 - bottomShiftMm} left={13.7} size={FONT_PT.bankNote} bold>{legacyCompanyName(companyName)}</T>
                <T top={102.05 - bottomShiftMm} left={13.7} size={FONT_PT.bankNote} bold>BCA NO A/C. 0271461312</T>

                {/* ---------- KOTAK TOTAL ----------
                    Real <table>, outer border ONLY (no internal rule — BUG 2 / spec Section 8), every row
                    bold, auto-fit height to however many rows show. */}
                <table
                  style={{
                    position: 'absolute',
                    left: `${TOTALS_TABLE_LEFT_MM}mm`,
                    top: `${totalsTableTopMm}mm`,
                    width: `${TOTALS_TABLE_WIDTH_MM}mm`,
                    borderCollapse: 'collapse',
                    border: `${TOTALS_BOX.borderMm}mm solid #000`,
                    tableLayout: 'fixed',
                  }}
                >
                  <colgroup>
                    <col style={{ width: `${TOTALS_BOX.labelColMm}mm` }} />
                    <col style={{ width: `${TOTALS_BOX.rpColMm}mm` }} />
                    <col style={{ width: `${TOTALS_NOMINAL_COL_MM}mm` }} />
                  </colgroup>
                  <tbody>
                    {totalsRows.map((row) => {
                      const cellStyle: React.CSSProperties = {
                        fontSize: `${FONT_PT.totalsBox}pt`,
                        fontWeight: 700,
                        lineHeight: LINE_HEIGHT,
                        padding: `${TOTALS_BOX.rowPaddingVerticalMm}mm 0`,
                      }
                      return (
                        <tr key={row.label}>
                          <td style={{ ...cellStyle, textAlign: 'left', paddingLeft: `${TOTALS_BOX.rowPaddingHorizontalMm}mm` }}>{row.label}</td>
                          <td style={{ ...cellStyle, textAlign: 'right', paddingRight: `${TOTALS_BOX.rowPaddingHorizontalMm}mm` }}>RP</td>
                          <td style={{ ...cellStyle, textAlign: 'right', paddingRight: `${TOTALS_BOX.rowPaddingHorizontalMm}mm` }}>{fmt(row.amount, totalsDecimals)}</td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>

                {/* ---------- TANDA TANGAN ---------- */}
                {/* signatureNameTop is computed above (BUG 3) from whichever of the totals table or the left
                    E&O.E column ends lower, plus a real gap — guarantees clearance at any row count instead
                    of a fixed top that could push content past the 148.5mm page bottom. */}
                <T top={signatureNameTop} left={10.79} width={65} size={FONT_PT.signatureName} bold align="center">{invoice.customer?.customer_name ?? '—'}</T>
                <T top={signatureNameTop} left={133.82} width={65} size={FONT_PT.signatureName} bold align="center">{companyName}</T>
                <Line top={signatureNameTop + 24.87} left={10.26} width={65} height={0.5} color="#000" />
                <Line top={signatureNameTop + 24.61} left={133.82} width={65} height={0.5} color="#000" />
                <T top={signatureNameTop + 25.93} left={10.26} width={65} size={FONT_PT.signatureCaption} align="center">({signatureLeftLabel})</T>
                <T top={signatureNameTop + 25.93} left={133.82} width={65} size={FONT_PT.signatureCaption} align="center">({signatureRightLabel})</T>
              </>
            )}
          </div>
        )
      })}
    </>
  )
}
