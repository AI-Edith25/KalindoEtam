import { useLayoutEffect, useRef, useState, type CSSProperties, type ReactNode, type Ref } from 'react'
import { PrintMetaTable } from '@/components/shared/PrintMetaTable'
import type { CompanyPrintHeader } from '@/features/administration/types'
import { terbilangIdr } from '@/shared/lib/numberToWords'
import type { Invoice } from '../types'
import {
  COLORS,
  DEJAVU_FONT_FACES,
  DEJAVU_FONT_STACK,
  FONT_PT,
  LINE_HEIGHT,
  legacyCompanyName,
  MARGIN_MM,
  PORTRAIT,
  PORTRAIT_ITEM_COLS,
  TOTALS_BOX,
} from './invoicePrintConstants'

/**
 * PORTRAIT layout — used by A4 and Continuous (Roll and Half are separate, untouched templates;
 * see InvoiceLandscapeLayout.tsx for Half). Unlike Landscape, this is a brand-new layout with no
 * legacy pixel spec to replicate, and renders as real, measured pages rather than one continuous
 * flex column:
 *  - A hidden measurement pass renders every item row (plus the header block, footer block, and a
 *    "CONTINUE TO NEXT PAGE" row) once to read their real rendered heights (`offsetHeight`, same
 *    px→mm conversion InvoicePrintPage's own Roll-height effect already uses). Chrome does not
 *    fragment `display:flex` content across printed pages — a flex column tall enough to overflow
 *    one page gets its children positioned by the flex algorithm and then sliced wherever the
 *    physical page ends, which is what was producing the reported overlap, not anything about the
 *    item rows or `break-inside` themselves.
 *  - Those measured heights feed `paginateInvoiceItems`, a pure bin-packing function that decides
 *    exactly which item rows belong on which physical page, reserving room for the "CONTINUE TO
 *    NEXT PAGE" row on every page but the last and for the real footer block only on the last.
 *  - Each physical page then renders as its own normal-flow div (header block repeated, a table
 *    with a `table-header-group` thead for the column headings, that page's own row slice, and
 *    either the continue-row or the footer), forced to end with `break-after: page`. Because each
 *    one is now guaranteed by construction to fit a single page, it's safe to still use
 *    `flex:1 0 auto` *inside* one page div to push a short last page's footer/signature down to
 *    the bottom — the flex fragmentation problem above only bites when a flex container itself has
 *    to split across a page boundary, which no longer happens here.
 *  - The item table and the totals box both use PERCENTAGE column widths (not mm), so the exact
 *    same column definition works correctly at A4's 190mm content width and Continuous's 221.3mm.
 *
 * All spacing/font-size constants come from invoicePrintConstants.ts — nothing here is an inline
 * "approximate" number.
 */

const CONTINUE_TEXT = 'CONTINUE TO NEXT PAGE ...'
/** Safety margin subtracted from every page's usable height, against sub-pixel/font-hinting
    rounding between the hidden measurement pass and the final print render — same reasoning as
    Roll's own +2mm margin in InvoicePrintPage.tsx, just slightly larger here because overflowing a
    forced `break-after: page` boundary clips content instead of just looking imperfect. */
const PAGE_SAFETY_MARGIN_MM = 3

function pxToMm(px: number): number {
  return (px * 25.4) / 96
}

/**
 * Splits `rowHeightsMm` into per-page row-index groups that fit within `capacityMm` once `fixedMm`
 * (header block + table column-header row, repeated every page) and either `continueMm` (every
 * page but the last) or `footerMm` (the last page only) are reserved.
 *
 * ponytail: a page whose footer-reserving budget still can't fit even after moving every possible
 * row off it (single row taller than the whole page) keeps that row anyway rather than looping
 * forever — upgrade to splitting an oversized row's own content if that ever happens in practice.
 */
