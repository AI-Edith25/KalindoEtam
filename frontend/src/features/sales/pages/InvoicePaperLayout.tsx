import { createContext, useContext } from 'react'
import type { CompanyPrintHeader } from '@/features/administration/types'
import { terbilangIdr } from '@/shared/lib/numberToWords'
import type { Invoice } from '../types'

/**
 * Shared "classic" invoice template for the A4, Half, and Continuous paper types — originally a
 * precise replica of the legacy SkyBiz salesinvoice.php print (DocumentTemplate=23) for Half (A5
 * landscape, 210x148.5mm) only — see invoice-print-spec.md at the repo root, which is the single
 * source of truth for every mm value below (extracted from the PDF's own content stream, not
 * estimated). Absolute positioning throughout per that spec's own Section 0 rule 3: mPDF places
 * every text baseline independently, which flow/flex layout cannot reproduce.
 *
 * A4 and Continuous now render this exact same component instead of their own separate markup.
 * Every `top`/`height`/font-size value below is a literal mm/pt constant shared unscaled by all
 * three paper types (same typography, same vertical rhythm) — only `left`/`width` values (via the
 * `T`/`Line` helpers' own ScaleXContext read, see below) scale horizontally by `contentWidthMm /
 * 210` to fill each paper type's own usable content width. A4's own content width (210mm, @page
 * margin:0) equals this component's 210mm reference frame exactly, so A4 renders pixel-identical
 * to Half with scale 1 — only Continuous (wider physical paper) actually scales. Neither paper
 * type stretches vertically: on their taller physical sheets the content simply occupies the top
 * portion, leaving blank paper below, same as a preprinted dot-matrix form on a larger sheet.
 *
 * Every top/left value is copied straight from the spec's Section 4/5/6/7/8/11 tables. The one
 * derived piece is the item-table column geometry (Section 7 only gives left/right text anchors,
 * not column boundaries): each column's box edge is chosen so header padding is 0 wherever the
 * header anchor can serve directly as the box edge, and each data cell then adds exactly the
 * spec's own +0.32mm inward shift — column boundaries themselves are invisible (no vertical
 * table rules exist in this layout) so their exact placement doesn't affect fidelity.
 *
 * Font Size / Font Style / Tax / Decimal / Discount stay live Print Options here too (real Half
 * invoices routinely have no tax — hardcoding the reference sample's tax-on look was wrong), but
 * every value above is still the spec's exact-replica default: fontSizePt unset (or 10) renders
 * at 1:1 scale, fontFamily unset renders DejaVu, showTax/showDiscount unset render off (no HCTax
 * column, no TAX/DISC row — Grand Total alone), matching how a typical untaxed Half invoice
 * actually prints. Turning a toggle on reproduces the spec's own geometry exactly (that's where
 * every mm value below came from); scaling font size scales the whole page uniformly via a CSS
 * transform rather than recomputing 100+ individual mm constants, so the default (scale 1) stays
 * pixel-exact and any other size stays proportionally identical, just bigger/smaller.
 *
 * The Grand Total box's row pitch/height/bold rules (see buildTotalsRows and the totals box JSX)
 * are a DELIBERATE departure from the original two-PDF-sample pixel measurements — a later ticket
 * explicitly asked for uniform per-row height and auto-fit spacing across all three paper types.
 * Do not "restore" the old irregular DISC pitch or unconditional bold; that was superseded on
 * purpose, not an oversight.
 */

/** Horizontal-only scale factor for the `T`/`Line` helpers below — `left`/`width` are multiplied
    by this, `top`/`height`/font sizes never are (see file doc comment). Default 1 so any caller
    that doesn't wrap in a Provider (there shouldn't be one) still renders at the 210mm reference
    scale. */
const ScaleXContext = createContext(1)

export const DEJAVU_FONT_STACK = '"DejaVu Sans Condensed", sans-serif'

const FONT_FACES = `
@font-face{ font-family:'DejaVu Sans Condensed';
  src:url('/fonts/DejaVuSansCondensed.woff2') format('woff2');
  font-weight:400; font-style:normal; font-display:block; }
@font-face{ font-family:'DejaVu Sans Condensed';
  src:url('/fonts/DejaVuSansCondensed-Bold.woff2') format('woff2');
  font-weight:700; font-style:normal; font-display:block; }
@font-face{ font-family:'DejaVu Sans Condensed';
  src:url('/fonts/DejaVuSansCondensed-BoldOblique.woff2') format('woff2');
  font-weight:700; font-style:italic; font-display:block; }
`

