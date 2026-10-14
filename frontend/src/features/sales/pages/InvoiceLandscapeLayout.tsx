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
  PT_TO_MM,
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
 * and the footer cluster (terbilang onward) vary per page. The footer always renders on whichever
 * page ends up last, however many rows that is — never on an empty page of its own: its internal
 * geometry (terbilangTop, totals-box top, computed signature position) sits at its usual frozen
 * spot for every ordinary invoice, and only shifts downward, as one uniform block, on the rare page
 * whose own rows already run past that spot (footerExtraShiftMm in the component body) — the exact
 * case a short custom Sheet Height plus a long invoice produces. Row capacity per page is computed
 * from the same frozen row/header geometry (ITEM_TABLE_TOP_MM/ITEM_THEAD_HEIGHT_MM/
 * ITEM_ROW_HEIGHT_MM below) — never hardcoded per invoice — so it stays correct if those numbers
 * ever change. ItemCode and Description both wrap downward instead of truncating (no DOM
 * measurement pass the way Portrait/A4 has — row height is a character-count estimate per column,
 * see DESCRIPTION_CHARS_PER_LINE/ITEMCODE_CHARS_PER_LINE and itemRowHeightMm below).
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
 *
 * Description/UOM/HCTax widths were redistributed from the frozen baseline's own 40.99/24.04/27.6
 * (keeping the 210mm total unchanged, every other column untouched) after a bug report showed
 * Description wrapping to 2 lines on most ordinary invoices, overflowing the dot-matrix sheet.
 * Real production data (2,067 invoice lines, Sep 2026) plus actual DejaVu Sans Condensed glyph
 * widths (measured the same way as every other constant in this file — see file doc comment)
 * drove the new split:
 *  - UOM's real content never exceeds "LEMBAR" (13.05mm + 0.32mm pad ≈ 13.4mm) — the old 24.04mm
 *    was carrying ~10mm of dead space. Cut to 16mm, still ~2.6mm of margin over "LEMBAR".
 *  - HCTax's real max observed is 55,009,907.24 (23.19mm + pad); even an 8-digit safety ceiling
 *    ("99,999,999.99") measures the same. Cut from 27.6mm to 25.6mm, ~1.8mm margin kept.
 *  - ItemCode/HCUnitCost/HCLineAmt were measured too and left untouched: ItemCode's common
 *    "TOKKA_x_13KG"-style codes (90th pct, 14 chars) already need ~27.8mm, matching its current
 *    27.45mm almost exactly — shrinking it would make a common code wrap, not just a rare one.
 *    HCUnitCost/HCLineAmt have under ~2mm of margin against real observed maxima already.
 *  - The reclaimed ~10mm goes entirely to Description (40.99mm -> 51.03mm), which now fits the
 *    real median line (21 chars, "SEMEN CONCH PCC 50 KG" = 41.75mm) and the 95th percentile
 *    (22 chars) on one line, wrapping only for rarer 27+-char lines (the 99th percentile).
 */