export function paginateInvoiceItems(
  rowHeightsMm: number[],
  fixedMm: number,
  continueMm: number,
  footerMm: number,
  capacityMm: number,
): number[][] {
  if (rowHeightsMm.length === 0) return [[]]

  const continueBudgetMm = capacityMm - fixedMm - continueMm - PAGE_SAFETY_MARGIN_MM
  const pages: number[][] = []
  let i = 0
  while (i < rowHeightsMm.length) {
    const page: number[] = []
    let used = 0
    let j = i
    while (j < rowHeightsMm.length && (page.length === 0 || used + rowHeightsMm[j] <= continueBudgetMm)) {
      used += rowHeightsMm[j]
      page.push(j)
      j++
    }
    pages.push(page)
    i = j
  }

  // The last page reserves room for the real footer (usually much taller than the one-line
  // continue message), so re-check it and cascade any rows that no longer fit onto a fresh final
  // page — repeating until stable in case that fresh page also overflows against the footer.
  const footerBudgetMm = capacityMm - fixedMm - footerMm - PAGE_SAFETY_MARGIN_MM
  const heightOf = (idxs: number[]) => idxs.reduce((sum, idx) => sum + rowHeightsMm[idx], 0)
  for (;;) {
    const last = pages[pages.length - 1]
    if (heightOf(last) <= footerBudgetMm) break
    const overflow: number[] = []
    while (last.length > 0 && heightOf(last) > footerBudgetMm) {
      overflow.unshift(last.pop()!)
    }
    if (last.length === 0) {
      // Nothing fits under the footer budget even alone (pathological: footer taller than a full
      // page) — keep every row rather than silently drop any, and stop cascading.
      last.push(...overflow)
      break
    }
    pages.push(overflow)
  }

  return pages
}

function fmt(value: number | string, decimals = 2): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

