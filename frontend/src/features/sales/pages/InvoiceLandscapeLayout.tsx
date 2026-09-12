import type { CompanyPrintHeader } from '@/features/administration/types'
import { terbilangIdr } from '@/shared/lib/numberToWords'
import type { Invoice } from '../types'
import {
  DEJAVU_FONT_FACES,
  DEJAVU_FONT_STACK,
  FONT_PT,
  LANDSCAPE_LEFT_COLUMN_BOTTOM_MM,
  LANDSCAPE_SIGNATURE_GAP_MM,
  LINE_HEIGHT,
  META_COLON_WIDTH_MM,
  META_GAP_MM,
  META_LABEL_WIDTH_LEFT_MM,
  META_LABEL_WIDTH_RIGHT_MM,
  TOTALS_BOX,
  TOTALS_BOX_ROW_HEIGHT_MM,
} from './invoicePrintConstants'

export { DEJAVU_FONT_STACK }

/**
 * Precise replica of the legacy SkyBiz salesinvoice.php print (DocumentTemplate=23) for the
 * LANDSCAPE layout — Half (A5 landscape, 210x148.5mm) is the only paper type that uses it; A4 and
 * Continuous use the separate PORTRAIT layout (InvoicePortraitLayout.tsx), per the clouderp legacy
 * system's own two-template split. See invoice-print-spec.md at the repo root, which is the single
 * source of truth for every mm value below (extracted from the PDF's own content stream, not
 * estimated). Absolute positioning throughout per that spec's own Section 0 rule 3: mPDF places
 * every text baseline independently, which flow/flex layout cannot reproduce. This component is a
 * fixed 210x148.5mm canvas — no scaling, no paper-type parameterization — since only one paper
 * type ever renders it.
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

/** Section 9: "PT Kalindo Etam" -> "PT. KALINDO ETAM" (all caps, period after PT) for this replica header only — every other print format keeps the company name as configured. */
function legacyCompanyName(name: string): string {
  return name.toUpperCase().replace(/^PT\s+/, 'PT. ')
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
}: InvoiceLandscapeLayoutProps) {
  const effectiveFontFamily = fontFamily ?? DEJAVU_FONT_STACK
  const itemCols = getItemCols(showTax)
  const totalsDecimals = (showDecimalTotals ?? true) ? 2 : 0
  const totalsRows = buildTotalsRows(invoice, showTax, showDiscount)

  // Predicts the totals table's own rendered height (real <table> below, auto-fit) so the
  // signature block can be positioned with real clearance regardless of row count — see
  // LANDSCAPE_SIGNATURE_GAP_MM's own derivation comment. Verified fit at the worst case (Tax +
  // Discount both on, 4 rows): totalsBoxBottom ≈ 113.5mm, signatureNameTop ≈ 116.2mm, signature
  // caption ends ≈145.4mm — inside the 148.5mm page with margin to spare.
  const totalsBoxBottom = TOTALS_TABLE_TOP_MM + totalsRows.length * TOTALS_BOX_ROW_HEIGHT_MM
  const signatureNameTop = Math.max(LANDSCAPE_LEFT_COLUMN_BOTTOM_MM, totalsBoxBottom) + LANDSCAPE_SIGNATURE_GAP_MM

  return (
    <div
      style={{
        position: 'relative',
        width: '210mm',
        height: '148.5mm',
        overflow: 'visible', // pagination for long invoices is unmeasured (spec Section 12) — flow past this box rather than silently clip line items
        fontFamily: effectiveFontFamily,
        color: '#000',
        lineHeight: LINE_HEIGHT,
      }}
    >
      <style>{DEJAVU_FONT_FACES}</style>

      {/* ---------- BLOK KIRI (company + customer) ---------- */}
      <T top={5.94} left={10} size={FONT_PT.companyName} bold>{legacyCompanyName(companyName)}</T>
      {printHeader?.address && <T top={12.65} left={10} size={FONT_PT.metaLeft}>{printHeader.address}</T>}
      <MetaField top={16.88} labelLeft={10} labelWidth={META_LABEL_WIDTH_LEFT_MM} label="TEL" value={printHeader?.phone ?? ''} size={FONT_PT.metaLeft} />
      <MetaField top={20.85} labelLeft={10} labelWidth={META_LABEL_WIDTH_LEFT_MM} label="EMAIL" value={printHeader?.email ?? ''} size={FONT_PT.metaLeft} />
      <T top={26.46} left={10} size={FONT_PT.customerName} bold>{invoice.customer?.customer_name ?? '—'}</T>
      {invoice.customer?.address && <T top={31.7} left={10} size={FONT_PT.metaLeft}>{invoice.customer.address}</T>}
      <MetaField top={36.99} labelLeft={10} labelWidth={META_LABEL_WIDTH_LEFT_MM} label="Tel" value={customerTel} size={FONT_PT.metaLeft} bold valueBold={false} />

      {/* ---------- BLOK KANAN (info kanan) ---------- */}
      {(
        [
          ['NO', invoice.document_number ?? '—', 5.26, true],
          ['Date', ddmmyyyy(invoice.invoice_date), 9.76, false],
          ['Reference 1', invoice.reference_1 ?? '', 14.25, false],
          ['Payment Term', invoice.terms_of_payment?.name ?? '', 18.23, false],
          ['Jatuh Tempo', ddmmyyyy(invoice.due_date), 22.73, false],
          ['Sales Person', invoice.sales_person?.name ?? '', 26.95, false],
          ['Location', location, 31.46, false],
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

      {/* ---------- TABEL ITEM ---------- */}
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
          {invoice.items.map((item, index) => (
            <tr key={item.id}>
              {itemCols.map((col) => {
                const style: React.CSSProperties = {
                  height: '5.92mm',
                  verticalAlign: 'top',
                  lineHeight: 1.2,
                  textAlign: col.align,
                  padding: 0,
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
                    content = fmt(item.qty, 0)
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
                    content = fmt(item.amount, 2)
                    break
                }
                return (
                  <td key={col.key} style={style}>
                    {content}
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>

      {/* ---------- TERMS KIRI ---------- */}
      <T top={82.44} left={10} size={FONT_PT.words}>{terbilangIdr(invoice.grand_total)}</T>
      <Line top={88.02} left={10} width={190} height={0.2} color="#000" />
      <T top={88.58} left={10} size={FONT_PT.eoeNote} bold italic>E. &amp; O.E</T>
      <T top={92.79} left={10} size={9}>1. All cheque and payment should be crossed and made payable to</T>
      <T top={97.29} left={13.7} size={FONT_PT.bankNote} bold>{legacyCompanyName(companyName)}</T>
      <T top={102.05} left={13.7} size={FONT_PT.bankNote} bold>BCA NO A/C. 0271461312</T>

      {/* ---------- KOTAK TOTAL ----------
          Real <table>, outer border ONLY (no internal rule — BUG 2 / spec Section 8), every row
          bold, auto-fit height to however many rows show. */}
      <table
        style={{
          position: 'absolute',
          left: `${TOTALS_TABLE_LEFT_MM}mm`,
          top: `${TOTALS_TABLE_TOP_MM}mm`,
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
    </div>
  )
}