const ITEM_COLS: ItemCol[] = [
  { key: 'no', label: 'No', align: 'left', width: 19.99, pad: 10.53 },
  { key: 'itemCode', label: 'ItemCode', align: 'left', width: 27.45, pad: 0 },
  { key: 'description', label: 'Description', align: 'left', width: 51.03, pad: 0 },
  { key: 'qty', label: 'Qty', align: 'right', width: 12.53, pad: 0.53 },
  { key: 'uom', label: 'UOM', align: 'left', width: 16.0, pad: 0 },
  { key: 'unitCost', label: 'HCUnitCost', align: 'right', width: 19.07, pad: 0 },
  { key: 'tax', label: 'HCTax', align: 'right', width: 25.6, pad: 0 },
  { key: 'lineAmt', label: 'HCLineAmt', align: 'right', width: 38.33, pad: 10.72 },
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
/** One extra printed line of description text at 10pt (line-height 1.2). */
const ITEM_TEXT_LINE_HEIGHT_MM = 4.23
/** Characters that fit on one line of the 51.03mm Description column at table size — kept a little
    low on purpose, same margin-of-safety this constant always had (see ITEM_COLS's own comment for
    the width change). Scaled from the same real-font measurement: "SEMEN CONCH PCC 50 KG" (21
    chars) measures 41.75mm, i.e. ~1.988mm/char average, so the ~50.7mm usable width fits ~25
    chars at that rate — rounded down to 23 to keep the original's conservative margin. */
const DESCRIPTION_CHARS_PER_LINE = 23
/** Same derivation as DESCRIPTION_CHARS_PER_LINE, scaled by column width (27.45mm here vs
    Description's 40.99mm) — ItemCode has no measurement pass either, so this is the only thing
    that can grow its row before text wraps into a row sized for one line. */
const ITEMCODE_CHARS_PER_LINE = 12
/** Terbilang's own frozen top (before any dot-matrix bottomShiftMm) — the footer cluster's usual
    start. Only a floor, not a hard limit: when the last page's own items already run past it, the
    whole footer cluster grows downward instead (see footerExtraShiftMm in the component body) —
    the footer always shares a page with the final rows, never gets an empty page of its own. */
const TERBILANG_TOP_MM = 82.44
/** Minimum clearance between the last item row and the footer cluster, only spent when the
    footer's own frozen spot would otherwise land above the last page's actual rows. */
const FOOTER_GAP_MM = 2
/** Invoice remarks ("Notes") sit directly above terbilang on the last page. Each line takes this
    height, and the last page's item area shrinks by the same amount so the notes never overlap rows. */
const NOTE_LINE_HEIGHT_MM = 3.6
const NOTE_FONT_PT = 8
/** Conservative characters per printed line at 8pt across the 190mm column — deliberately low so the
    reserved height never falls short of what the browser actually wraps to. */
const NOTE_CHARS_PER_LINE = 110
/** Printed line count for the notes: each paragraph wraps by length, not just by its own newlines. */
function countNoteLines(remarks: string): number {
  return remarks.split('\n').reduce((total, paragraph) => total + Math.max(1, Math.ceil(paragraph.length / NOTE_CHARS_PER_LINE)), 0)
}
/** Non-last pages have no footer to stop for, so the item area can run down to the physical page
    bottom instead — minus a small bottom margin and room for the "CONTINUE TO NEXT PAGE" line. */
const PAGE_BOTTOM_MARGIN_MM = 3
const CONTINUE_ROW_HEIGHT_MM = 6
const CONTINUE_TEXT = 'CONTINUE TO NEXT PAGE ...'

/** Max item rows on one printed Half page — a longer invoice continues on the next page (legacy SkyBiz output: 5 items, then "CONTINUE TO NEXT PAGE"). */
const ITEMS_PER_PAGE = 5

/**
 * Splits rows into page-sized index groups by their heights. A page holds at most ITEMS_PER_PAGE
 * rows and fits within `middleAvailMm`; every page but the last ends with "CONTINUE TO NEXT PAGE".
 * The footer is never given a page of its own — it always renders on whichever page ends up last
 * here, however many rows that is (the component grows the footer downward when those rows run
 * past its usual spot — see footerExtraShiftMm). A row taller than a whole page is still placed
 * alone, so it is never dropped.
 */
export function paginateHalfInvoiceItems(rowHeightsMm: number[], middleAvailMm: number): number[][] {
  if (rowHeightsMm.length === 0) return [[]]
  const count = rowHeightsMm.length
  const range = (start: number, end: number) => Array.from({ length: end - start }, (_, k) => start + k)

  const pages: number[][] = []
  let start = 0
  while (start < count) {
    let end = start
    let used = 0
    while (end < count && end - start < ITEMS_PER_PAGE && used + rowHeightsMm[end] <= middleAvailMm) {
      used += rowHeightsMm[end]
      end++
    }
    if (end === start) end = start + 1
    pages.push(range(start, end))
    start = end
  }
  return pages
}

/**
 * Overflow safety net for the LAST page only (see paginateHalfInvoiceItems's own cap/budget —
 * that already keeps every OTHER page within bounds). A short dot-matrix Sheet Height plus a
 * handful of wrapped rows can still push the footer cluster (via footerExtraShiftMm) past the
 * physical page bottom on the last page specifically — previously this silently clipped on
 * screen (overflow:hidden) while Chrome's print engine still emitted a near-empty extra page for
 * the un-clipped remainder (see InvoiceLandscapeLayout's own doc comment, "the one exception").
 *
 * Two remedies, tried in order, both scoped to the LAST page only — earlier pages (and their
 * CONTINUE TO NEXT PAGE footer-free rendering) are untouched either way:
 *  1. Shrink the whole last page uniformly via CSS transform:scale (9.99pt -> 9pt -> 8.5pt ->
 *     8pt, the smallest still legible on dot-matrix) — scaling the page's own root element scales
 *     every descendant's absolute mm position by the same ratio, so this is a true proportional
 *     shrink of the entire block (table, totals box, signature), not just a font-size tweak that
 *     would leave the frozen absolute gaps between them unchanged.
 *  2. If even 8pt still doesn't fit, the last page's own rows genuinely don't belong on one
 *     sheet — split them across one more physical page instead, moving the overflowing trailing
 *     rows onto a new page of their own. That new page is a "continuation" (see isContinuation
 *     in the component body): it renders an abbreviated "Lanjutan Invoice ... (Hal N)" line
 *     instead of repeating the full company/customer header block, keeping the already-frozen
 *     47.51mm ITEM_TABLE_TOP_MM unchanged (the blank space above it is the deliberate, lazy
 *     trade-off — this path only runs for the rare invoice that overflows even after Task A's
 *     wider Description column and a full shrink pass).
 */
const CAPTION_LINE_HEIGHT_MM = FONT_PT.signatureCaption * LINE_HEIGHT * PT_TO_MM
/** 9.99pt -> 9pt -> 8.5pt -> 8pt, as ratios of the baseline 9.99pt table font — tried in order,
    first one that fits wins. 8pt is the floor: smaller is not reliably legible on a dot-matrix
    impact printer, especially with a worn ribbon, so below this the page splits instead. */
const SHRINK_RATIOS = [9 / 9.99, 8.5 / 9.99, 8 / 9.99]

/** Everything the last page's footer cluster needs to know it fits — see this function's own
    caller for how pageBottomShiftMm/totalsTableTopMm/signatureNameTop feed the actual render. */
function computePageFooterGeometry(itemsBottomMm: number, bottomShiftMm: number, notesReservedMm: number, totalsRowCount: number) {
  const footerExtraShiftMm = Math.max(0, itemsBottomMm + FOOTER_GAP_MM - (TERBILANG_TOP_MM - bottomShiftMm - notesReservedMm))
  const pageBottomShiftMm = bottomShiftMm - footerExtraShiftMm
  const totalsTableTopMm = TOTALS_TABLE_TOP_MM - pageBottomShiftMm
  const totalsBoxBottom = totalsTableTopMm + totalsRowCount * TOTALS_BOX_ROW_HEIGHT_MM
  const signatureNameTop = Math.max(LANDSCAPE_LEFT_COLUMN_BOTTOM_MM - pageBottomShiftMm, totalsBoxBottom) + LANDSCAPE_SIGNATURE_GAP_MM
  const contentBottomMm = signatureNameTop + 25.93 + CAPTION_LINE_HEIGHT_MM
  return { pageBottomShiftMm, totalsTableTopMm, signatureNameTop, contentBottomMm }
}

export interface RenderPage {
  rowIndexes: number[]
  isLastPage: boolean
  /** True only for a page this safety net inserted (remedy 2) — renders the abbreviated header. */
  isContinuation: boolean
  /** CSS transform:scale ratio for this page's root element — 1 unless remedy 1 applied. */
  scale: number
}

/**
 * Resolves one candidate "last page" into one or more render pages. Recurses when a split still
 * doesn't fit — a severely overflowing invoice (very long descriptions, many rows) can need more
 * than one continuation page, and each one gets the same shrink-then-split treatment as the
 * first; checking only the KEPT portion and assuming the overflow tail is automatically fine was
 * the actual bug a smoke test for this function caught before shipping (an overflow tail can
 * itself still be too tall for one page).
 */
function resolveLastPage(
  rowIndexes: number[],
  rowHeightsMm: number[],
  bottomShiftMm: number,
  notesReservedMm: number,
  totalsRowCount: number,
  sheetHeightMm: number,
  isContinuation: boolean,
): RenderPage[] {
  const bottomMmFor = (idxs: number[]) => {
    const itemsBottomMm = ITEM_TABLE_TOP_MM + ITEM_THEAD_HEIGHT_MM + idxs.reduce((sum, i) => sum + rowHeightsMm[i], 0)
    return computePageFooterGeometry(itemsBottomMm, bottomShiftMm, notesReservedMm, totalsRowCount).contentBottomMm
  }

  const naturalBottomMm = bottomMmFor(rowIndexes)
  if (naturalBottomMm <= sheetHeightMm) {
    return [{ rowIndexes, isLastPage: true, isContinuation, scale: 1 }]
  }

  const fittingRatio = SHRINK_RATIOS.find((ratio) => naturalBottomMm * ratio <= sheetHeightMm)
  if (fittingRatio) {
    return [{ rowIndexes, isLastPage: true, isContinuation, scale: fittingRatio }]
  }

  // Move trailing rows off this page until what remains fits at full scale, then recurse on the
  // overflow tail (it gets its own shrink-then-split pass, not an unchecked assumption it fits).
  let keepCount = rowIndexes.length
  while (keepCount > 0 && bottomMmFor(rowIndexes.slice(0, keepCount)) > sheetHeightMm) {
    keepCount -= 1
  }
  if (keepCount === 0) {
    // Degenerate: even an empty item table + footer alone doesn't fit this Sheet Height — nothing
    // left to split off. Render as one (still maximally shrunk) page rather than loop forever;
    // a Sheet Height this tiny is a misconfiguration outside anything this ticket covers.
    return [{ rowIndexes, isLastPage: true, isContinuation, scale: SHRINK_RATIOS[SHRINK_RATIOS.length - 1] }]
  }
  const kept = rowIndexes.slice(0, keepCount)
  const overflow = rowIndexes.slice(keepCount)
  return [
    { rowIndexes: kept, isLastPage: false, isContinuation, scale: 1 },
    ...resolveLastPage(overflow, rowHeightsMm, bottomShiftMm, notesReservedMm, totalsRowCount, sheetHeightMm, true),
  ]
}

/** Applies the safety net described above to whatever paginateHalfInvoiceItems already produced
    — only ever touches the last entry, by either marking it for a shrink (scale < 1) or splitting
    it into one or more continuation pages. Every other page passes through unchanged. */
export function applyLastPageOverflowSafetyNet(
  basePages: number[][],
  rowHeightsMm: number[],
  bottomShiftMm: number,
  notesReservedMm: number,
  totalsRowCount: number,
  sheetHeightMm: number,
): RenderPage[] {
  const earlierPages: RenderPage[] = basePages.slice(0, -1).map((rowIndexes) => ({ rowIndexes, isLastPage: false, isContinuation: false, scale: 1 }))
  const lastRowIndexes = basePages[basePages.length - 1]
  return [...earlierPages, ...resolveLastPage(lastRowIndexes, rowHeightsMm, bottomShiftMm, notesReservedMm, totalsRowCount, sheetHeightMm, false)]
}

/** Printed line count of a string at the given column's chars-per-line (greedy word wrap — an
    ItemCode with no spaces is one long "word", which the mid-word-break branch below already
    handles the same as Description always has). */
function textLineCount(text: string, cpl: number): number {
  let lines = 1
  let current = 0
  for (const word of text.split(/\s+/).filter(Boolean)) {
    if (current > 0 && current + 1 + word.length <= cpl) {
      current += 1 + word.length
      continue
    }
    if (current > 0) {
      lines += 1
      current = 0
    }
    // A word starts a fresh line; one longer than a line breaks across however many lines it needs.
    const pieces = Math.ceil(word.length / cpl)
    lines += pieces - 1
    current = word.length - (pieces - 1) * cpl
  }
  return lines
}

/** Row height for one invoice item: a single line is the frozen 5.92mm; each extra line — from
    whichever of Description or ItemCode wraps to more lines — adds one text line to both columns
    at once, since they share one `<tr>`. */
function itemRowHeightMm(item: { item_name: string; item_code: string | null }): number {
  const lines = Math.max(
    textLineCount(item.item_name, DESCRIPTION_CHARS_PER_LINE),
    textLineCount(item.item_code ?? '', ITEMCODE_CHARS_PER_LINE),
  )
  return ITEM_ROW_HEIGHT_MM + (lines - 1) * ITEM_TEXT_LINE_HEIGHT_MM
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

  // Pagination — see file doc comment. middlePageItemBottomMm is just "physical sheet bottom minus
  // a margin and the continue-row's own height."; the footer no longer reserves its own budget
  // here (see footerExtraShiftMm below, computed per page once the last page is known).
  const notesHeightMm = (invoice.remarks ? countNoteLines(invoice.remarks) : 0) * NOTE_LINE_HEIGHT_MM
  // Notes sit above terbilang with the shared gap, so the reserved band is the notes plus that gap.
  const notesReservedMm = invoice.remarks ? notesHeightMm + NOTES_TO_WORDS_GAP_MM : 0
  const middlePageItemBottomMm = sheetHeightMm - PAGE_BOTTOM_MARGIN_MM - CONTINUE_ROW_HEIGHT_MM
  const rowHeightsMm = invoice.items.map(itemRowHeightMm)
  const middleAvailMm = middlePageItemBottomMm - ITEM_TABLE_TOP_MM - ITEM_THEAD_HEIGHT_MM
  const basePages = paginateHalfInvoiceItems(rowHeightsMm, middleAvailMm)
  // Safety net (see its own doc comment): only ever adjusts the last entry basePages produced,
  // either by scaling it down or splitting it into [adjusted last page, continuation page].
  const renderPages = applyLastPageOverflowSafetyNet(basePages, rowHeightsMm, bottomShiftMm, notesReservedMm, totalsRows.length, sheetHeightMm)

  return (
    <>
      {renderPages.map(({ rowIndexes, isLastPage, isContinuation, scale }, pageIndex) => {
        const itemsBottomMm = ITEM_TABLE_TOP_MM + ITEM_THEAD_HEIGHT_MM + rowIndexes.reduce((sum, idx) => sum + rowHeightsMm[idx], 0)
        const continueRowTop = itemsBottomMm + 1

        // The footer's own frozen top is TERBILANG_TOP_MM - bottomShiftMm (minus room for notes).
        // When this page's own rows already run past that spot, the whole footer cluster grows
        // downward by the difference instead — the footer always shares the last page, never gets
        // an empty page of its own (see paginateHalfInvoiceItems' own doc comment). Zero for every
        // normal invoice (few rows, plenty of room above the footer's usual spot). Verified fit at
        // the worst case (Tax + Discount both on, 4 rows): totalsBoxBottom ≈ 113.5mm, signatureNameTop
        // ≈ 116.2mm, signature caption ends ≈145.4mm — inside the 148.5mm page with margin to spare
        // (footerExtraShiftMm 0). Beyond that, applyLastPageOverflowSafetyNet's own scale/split
        // already guarantees this page's un-scaled contentBottomMm fits once scaled — see its doc
        // comment for why a uniform transform:scale on the root element below is enough on its own.
        const { pageBottomShiftMm, totalsTableTopMm, signatureNameTop } = isLastPage
          ? computePageFooterGeometry(itemsBottomMm, bottomShiftMm, notesReservedMm, totalsRows.length)
          : { pageBottomShiftMm: bottomShiftMm, totalsTableTopMm: 0, signatureNameTop: 0 }

        return (
          <div
            key={pageIndex}
            data-testid="invoice-landscape-canvas"
            style={{
              position: 'relative',
              width: '210mm',
              height: `${sheetHeightMm}mm`,
              // Pagination above guarantees fit for every ordinary invoice. The one exception is a
              // custom Sheet Height tuned so short that the footer alone barely fits it (see
              // footerExtraShiftMm) — there, a long invoice's last page can still run past this
              // sheet's own bottom; clipping that rather than adding a wholly blank page is the
              // better of two imperfect outcomes.
              overflow: 'hidden',
              marginLeft: offsetLeftMm ? `${offsetLeftMm}mm` : undefined,
              marginTop: offsetTopMm ? `${offsetTopMm}mm` : undefined,
              breakAfter: isLastPage ? 'avoid' : 'page',
              pageBreakAfter: isLastPage ? 'avoid' : 'always',
            }}
          >
            {/*
             * Overflow safety net remedy 1 (see applyLastPageOverflowSafetyNet) lives on THIS inner
             * wrapper, not the outer page div above — `zoom`, not `transform: scale`, and on a
             * separate element on purpose:
             *  - transform is paint-only: it never changes the element's own generated box, so a
             *    page whose un-transformed content is taller than sheetHeightMm still measures as
             *    overflowing for every purpose EXCEPT painting — print pagination fragments it onto
             *    a 2nd (near-empty) page and the signature block (laid out past sheetHeightMm) gets
             *    clipped by the outer `overflow: hidden`, regardless of how small the transform
             *    paints it on screen. Confirmed via real Ctrl+P/Save-as-PDF output, not just the
             *    on-screen preview, which looked fine under the old transform approach and never
             *    caught this.
             *  - `zoom` DOES reflow, which is what we want for the content — but putting it on the
             *    SAME element that also declares the physical `height: sheetHeightMm` above would
             *    leave it ambiguous whether that explicit height is itself zoomed (shrinking the
             *    clip boundary along with the content, reintroducing a smaller version of the same
             *    clip). Zooming only this un-sized inner wrapper keeps the outer box's physical
             *    139.7mm page boundary fixed and unambiguous, while every absolutely-positioned
             *    descendant below (all positioned relative to THIS wrapper) renders at genuinely
             *    smaller physical offsets — safely inside that boundary instead of merely painted
             *    smaller within it.
             */}
            <div
              style={{
                position: 'relative',
                zoom: scale !== 1 ? scale : undefined,
                fontFamily: effectiveFontFamily,
                color: '#000',
                lineHeight: LINE_HEIGHT,
              }}
            >
              <style>{DEJAVU_FONT_FACES}</style>

              {isContinuation ? (
              /* ---------- Continuation page (remedy 2) — abbreviated header instead of the full
                 company/customer block. ITEM_TABLE_TOP_MM stays 47.51mm unchanged (same capacity
                 math as every other page); the blank space above the item table is the deliberate
                 trade-off for this rare fallback path. */
              <T top={10} left={10} width={190} size={FONT_PT.title} bold>
                {`Lanjutan Invoice ${invoice.document_number ?? ''} (Hal ${pageIndex + 1} dari ${renderPages.length})`}
              </T>
            ) : (
              <>
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
                    ...(renderPages.length > 1 ? [['Page No', `${pageIndex + 1} of ${renderPages.length}`, 35.96, false] as [string, string, number, boolean]] : []),
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
              </>
            )}

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
                        const wraps = col.key === 'description' || col.key === 'itemCode'
                        const style: React.CSSProperties = {
                          height: `${rowHeightsMm[index]}mm`,
                          verticalAlign: 'top',
                          lineHeight: 1.2,
                          textAlign: col.align,
                          padding: 0,
                          // Description and ItemCode both wrap downward and grow their shared row —
                          // the row height above is computed from the greater of the two columns'
                          // own line counts (itemRowHeightMm), so pagination stays exact and nothing
                          // is clipped. ItemCode used to ellipsis-truncate instead, which silently
                          // dropped its tail right where it butts up against Description with no
                          // visible gap — same bug and same fix as the Portrait/A4 layout's own.
                          whiteSpace: wraps ? 'normal' : 'nowrap',
                          ...(wraps ? { overflowWrap: 'break-word' as const } : undefined),
                          ...cellPadStyle(col, true),
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
                      top: `${TERBILANG_TOP_MM - pageBottomShiftMm - notesReservedMm}mm`,
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
                <T top={82.44 - pageBottomShiftMm} left={10} size={FONT_PT.words}>{terbilangIdr(invoice.grand_total)}</T>
                <Line top={88.02 - pageBottomShiftMm} left={10} width={190} height={0.2} color="#000" />
                <T top={88.58 - pageBottomShiftMm} left={10} size={FONT_PT.eoeNote} bold italic>E. &amp; O.E</T>
                <T top={92.79 - pageBottomShiftMm} left={10} size={9}>1. All cheque and payment should be crossed and made payable to</T>
                <T top={97.29 - pageBottomShiftMm} left={13.7} size={FONT_PT.bankNote} bold>{legacyCompanyName(companyName)}</T>
                <T top={102.05 - pageBottomShiftMm} left={13.7} size={FONT_PT.bankNote} bold>BCA NO A/C. 0271461312</T>

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
          </div>
        )
      })}
    </>
  )
}