/** SI.pdf's own always-2-decimal, comma-thousands convention (Section 9) for item-table money columns — fixed regardless of the Decimal toggle, same "table columns always show their own fixed decimals" convention A4 already documents. Only the totals box responds to the toggle (see showDecimalTotals below). */
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
  const scaleX = useContext(ScaleXContext)
  return (
    <div
      style={{
        position: 'absolute',
        top: `${top}mm`,
        left: `${left * scaleX}mm`,
        width: width != null ? `${width * scaleX}mm` : undefined,
        whiteSpace: 'nowrap',
        lineHeight: 1.164,
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
  const scaleX = useContext(ScaleXContext)
  return (
    <div
      style={{
        position: 'absolute',
        top: `${top}mm`,
        left: `${left * scaleX}mm`,
        width: `${width * scaleX}mm`,
        height: `${height}mm`,
        background: color,
      }}
    />
  )
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

/**
 * Row positions: first row starts at a fixed 90.75mm, every later row (including into DISC) uses
 * one uniform 5.29mm step. A single pitch was chosen deliberately — this box now auto-fits its
 * height/padding to row count and applies a conditional bold rule (see the totals box JSX below),
 * so keeping one legacy PDF sample's irregular 4.76mm DISC step would have been an inconsistent
 * half-measure once every other property of the box was already being redesigned.
 */
const TOTALS_FIRST_ROW_TOP = 90.75
const TOTALS_ROW_PITCH = 5.29
const TOTALS_BOX_TOP_MM = 89.08
/** Gap between the box's own top border and the first row's text top (89.08mm box top vs 90.75mm
    first-row top, from the original measured sample) — reused as the box's top inner padding. */
const TOTALS_BOX_PAD_TOP = TOTALS_FIRST_ROW_TOP - TOTALS_BOX_TOP_MM
/** Bottom inner padding below the last row, tuned so the box reads as evenly padded top/bottom
    rather than hugging the last row's text baseline. */
const TOTALS_BOX_PAD_BOTTOM = 3.2

/** Bottom of the left-column E&O.E/BCA note block (row-count-independent — that block's own
    content never changes) — approximated from its last line's own top (102.05mm) plus a 9pt
    line's height; needs one visual tuning pass against the real print preview once implemented. */
const LEFT_COLUMN_BOTTOM_MM = 106
/** Real clearance between whichever of the totals box or the left column ends lower, and the
    signature name text below it — replaces the old fixed top=110.25mm, which left only 0.03mm at
    4 rows and wasted space at 1 row. */
const SIGNATURE_GAP_MM = 4
/** Vertical offsets from the signature name's own top down to the rest of the signature block,
    preserved exactly from the original fixed absolute values (135.12/134.86/136.18 minus the old
    110.25 name top) — including the real 0.26mm left/right line asymmetry, not a rounding error. */
const SIGNATURE_LINE_LEFT_OFFSET_MM = 24.87
const SIGNATURE_LINE_RIGHT_OFFSET_MM = 24.61
const SIGNATURE_LABEL_OFFSET_MM = 25.93

function buildTotalsRows(invoice: Invoice, showTax: boolean, showDiscount: boolean) {
  const rows: { label: string; amount: number | string; top: number; isFinal?: boolean }[] = []
  const push = (label: string, amount: number | string, isFinal?: boolean) => {
    const top = rows.length === 0 ? TOTALS_FIRST_ROW_TOP : rows[rows.length - 1].top + TOTALS_ROW_PITCH
    rows.push({ label, amount, top, isFinal })
  }
  if (showTax || showDiscount) push('TOTAL', invoice.subtotal)
  if (showTax) push('TAX', invoice.tax_amount)
  if (showDiscount) push('DISC', invoice.discount_amount)
  push('Grand Total', invoice.grand_total, true)
  return rows
}

export interface InvoicePaperLayoutProps {
  invoice: Invoice
  companyName: string
  printHeader: CompanyPrintHeader | undefined
  customerTel: string
  location: string
  signatureLeftLabel: string
  signatureRightLabel: string
  /** Font Style dropdown — unset renders the spec's own DejaVu Sans Condensed (the exact-replica default). */
  fontFamily?: string
  /** Font Size (pt) — unset (or 10) renders at the spec's exact 1:1 scale. Any other value scales the ENTIRE page uniformly via a CSS transform (every mm position/size and every font size all move together), so the layout stays proportionally identical at any size rather than being recomputed per element. */
  fontSizePt?: number
  /** Tampilkan Tax — unset/false renders the spec-default "no tax" look (no HCTax column, no TAX row): real Half invoices are usually untaxed. On reproduces the spec sample's own exact geometry. */
  showTax: boolean
  /** Tampilkan Diskon — adds a DISC row (see buildTotalsRows); off by default, matching every other paper type's own default. */
  showDiscount: boolean
  /** Tampilkan Desimal, totals box only (item-table money columns always show 2 decimals regardless, per Section 9) — unset defaults to ON (2 decimals) here for all three paper types, matching the legacy Half output the spec was extracted from. */
  showDecimalTotals?: boolean
  /** Usable content width in mm — 210 (default) for A4/Half, ~229.3 for Continuous (its wider physical page minus @page margin). Every `left`/`width` value below is expressed against a 210mm reference frame and scaled by `contentWidthMm / 210` via ScaleXContext; `top`/`height`/font sizes never scale. See file doc comment. */
  contentWidthMm?: number
}

export function InvoicePaperLayout({
  invoice,
  companyName,
  printHeader,
  customerTel,
  location,
  signatureLeftLabel,
  signatureRightLabel,
  fontFamily,
  fontSizePt,
  showTax,
  showDiscount,
  showDecimalTotals,
  contentWidthMm = 210,
}: InvoicePaperLayoutProps) {
  const scale = (fontSizePt ?? 10) / 10
  const scaleX = contentWidthMm / 210
  const effectiveFontFamily = fontFamily ?? DEJAVU_FONT_STACK
  const itemCols = getItemCols(showTax)
  const totalsDecimals = (showDecimalTotals ?? true) ? 2 : 0
  const totalsRows = buildTotalsRows(invoice, showTax, showDiscount)
  const totalsBoxHeight = TOTALS_BOX_PAD_TOP + totalsRows.length * TOTALS_ROW_PITCH + TOTALS_BOX_PAD_BOTTOM
  const signatureNameTop = Math.max(LEFT_COLUMN_BOTTOM_MM, TOTALS_BOX_TOP_MM + totalsBoxHeight) + SIGNATURE_GAP_MM

  return (
    <div
      style={{
        position: 'relative',
        width: `${contentWidthMm}mm`,
        height: '148.5mm',
        overflow: 'visible', // pagination for long invoices is unmeasured (spec Section 12) — flow past this box rather than silently clip line items
        fontFamily: effectiveFontFamily,
        color: '#000',
        lineHeight: 1.164,
        transform: scale !== 1 ? `scale(${scale})` : undefined,
        transformOrigin: 'top left',
      }}
    >
      <ScaleXContext.Provider value={scaleX}>
      <style>{FONT_FACES}</style>

      {/* ---------- BLOK KIRI (company + customer) ---------- */}
      <T top={5.94} left={10} size={14} bold>{legacyCompanyName(companyName)}</T>
      {printHeader?.address && <T top={12.65} left={10} size={8}>{printHeader.address}</T>}
      <T top={16.88} left={10} size={8}>TEL</T>
      <T top={16.88} left={22.7} size={8}>:</T>
      <T top={16.88} left={27.2} size={8}>{printHeader?.phone ?? ''}</T>
      <T top={20.85} left={10} size={8}>EMAIL</T>
      <T top={20.85} left={22.7} size={8}>:</T>
      <T top={20.85} left={27.2} size={8}>{printHeader?.email ?? ''}</T>
      <T top={26.46} left={10} size={10} bold>{invoice.customer?.customer_name ?? '—'}</T>
      {invoice.customer?.address && <T top={31.7} left={10} size={8}>{invoice.customer.address}</T>}
      <T top={36.99} left={10} size={8} bold>Tel</T>
      <T top={36.99} left={17.41} size={8} bold>:</T>
      <T top={36.99} left={22.7} size={8}>{customerTel}</T>

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
        <div key={label}>
          <T top={top} left={127.21} width={20} size={9} bold={bold}>{label}</T>
          <T top={top} left={147.31} width={5} size={9} bold={bold}>:</T>
          <T top={top} left={153.4} width={45} size={9} bold={bold}>{value}</T>
        </div>
      ))}

      {/* ---------- JUDUL ---------- */}
      <T top={37.75} left={10} width={190} size={16} bold align="center">INVOICE</T>

      {/* ---------- GARIS ---------- */}
      <Line top={45.22} left={10} width={190} height={0.8} color="#000" />
      <Line top={46.79} left={10.66} width={188.49} height={0.26} color="#383838" />
      <Line top={52.34} left={10.66} width={188.49} height={0.26} color="#383838" />
      <Line top={88.02} left={10} width={190} height={0.2} color="#000" />

      {/* ---------- TABEL ITEM ---------- */}
      <table
        style={{
          position: 'absolute',
          left: 0,
          top: '47.51mm',
          width: `${contentWidthMm}mm`,
          borderCollapse: 'collapse',
          tableLayout: 'fixed',
          fontSize: '9.99pt',
        }}
      >
        <colgroup>
          {itemCols.map((col) => (
            <col key={col.key} style={{ width: `${col.width * scaleX}mm` }} />
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
      <T top={82.44} left={10} size={8}>{terbilangIdr(invoice.grand_total)}</T>
      <T top={88.58} left={10} size={10} bold italic>E. &amp; O.E</T>
      <T top={92.79} left={10} size={9}>1. All cheque and payment should be crossed and made payable to</T>
      <T top={97.29} left={13.7} size={9} bold>{legacyCompanyName(companyName)}</T>
      <T top={102.05} left={13.7} size={9} bold>BCA NO A/C. 0271461312</T>

      {/* ---------- KOTAK TOTAL ---------- */}
      {/* Box auto-fits its height to row count (TOTALS_BOX_PAD_TOP + rows*TOTALS_ROW_PITCH +
          TOTALS_BOX_PAD_BOTTOM) — no hardcoded height, no leftover blank space at 1 row, no
          cramped closing gap at 4. */}
      <div
        style={{
          position: 'absolute',
          left: `${119.27 * scaleX}mm`,
          top: `${TOTALS_BOX_TOP_MM}mm`,
          width: `${79.08 * scaleX}mm`,
          height: `${totalsBoxHeight}mm`,
          border: '0.50mm solid #000',
          boxSizing: 'border-box',
        }}
      />
      {totalsRows.map((row, index) => {
        const { top } = row
        const labelLeft = row.isFinal ? 121.12 : 120.6
        const rpLeft = row.isFinal ? 155.25 : 154.72
        return (
          <div key={row.label}>
            {/* Thin separator directly above Grand Total, only when TOTAL/TAX/DISC rows precede it. */}
            {row.isFinal && index > 0 && (
              <Line top={top - TOTALS_ROW_PITCH / 2} left={120.6} width={73.4} height={0.2} color="#000" />
            )}
            <T top={top} left={labelLeft} size={10} bold={row.isFinal}>{row.label}</T>
            <T top={top} left={rpLeft} width={10} size={10} bold={row.isFinal} align="right">RP</T>
            <T top={top} left={162.4} width={35} size={10} bold={row.isFinal} align="right">{fmt(row.amount, totalsDecimals)}</T>
          </div>
        )
      })}

      {/* ---------- TANDA TANGAN ---------- */}
      {/* signatureNameTop is computed above from whichever of the totals box or the left E&O.E
          column ends lower, plus a real SIGNATURE_GAP_MM buffer — guarantees clearance at any
          row count instead of the old fixed top that left 0.03mm at 4 rows. */}
      <T top={signatureNameTop} left={10.79} width={65} size={9} bold align="center">{invoice.customer?.customer_name ?? '—'}</T>
      <T top={signatureNameTop} left={133.82} width={65} size={9} bold align="center">{companyName}</T>
      <Line top={signatureNameTop + SIGNATURE_LINE_LEFT_OFFSET_MM} left={10.26} width={65} height={0.5} color="#000" />
      <Line top={signatureNameTop + SIGNATURE_LINE_RIGHT_OFFSET_MM} left={133.82} width={65} height={0.5} color="#000" />
      <T top={signatureNameTop + SIGNATURE_LABEL_OFFSET_MM} left={10.26} width={65} size={9} align="center">({signatureLeftLabel})</T>
      <T top={signatureNameTop + SIGNATURE_LABEL_OFFSET_MM} left={133.82} width={65} size={9} align="center">({signatureRightLabel})</T>
      </ScaleXContext.Provider>
    </div>
  )
}