function ddmmyyyy(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${day}/${month}/${year}`
}

/** Drops HCTax when Tax is off, folding its width into HCLineAmt — same convention as Landscape's own getItemCols. */
/** Tax off drops HCTax entirely — its freed width used to go 100% into HCLineAmt, which produced
    a right-aligned number sitting at the end of an ~34%-wide (65mm) column: a big empty gap right
    after HCUnitCost, not a wider-looking amount. Split instead: a little to HCLineAmt for genuine
    headroom, most of it back to Description (which can always use more before it needs to wrap). */
function getPortraitItemCols(showTax: boolean) {
  if (showTax) return PORTRAIT_ITEM_COLS
  const taxCol = PORTRAIT_ITEM_COLS.find((c) => c.key === 'tax')!
  const toLineAmt = 4
  const toDescription = taxCol.percent - toLineAmt
  return PORTRAIT_ITEM_COLS.filter((c) => c.key !== 'tax').map((c) => {
    if (c.key === 'lineAmt') return { ...c, percent: c.percent + toLineAmt }
    if (c.key === 'description') return { ...c, percent: c.percent + toDescription }
    return c
  })
}

function renderCell(key: string, item: Invoice['items'][number], index: number): ReactNode {
  switch (key) {
    case 'no':
      return index + 1
    case 'itemCode':
      return item.item_code ?? ''
    case 'description':
      return item.item_name
    case 'qty':
      return fmt(item.qty, 0)
    case 'uom':
      return item.uom ?? ''
    case 'unitCost':
      return fmt(item.rate, 2)
    case 'tax':
      return fmt(item.tax_amount, 2)
    case 'lineAmt':
      return fmt(item.amount, 2)
    default:
      return ''
  }
}

function buildTotalsRows(invoice: Invoice, showTax: boolean, showDiscount: boolean) {
  const rows: { label: string; amount: number | string; isFinal?: boolean }[] = []
  if (showTax || showDiscount) rows.push({ label: 'TOTAL', amount: invoice.subtotal })
  if (showTax) rows.push({ label: 'TAX', amount: invoice.tax_amount })
  if (showDiscount) rows.push({ label: 'DISC', amount: invoice.discount_amount })
  rows.push({ label: 'Grand Total', amount: invoice.grand_total, isFinal: true })
  return rows
}

export interface InvoicePortraitLayoutProps {
  invoice: Invoice
  companyName: string
  printHeader: CompanyPrintHeader | undefined
  attn: string
  customerTel: string
  fax: string
  location: string
  signatureLeftLabel: string
  signatureRightLabel: string
  fontFamily?: string
  showTax: boolean
  showDiscount: boolean
  showDecimalTotals?: boolean
  /** Physical page height in mm (297 for A4, 279.4 for Continuous) — drives each physical page's
      own min-height (unless `autoHeight`, see below); width is always 100% of its container, which
      the caller already sizes to the correct physical page width. Still used as the pagination
      budget even when `autoHeight` is set — it decides when content is long enough to need a 2nd
      page, it just no longer forces every page to visually fill that height. */
  pageHeightMm: number
  /** Dot Matrix (Auto) only — the caller can't know the real physical page height (that's the
      whole reason this mode exists, see PrintPaperType's own 'dotmatrix_auto' doc comment), so
      forcing every page to a fixed min-height would either leave a printer-confusing blank gap or,
      worse, overflow a shorter-than-assumed physical page. Each page instead sizes to exactly its
      own content. */
  autoHeight?: boolean
}

export function InvoicePortraitLayout({
  invoice,
  companyName,
  printHeader,
  attn,
  customerTel,
  fax,
  location,
  signatureLeftLabel,
  signatureRightLabel,
  fontFamily,
  showTax,
  showDiscount,
  showDecimalTotals,
  pageHeightMm,
  autoHeight,
}: InvoicePortraitLayoutProps) {
  const effectiveFontFamily = fontFamily ?? DEJAVU_FONT_STACK
  const itemCols = getPortraitItemCols(showTax)
  const totalsDecimals = (showDecimalTotals ?? true) ? 2 : 0
  const totalsRows = buildTotalsRows(invoice, showTax, showDiscount)
  const items = invoice.items

  const headerRef = useRef<HTMLDivElement>(null)
  const footerRef = useRef<HTMLDivElement>(null)
  const theadRef = useRef<HTMLTableSectionElement>(null)
  const continueRowRef = useRef<HTMLTableRowElement>(null)
  const rowRefs = useRef<Map<string, HTMLTableRowElement>>(new Map())

  const [pages, setPages] = useState<number[][] | null>(null)

  // Whatever affects the header/footer/row content or geometry invalidates the current
  // measurement — re-run the hidden measurement pass instead of printing a stale pagination.
  const measureKey = JSON.stringify([
    items.map((i) => [i.id, i.item_name, i.item_code, i.qty, i.uom, i.rate, i.tax_amount, i.amount]),
    showTax,
    showDiscount,
    totalsDecimals,
    pageHeightMm,
    effectiveFontFamily,
    attn,
    customerTel,
    fax,
    location,
    signatureLeftLabel,
    signatureRightLabel,
    invoice.grand_total,
  ])
  const lastMeasureKeyRef = useRef<string | null>(null)
  useLayoutEffect(() => {
    if (lastMeasureKeyRef.current !== null && lastMeasureKeyRef.current !== measureKey) {
      setPages(null)
    }
    lastMeasureKeyRef.current = measureKey
  }, [measureKey])

  useLayoutEffect(() => {
    if (pages !== null) return
    let cancelled = false
    ;(async () => {
      // The DejaVu webfont loads asynchronously (@font-face) — measuring before it's ready would
      // paginate against fallback-font metrics that don't match what actually prints.
      await document.fonts.ready
      if (cancelled) return
      if (!headerRef.current || !footerRef.current || !theadRef.current || !continueRowRef.current) return

      const headerMm = pxToMm(headerRef.current.offsetHeight)
      const footerMm = pxToMm(footerRef.current.offsetHeight)
      const theadMm = pxToMm(theadRef.current.offsetHeight)
      const continueMm = pxToMm(continueRowRef.current.offsetHeight)
      const rowHeightsMm = items.map((item) => {
        const el = rowRefs.current.get(item.id)
        return el ? pxToMm(el.offsetHeight) : 0
      })
      const capacityMm = pageHeightMm - 2 * MARGIN_MM
      const fixedMm = headerMm + theadMm

      setPages(paginateInvoiceItems(rowHeightsMm, fixedMm, continueMm, footerMm, capacityMm))
    })()
    return () => {
      cancelled = true
    }
  }, [pages, items, pageHeightMm])

  function renderHeaderBlock(pageLabel: string, ref?: Ref<HTMLDivElement>) {
    return (
      <div ref={ref} style={{ display: 'flex', flexDirection: 'column', gap: '0.5mm' }}>
        {/* ---------- 1. Blok perusahaan (kiri) ---------- */}
        <div style={{ fontSize: `${FONT_PT.companyName}pt`, fontWeight: 700 }}>{legacyCompanyName(companyName)}</div>
        {printHeader?.address && <div style={{ fontSize: `${FONT_PT.metaLeft}pt` }}>{printHeader.address}</div>}
        <PrintMetaTable
          size={FONT_PT.metaLeft}
          rows={[
            { label: 'TEL', value: printHeader?.phone ?? '' },
            { label: 'EMAIL', value: printHeader?.email ?? '' },
          ]}
        />

        {/* ---------- 2. Judul ---------- */}
        <div style={{ marginTop: `${PORTRAIT.gapAboveTitleMm}mm`, textAlign: 'center', fontSize: `${FONT_PT.title}pt`, fontWeight: 700 }}>INVOICE</div>

        {/* ---------- 3. Garis tebal ---------- */}
        <div style={{ marginTop: `${PORTRAIT.gapBelowTitleRuleMm}mm`, height: `${PORTRAIT.titleRuleThicknessMm}mm`, background: COLORS.rule }} />

        {/* ---------- 4. Blok dua kolom ---------- */}
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: `${PORTRAIT.headerLeftColPercent}% ${PORTRAIT.headerRightColPercent}%`,
            gap: `${PORTRAIT.gapBetweenColsMm}mm`,
            marginTop: `${PORTRAIT.gapAboveTableMm}mm`,
          }}
        >
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5mm' }}>
            <div style={{ fontSize: `${FONT_PT.customerName}pt`, fontWeight: 700 }}>{invoice.customer?.customer_name ?? '—'}</div>
            {invoice.customer?.address && <div style={{ fontSize: `${FONT_PT.metaLeft}pt` }}>{invoice.customer.address}</div>}
            <div style={{ marginTop: '2mm' }}>
              <PrintMetaTable
                size={FONT_PT.metaLeft}
                rows={[
                  { label: 'Attn', value: attn, bold: true, valueBold: false },
                  { label: 'Tel', value: customerTel, bold: true, valueBold: false },
                  { label: 'Fax', value: fax, bold: true, valueBold: false },
                ]}
              />
            </div>
          </div>
          <div>
            <PrintMetaTable
              size={FONT_PT.metaRight}
              rows={[
                { label: 'NO', value: invoice.document_number ?? '—', bold: true },
                { label: 'Date', value: ddmmyyyy(invoice.invoice_date) },
                { label: 'Reference 1', value: invoice.reference_1 ?? '' },
                { label: 'Reference 2', value: invoice.reference_2 ?? '' },
                { label: 'Payment Term', value: invoice.terms_of_payment?.name ?? '' },
                { label: 'Jatuh Tempo', value: ddmmyyyy(invoice.due_date) },
                { label: 'Sales Person', value: invoice.sales_person?.name ?? '' },
                { label: 'Page No', value: pageLabel },
                { label: 'Location', value: location },
              ]}
            />
          </div>
        </div>
      </div>
    )
  }

  function renderThead(ref?: Ref<HTMLTableSectionElement>) {
    return (
      <thead ref={ref} style={{ display: 'table-header-group' }}>
        <tr
          style={{
            borderTop: `${PORTRAIT.tableRuleThicknessMm}mm solid ${COLORS.tableRule}`,
            borderBottom: `${PORTRAIT.tableRuleThicknessMm}mm solid ${COLORS.tableRule}`,
          }}
        >
          {itemCols.map((col) => (
            <th
              key={col.key}
              style={{
                textAlign: col.align,
                fontWeight: 700,
                fontSize: `${FONT_PT.tableHeader}pt`,
                padding: `${PORTRAIT.tableCellPaddingVerticalMm}mm ${PORTRAIT.tableCellPaddingHorizontalMm}mm`,
              }}
            >
              {col.label}
            </th>
          ))}
        </tr>
      </thead>
    )
  }

  function renderRow(item: Invoice['items'][number], index: number, ref?: (el: HTMLTableRowElement | null) => void) {
    return (
      <tr key={item.id} ref={ref} style={{ breakInside: 'avoid' }}>
        {itemCols.map((col) => (
          <td
            key={col.key}
            style={{
              textAlign: col.align,
              verticalAlign: 'top',
              padding: `${PORTRAIT.tableCellPaddingVerticalMm}mm ${PORTRAIT.tableCellPaddingHorizontalMm}mm`,
              whiteSpace: col.key === 'description' ? 'normal' : 'nowrap',
              overflowWrap: col.key === 'description' ? 'break-word' : undefined,
              // ItemCode is the one nowrap column with real-world free-text length variance (not a
              // short controlled code like UOM, or a formatted number) — without this, a code
              // longer than its column overflows visibly into Description (table-layout:fixed
              // doesn't clip on its own). An honest ellipsis beats silently running into the next
              // column.
              ...(col.key === 'itemCode' ? { overflow: 'hidden', textOverflow: 'ellipsis' } : undefined),
            }}
          >
            {renderCell(col.key, item, index)}
          </td>
        ))}
      </tr>
    )
  }

  function renderContinueRow(ref?: Ref<HTMLTableRowElement>) {
    return (
      <tr ref={ref} style={{ breakInside: 'avoid' }}>
        <td
          colSpan={itemCols.length}
          style={{
            textAlign: 'right',
            fontStyle: 'italic',
            fontWeight: 700,
            padding: `${PORTRAIT.tableCellPaddingVerticalMm}mm ${PORTRAIT.tableCellPaddingHorizontalMm}mm`,
          }}
        >
          {CONTINUE_TEXT}
        </td>
      </tr>
    )
  }

  function renderFooterBlock(ref?: Ref<HTMLDivElement>) {
    return (
      <div ref={ref}>
        {/* ---------- 7. Jumlah dalam huruf ---------- */}
        <div style={{ marginTop: `${PORTRAIT.gapAboveWordsMm}mm`, fontSize: `${FONT_PT.words}pt`, textTransform: 'uppercase' }}>
          RP {terbilangIdr(invoice.grand_total)}
        </div>
        <div style={{ marginTop: `${PORTRAIT.gapBelowWordsRuleMm}mm`, height: `${PORTRAIT.wordsRuleThicknessMm}mm`, background: COLORS.rule }} />

        {/* ---------- 8. Footer dua kolom ---------- */}
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: `${PORTRAIT.footerLeftColPercent}% ${PORTRAIT.footerRightColPercent}%`,
            gap: `${PORTRAIT.gapBetweenColsMm}mm`,
            marginTop: `${PORTRAIT.gapBelowWordsRuleMm}mm`,
            breakInside: 'avoid',
          }}
        >
          <div>
            <div style={{ fontWeight: 700, fontStyle: 'italic', fontSize: `${FONT_PT.eoeNote}pt` }}>E. &amp; O.E</div>
            <ol style={{ marginTop: '1mm', paddingLeft: '4mm', fontSize: `${FONT_PT.bankNote}pt` }}>
              <li>
                All cheque and payment should be crossed and made payable to
                <br />
                <span style={{ fontWeight: 700 }}>{legacyCompanyName(companyName)}</span>
                <br />
                <span style={{ fontWeight: 700 }}>BCA NO A/C. 0271461312</span>
              </li>
            </ol>
          </div>
          <div>
            {/* Totals box — outer border only, no internal rule, every row bold, auto-fit height.
                Percentage columns keep the nominal column aligned with the item table's own
                HCLineAmt column since both are flush against the same content edge. */}
            <table style={{ width: '100%', borderCollapse: 'collapse', border: `${TOTALS_BOX.borderMm}mm solid #000` }}>
              <colgroup>
                <col style={{ width: `${PORTRAIT.totalsLabelColPercent}%` }} />
                <col style={{ width: `${PORTRAIT.totalsRpColPercent}%` }} />
                <col style={{ width: `${PORTRAIT.totalsNominalColPercent}%` }} />
              </colgroup>
              <tbody>
                {totalsRows.map((row) => {
                  const cellStyle: CSSProperties = {
                    fontSize: `${FONT_PT.totalsBox}pt`,
                    fontWeight: 700,
                    lineHeight: LINE_HEIGHT,
                    padding: `${TOTALS_BOX.rowPaddingVerticalMm}mm ${TOTALS_BOX.rowPaddingHorizontalMm}mm`,
                  }
                  return (
                    <tr key={row.label}>
                      <td style={{ ...cellStyle, textAlign: 'left' }}>{row.label}</td>
                      <td style={{ ...cellStyle, textAlign: 'right' }}>RP</td>
                      <td style={{ ...cellStyle, textAlign: 'right' }}>{fmt(row.amount, totalsDecimals)}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>

        {/* ---------- 9. Nama penanda tangan ---------- */}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', marginTop: `${PORTRAIT.gapAboveSignatureMm}mm`, breakInside: 'avoid' }}>
          <div style={{ textAlign: 'center', fontSize: `${FONT_PT.signatureName}pt`, fontWeight: 700 }}>{invoice.customer?.customer_name ?? '—'}</div>
          <div style={{ textAlign: 'center', fontSize: `${FONT_PT.signatureName}pt`, fontWeight: 700 }}>{companyName}</div>
        </div>

        {/* ---------- 10. Garis + label tanda tangan ---------- */}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', marginTop: `${PORTRAIT.signatureGapNameToLineMm}mm`, breakInside: 'avoid' }}>
          <div style={{ display: 'flex', justifyContent: 'center' }}>
            <div style={{ width: `${PORTRAIT.signatureLineWidthMm}mm`, height: `${PORTRAIT.signatureLineThicknessMm}mm`, background: '#000' }} />
          </div>
          <div style={{ display: 'flex', justifyContent: 'center' }}>
            <div style={{ width: `${PORTRAIT.signatureLineWidthMm}mm`, height: `${PORTRAIT.signatureLineThicknessMm}mm`, background: '#000' }} />
          </div>
        </div>
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: '1fr 1fr',
            marginTop: `${PORTRAIT.signatureGapLineToCaptionMm}mm`,
            breakInside: 'avoid',
          }}
        >
          <div style={{ textAlign: 'center', fontSize: `${FONT_PT.signatureCaption}pt` }}>({signatureLeftLabel})</div>
          <div style={{ textAlign: 'center', fontSize: `${FONT_PT.signatureCaption}pt` }}>({signatureRightLabel})</div>
        </div>
      </div>
    )
  }

  const pageBaseStyle: CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    width: '100%',
    minHeight: autoHeight ? undefined : `${pageHeightMm}mm`,
    boxSizing: 'border-box',
    padding: `${MARGIN_MM}mm`,
    fontFamily: effectiveFontFamily,
    color: COLORS.text,
    lineHeight: LINE_HEIGHT,
  }

  if (pages === null) {
    // Hidden measurement pass — same markup as the real render (one page's worth, unpaginated),
    // just off-screen while we read real heights back out of it. Never shown on screen or print.
    return (
      <div style={{ ...pageBaseStyle, visibility: 'hidden' }}>
        <style>{DEJAVU_FONT_FACES}</style>
        {renderHeaderBlock('1 of 1', headerRef)}
        <div style={{ marginTop: `${PORTRAIT.gapAboveTableMm}mm` }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed', fontSize: `${FONT_PT.tableBody}pt` }}>
            <colgroup>
              {itemCols.map((col) => (
                <col key={col.key} style={{ width: `${col.percent}%` }} />
              ))}
            </colgroup>
            {renderThead(theadRef)}
            <tbody>
              {items.map((item, index) => renderRow(item, index, (el) => (el ? rowRefs.current.set(item.id, el) : rowRefs.current.delete(item.id))))}
              {renderContinueRow(continueRowRef)}
            </tbody>
          </table>
        </div>
        {renderFooterBlock(footerRef)}
      </div>
    )
  }

  return (
    <>
      {pages.map((rowIndexes, pageIndex) => {
        const isLast = pageIndex === pages.length - 1
        return (
          <div
            key={pageIndex}
            style={{
              ...pageBaseStyle,
              breakAfter: isLast ? undefined : 'page',
              pageBreakAfter: isLast ? undefined : 'always',
            }}
          >
            <style>{DEJAVU_FONT_FACES}</style>
            {renderHeaderBlock(`${pageIndex + 1} of ${pages.length}`)}

            {/* flex:1 0 auto is safe per-page here — each page div is guaranteed by the
                measurement pass to fit within one physical page, so nothing inside it ever needs
                to fragment across a page boundary (see file doc comment). */}
            <div style={{ flex: '1 0 auto', marginTop: `${PORTRAIT.gapAboveTableMm}mm` }}>
              <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed', fontSize: `${FONT_PT.tableBody}pt` }}>
                <colgroup>
                  {itemCols.map((col) => (
                    <col key={col.key} style={{ width: `${col.percent}%` }} />
                  ))}
                </colgroup>
                {renderThead()}
                <tbody>
                  {rowIndexes.map((idx) => renderRow(items[idx], idx))}
                  {!isLast && renderContinueRow()}
                </tbody>
              </table>
            </div>

            {isLast && renderFooterBlock()}
          </div>
        )
      })}
    </>
  )
}
