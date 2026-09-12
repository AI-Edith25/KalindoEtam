import type { ReactNode } from 'react'
import type { CompanyPrintHeader } from '@/features/administration/types'
import { terbilangIdr } from '@/shared/lib/numberToWords'
import type { Invoice } from '../types'
import {
  COLORS,
  DEJAVU_FONT_FACES,
  DEJAVU_FONT_STACK,
  FONT_PT,
  LINE_HEIGHT,
  MARGIN_MM,
  META_GAP_MM,
  PORTRAIT,
  PORTRAIT_ITEM_COLS,
  TOTALS_BOX,
} from './invoicePrintConstants'

/**
 * PORTRAIT layout — used by A4 and Continuous (Roll and Half are separate, untouched templates;
 * see InvoiceLandscapeLayout.tsx for Half). Unlike Landscape, this is a brand-new layout with no
 * legacy pixel spec to replicate, and deliberately uses normal document flow (flex column + a real
 * `<table>` with a repeating `<thead>`) rather than absolute positioning:
 *  - The item table sits in a `flex: 1 0 auto` wrapper between the fixed-height header block and
 *    the fixed-height footer block, so on a short invoice it stretches to fill the page and the
 *    footer/signature land flush at the bottom (BUG 3) — but because it's `min-height`, not a hard
 *    `height`, a long invoice simply grows past one page instead of being clipped: the whole
 *    component flows across as many physical pages as it needs.
 *  - `<thead style={{ display: 'table-header-group' }}>` is what makes the column-heading row
 *    repeat on every subsequent printed page for a multi-page invoice, with no custom code beyond
 *    that one CSS declaration — the standard, well-supported technique for this.
 *  - Every row (`<tr>`) and the footer/signature blocks set `breakInside: 'avoid'` so a single
 *    item or the footer/signature group is never visually split across a page boundary.
 *  - The item table and the totals box both use PERCENTAGE column widths (not mm), so the exact
 *    same column definition works correctly at A4's 190mm content width and Continuous's 221.3mm
 *    without ever letting the rightmost column (HCLineAmt) run past the content edge (BUG 4).
 *
 * All spacing/font-size constants come from invoicePrintConstants.ts — nothing here is an inline
 * "approximate" number.
 */

function fmt(value: number | string, decimals = 2): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

function ddmmyyyy(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${day}/${month}/${year}`
}

/** Same convention as Landscape: "PT Kalindo Etam" -> "PT. KALINDO ETAM". */
function legacyCompanyName(name: string): string {
  return name.toUpperCase().replace(/^PT\s+/, 'PT. ')
}

/**
 * One label/":"/value block (BUG 1) — a real `<table>` with `table-layout: auto` (the default), so
 * the label column auto-sizes to whichever label in THIS block is widest, guaranteeing the ":"
 * never touches any label regardless of content, with no pre-measured width needed (unlike
 * Landscape, which must use absolute positioning and therefore does need pre-measured widths).
 */
function MetaTable({ rows, size }: { rows: { label: string; value: ReactNode; bold?: boolean; valueBold?: boolean }[]; size: number }) {
  return (
    <table style={{ borderCollapse: 'collapse' }}>
      <tbody>
        {rows.map((row) => (
          <tr key={row.label}>
            <td
              style={{
                whiteSpace: 'nowrap',
                textAlign: 'left',
                verticalAlign: 'top',
                paddingRight: `${META_GAP_MM}mm`,
                fontSize: `${size}pt`,
                fontWeight: row.bold ? 700 : 400,
              }}
            >
              {row.label}
            </td>
            <td style={{ whiteSpace: 'nowrap', textAlign: 'left', verticalAlign: 'top', fontSize: `${size}pt`, fontWeight: row.bold ? 700 : 400 }}>:</td>
            <td
              style={{
                textAlign: 'left',
                verticalAlign: 'top',
                paddingLeft: `${META_GAP_MM}mm`,
                fontSize: `${size}pt`,
                fontWeight: (row.valueBold ?? row.bold) ? 700 : 400,
              }}
            >
              {row.value}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

/** Drops HCTax when Tax is off, folding its width into HCLineAmt — same convention as Landscape's own getItemCols. */
function getPortraitItemCols(showTax: boolean) {
  if (showTax) return PORTRAIT_ITEM_COLS
  const taxCol = PORTRAIT_ITEM_COLS.find((c) => c.key === 'tax')!
  return PORTRAIT_ITEM_COLS.filter((c) => c.key !== 'tax').map((c) => (c.key === 'lineAmt' ? { ...c, percent: c.percent + taxCol.percent } : c))
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
  /** Physical page height in mm (297 for A4, 279.4 for Continuous) — drives this component's own
      min-height (see file doc comment); width is always 100% of its container, which the caller
      already sizes to the correct physical page width. */
  pageHeightMm: number
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
}: InvoicePortraitLayoutProps) {
  const effectiveFontFamily = fontFamily ?? DEJAVU_FONT_STACK
  const itemCols = getPortraitItemCols(showTax)
  const totalsDecimals = (showDecimalTotals ?? true) ? 2 : 0
  const totalsRows = buildTotalsRows(invoice, showTax, showDiscount)

  return (
    <div
      style={{
        display: 'flex',
        flexDirection: 'column',
        width: '100%',
        minHeight: `${pageHeightMm}mm`,
        boxSizing: 'border-box',
        padding: `${MARGIN_MM}mm`,
        fontFamily: effectiveFontFamily,
        color: COLORS.text,
        lineHeight: LINE_HEIGHT,
      }}
    >
      <style>{DEJAVU_FONT_FACES}</style>

      {/* ---------- 1. Blok perusahaan (kiri) ---------- */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5mm' }}>
        <div style={{ fontSize: `${FONT_PT.companyName}pt`, fontWeight: 700 }}>{legacyCompanyName(companyName)}</div>
        {printHeader?.address && <div style={{ fontSize: `${FONT_PT.metaLeft}pt` }}>{printHeader.address}</div>}
        <MetaTable
          size={FONT_PT.metaLeft}
          rows={[
            { label: 'TEL', value: printHeader?.phone ?? '' },
            { label: 'EMAIL', value: printHeader?.email ?? '' },
          ]}
        />
      </div>

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
            <MetaTable
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
          <MetaTable
            size={FONT_PT.metaRight}
            rows={[
              { label: 'NO', value: invoice.document_number ?? '—', bold: true },
              { label: 'Date', value: ddmmyyyy(invoice.invoice_date) },
              { label: 'Reference 1', value: invoice.reference_1 ?? '' },
              { label: 'Reference 2', value: invoice.reference_2 ?? '' },
              { label: 'Payment Term', value: invoice.terms_of_payment?.name ?? '' },
              { label: 'Jatuh Tempo', value: ddmmyyyy(invoice.due_date) },
              { label: 'Sales Person', value: invoice.sales_person?.name ?? '' },
              { label: 'Page No', value: '1 of 1' },
              { label: 'Location', value: location },
            ]}
          />
        </div>
      </div>

      {/* ---------- 5+6. Tabel item (flex-grow) ---------- */}
      <div style={{ flex: '1 0 auto', marginTop: `${PORTRAIT.gapAboveTableMm}mm` }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed', fontSize: `${FONT_PT.tableBody}pt` }}>
          <colgroup>
            {itemCols.map((col) => (
              <col key={col.key} style={{ width: `${col.percent}%` }} />
            ))}
          </colgroup>
          <thead style={{ display: 'table-header-group' }}>
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
          <tbody>
            {invoice.items.map((item, index) => (
              <tr key={item.id} style={{ breakInside: 'avoid' }}>
                {itemCols.map((col) => (
                  <td
                    key={col.key}
                    style={{
                      textAlign: col.align,
                      verticalAlign: 'top',
                      padding: `${PORTRAIT.tableCellPaddingVerticalMm}mm ${PORTRAIT.tableCellPaddingHorizontalMm}mm`,
                      whiteSpace: col.key === 'description' ? 'normal' : 'nowrap',
                      overflowWrap: col.key === 'description' ? 'break-word' : undefined,
                    }}
                  >
                    {renderCell(col.key, item, index)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

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
          {/* Totals box (BUG 2) — outer border only, no internal rule, every row bold, auto-fit
              height. Percentage columns (BUG 4) keep the nominal column aligned with the item
              table's own HCLineAmt column since both are flush against the same content edge. */}
          <table style={{ width: '100%', borderCollapse: 'collapse', border: `${TOTALS_BOX.borderMm}mm solid #000` }}>
            <colgroup>
              <col style={{ width: `${PORTRAIT.totalsLabelColPercent}%` }} />
              <col style={{ width: `${PORTRAIT.totalsRpColPercent}%` }} />
              <col style={{ width: `${PORTRAIT.totalsNominalColPercent}%` }} />
            </colgroup>
            <tbody>
              {totalsRows.map((row) => {
                const cellStyle: React.CSSProperties = {
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
