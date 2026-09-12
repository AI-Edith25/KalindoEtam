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
 * Every `top`/font-size value below is a literal mm/pt constant shared unscaled by all three paper
 * types (same typography, same vertical rhythm) — only `left`/`width` values (via the `T`/`Line`
 * helpers' own ScaleXContext read) scale horizontally by `contentWidthMm / 210` to fill each paper
 * type's own usable content width. A4's own usable width (210mm) equals this component's 210mm
 * reference frame exactly, so A4 renders pixel-identical to Half horizontally with scale 1 — only
 * Continuous (wider physical paper) actually scales.
 *
 * `contentWidthMm`/`contentHeightMm` are the USABLE area after each paper type's own @page margin
 * (computed once in InvoicePrintPage.tsx's own PAPER_SIZES map) — NOT the raw physical paper size.
 * This component's own root div must stay sized to the usable area, never the physical size: for
 * Continuous specifically (@page margin: 6mm), a root div sized to the full physical page would
 * claim more horizontal space than the printable area actually has once the browser applies that
 * margin, silently clipping ~6mm off one edge during a real print. The on-screen physical-paper
 * framing (so A4 previews portrait-tall, Continuous previews wider) lives one level up, on
 * InvoicePrintPage's own wrapper div, which is reset to `width:auto;height:auto` during print (see
 * that file) so it just shrink-wraps this component's print-safe usable size.
 *
 * Because Half's own reference frame is only 148.5mm tall while A4 (297mm usable) and Continuous
 * (267.4mm usable) are physically taller, the header block and item table keep their exact Half
 * coordinates unscaled and unshifted (same position on every paper type) — but the FOOTER block
 * (amount-in-words, E&O.E note, totals table, signature) is shifted down as one unit by
 * `footerOffsetMm = contentHeightMm - 148.5`, landing it near the true bottom of whichever paper's
 * actually taller, rather than hanging at Half's own cramped offset with acres of blank paper below
 * it. For Half itself the offset is exactly 0 — nothing about Half's own rendering changes.
 *
 * The Grand Total box is a real `<table>` (border-collapse: collapse) rather than a stack of
 * absolutely-positioned text over a separately-positioned border div — a previous version drew the
 * separator above Grand Total as a manually-positioned line, which cut through the previous row's
 * own text because the offset math didn't account for real line-height. A `<table>` cannot make
 * that mistake: every row's height is the browser's own padding + line-height, and the separator is
 * a plain `border-top` on the Grand Total `<tr>`, which can only ever land on a row boundary.
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

/** Gap between a label's own right edge and the ":" that follows it, and again between the ":"
    and the value — a real, constant gap on every row (see MetaField) is exactly what fixes the
    label/colon collision bug: labels are right-aligned inside a box anchored at `labelRight`, so a
    long label ("Payment Term") and a short one ("Date") both end flush at the same x-position
    before this gap, instead of a short common LEFT start position letting long labels run into the
    colon (the original bug). */
const META_GAP_MM = 1.5
const META_COLON_WIDTH_MM = 3

/**
 * One label/":"/value row, used for both the left block (TEL/EMAIL/Tel) and the right meta block
 * (NO/Date/Reference 1/Payment Term/.../Location) — same component, same gap rule, so both blocks
 * stay consistent by construction rather than by separately hand-tuned numbers.
 */
function MetaField({
  top,
  labelRight,
  labelWidth,
  label,
  value,
  size,
  bold,
  valueBold,
}: {
  top: number
  labelRight: number
  labelWidth: number
  label: string
  value: React.ReactNode
  size: number
  bold?: boolean
  /** Defaults to `bold` — only the customer "Tel" row on the left block needs its value NOT bold
      while its own label/colon stay bold, matching the original design. */
  valueBold?: boolean
}) {
  const colonLeft = labelRight + META_GAP_MM
  const valueLeft = colonLeft + META_COLON_WIDTH_MM + META_GAP_MM
  return (
    <>
      <T top={top} left={labelRight - labelWidth} width={labelWidth} size={size} bold={bold} align="right">
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

/** Anchor x-position (the label's own right edge, before the gap+colon) and a generously wide
    label box for the left block (TEL/EMAIL/Tel — all short labels, plenty of headroom). */
const LEFT_LABEL_RIGHT_MM = 21
const LEFT_LABEL_WIDTH_MM = 15
/** Same for the right meta block, sized for its longest labels ("Payment Term"/"Sales Person") —
    45mm of right-aligned room is far more than either needs at 9pt, so neither can ever reach the
    box's own left edge, let alone the colon that follows the box's right edge. */
const RIGHT_LABEL_RIGHT_MM = 158
const RIGHT_LABEL_WIDTH_MM = 45

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

/** Totals table's own outer box — left/width scale via scaleX like everything else; `top` is
    footer-relative (see fy() in the component body). Right edge (119.27+80.01=199.28mm) matches
    the item table's own HCLineAmt header-pad reference (210-10.72), so the nominal column below
    reads as the same right margin as the item table's own amount column. */
const TOTALS_TABLE_LEFT_MM = 119.27
const TOTALS_TABLE_WIDTH_MM = 80.01
const TOTALS_LABEL_COL_MM = 40
const TOTALS_RP_COL_MM = 12
const TOTALS_NOMINAL_COL_MM = TOTALS_TABLE_WIDTH_MM - TOTALS_LABEL_COL_MM - TOTALS_RP_COL_MM
const TOTALS_BOX_TOP_MM = 89.08

/** Static estimate of one totals row's real rendered height (padding + line-height), used only to
    predict where the signature block needs to start (see signatureNameTop) — the actual table
    below is real HTML and sizes itself; this constant never affects its rendering, only the
    downstream estimate. Rounded up from ~6.6mm (2×1mm padding + 10pt at line-height 1.3) for a
    safety margin. */
const TOTALS_ROW_HEIGHT_ESTIMATE_MM = 7

function buildTotalsRows(invoice: Invoice, showTax: boolean, showDiscount: boolean) {
  const rows: { label: string; amount: number | string; isFinal?: boolean }[] = []
  if (showTax || showDiscount) rows.push({ label: 'TOTAL', amount: invoice.subtotal })
  if (showTax) rows.push({ label: 'TAX', amount: invoice.tax_amount })
  if (showDiscount) rows.push({ label: 'DISC', amount: invoice.discount_amount })
  rows.push({ label: 'Grand Total', amount: invoice.grand_total, isFinal: true })
  return rows
}

/** Bottom of the left-column E&O.E/BCA note block (row-count-independent — that block's own
    content never changes), footer-relative like everything else in that block — approximated from
    its last line's own top (102.05mm) plus a 9pt line's height; needs one visual tuning pass
    against the real print preview once implemented. */
const LEFT_COLUMN_BOTTOM_MM = 106
/** Real clearance between whichever of the totals table or the left column ends lower, and the
    signature name text below it — replaces an old fixed top, which left only 0.03mm at 4 rows and
    wasted space at 1 row. */
const SIGNATURE_GAP_MM = 4
/** Vertical offsets from the signature name's own top down to the rest of the signature block,
    preserved exactly from the original fixed absolute values (135.12/134.86/136.18 minus the old
    110.25 name top) — including the real 0.26mm left/right line asymmetry, not a rounding error. */
const SIGNATURE_LINE_LEFT_OFFSET_MM = 24.87
const SIGNATURE_LINE_RIGHT_OFFSET_MM = 24.61
const SIGNATURE_LABEL_OFFSET_MM = 25.93

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
  /** Usable content width in mm (after the paper type's own @page margin) — 210 (default) for A4/Half, ~229.3 for Continuous. Every `left`/`width` value below is expressed against a 210mm reference frame and scaled by `contentWidthMm / 210` via ScaleXContext; `top`/font sizes never scale. See file doc comment. */
  contentWidthMm?: number
  /** Usable content HEIGHT in mm (after the paper type's own @page margin) — 148.5 (default) for Half, 297 for A4, ~267.4 for Continuous. Drives this component's own root div height and how far the footer block shifts down (footerOffsetMm). Must be the usable area, never the raw physical paper height — see file doc comment. */
  contentHeightMm?: number
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
  contentHeightMm = 148.5,
}: InvoicePaperLayoutProps) {
  const scale = (fontSizePt ?? 10) / 10
  const scaleX = contentWidthMm / 210
  const effectiveFontFamily = fontFamily ?? DEJAVU_FONT_STACK
  const itemCols = getItemCols(showTax)
  const totalsDecimals = (showDecimalTotals ?? true) ? 2 : 0
  const totalsRows = buildTotalsRows(invoice, showTax, showDiscount)

  // Everything at/below the amount-in-words line is one "footer" unit that shifts down together
  // on taller-than-Half paper (A4/Continuous), landing near the real bottom of that paper instead
  // of hanging at Half's own cramped offset — see file doc comment. Half itself (148.5mm) gets 0.
  const footerOffsetMm = contentHeightMm - 148.5
  const fy = (mm: number) => mm + footerOffsetMm

  const totalsBoxHeightEstimate = totalsRows.length * TOTALS_ROW_HEIGHT_ESTIMATE_MM
  const signatureNameTop = Math.max(fy(LEFT_COLUMN_BOTTOM_MM), fy(TOTALS_BOX_TOP_MM) + totalsBoxHeightEstimate) + SIGNATURE_GAP_MM

  return (
    <div
      style={{
        position: 'relative',
        width: `${contentWidthMm}mm`,
        height: `${contentHeightMm}mm`,
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
      <MetaField top={16.88} labelRight={LEFT_LABEL_RIGHT_MM} labelWidth={LEFT_LABEL_WIDTH_MM} label="TEL" value={printHeader?.phone ?? ''} size={8} />
      <MetaField top={20.85} labelRight={LEFT_LABEL_RIGHT_MM} labelWidth={LEFT_LABEL_WIDTH_MM} label="EMAIL" value={printHeader?.email ?? ''} size={8} />
      <T top={26.46} left={10} size={10} bold>{invoice.customer?.customer_name ?? '—'}</T>
      {invoice.customer?.address && <T top={31.7} left={10} size={8}>{invoice.customer.address}</T>}
      <MetaField top={36.99} labelRight={LEFT_LABEL_RIGHT_MM} labelWidth={LEFT_LABEL_WIDTH_MM} label="Tel" value={customerTel} size={8} bold valueBold={false} />

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
        <MetaField key={label} top={top} labelRight={RIGHT_LABEL_RIGHT_MM} labelWidth={RIGHT_LABEL_WIDTH_MM} label={label} value={value} size={9} bold={bold} />
      ))}

      {/* ---------- JUDUL ---------- */}
      <T top={37.75} left={10} width={190} size={16} bold align="center">INVOICE</T>

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

      {/* ---------- FOOTER (amount in words / E&O.E / totals / signature) ----------
          Everything from here down is shifted by fy() as one unit — see footerOffsetMm above. */}
      <T top={fy(82.44)} left={10} size={8}>{terbilangIdr(invoice.grand_total)}</T>
      <Line top={fy(88.02)} left={10} width={190} height={0.2} color="#000" />
      <T top={fy(88.58)} left={10} size={10} bold italic>E. &amp; O.E</T>
      <T top={fy(92.79)} left={10} size={9}>1. All cheque and payment should be crossed and made payable to</T>
      <T top={fy(97.29)} left={13.7} size={9} bold>{legacyCompanyName(companyName)}</T>
      <T top={fy(102.05)} left={13.7} size={9} bold>BCA NO A/C. 0271461312</T>

      {/* ---------- KOTAK TOTAL ----------
          A real <table> (border-collapse: collapse) — row height is the browser's own padding +
          line-height, so the separator below (a border-top on Grand Total's own row) can only ever
          land on that row's edge, never through a previous row's text. Auto-fits height to however
          many rows show, no hardcoded box height. */}
      <table
        style={{
          position: 'absolute',
          left: `${TOTALS_TABLE_LEFT_MM * scaleX}mm`,
          top: `${fy(TOTALS_BOX_TOP_MM)}mm`,
          width: `${TOTALS_TABLE_WIDTH_MM * scaleX}mm`,
          borderCollapse: 'collapse',
          border: '0.5mm solid #000',
          tableLayout: 'fixed',
        }}
      >
        <colgroup>
          <col style={{ width: `${TOTALS_LABEL_COL_MM * scaleX}mm` }} />
          <col style={{ width: `${TOTALS_RP_COL_MM * scaleX}mm` }} />
          <col style={{ width: `${TOTALS_NOMINAL_COL_MM * scaleX}mm` }} />
        </colgroup>
        <tbody>
          {totalsRows.map((row, index) => {
            // Separator only above Grand Total, and only when TOTAL/TAX/DISC rows precede it.
            const separator = row.isFinal && index > 0 ? '0.2mm solid #000' : undefined
            const cellStyle: React.CSSProperties = {
              fontSize: '10pt',
              fontWeight: row.isFinal ? 700 : 400,
              lineHeight: 1.3,
              padding: '1mm 0',
              borderTop: separator,
            }
            return (
              <tr key={row.label}>
                <td style={{ ...cellStyle, textAlign: 'left', paddingLeft: '1.33mm' }}>{row.label}</td>
                <td style={{ ...cellStyle, textAlign: 'right', paddingRight: '1mm' }}>RP</td>
                <td style={{ ...cellStyle, textAlign: 'right', paddingRight: '0.32mm' }}>{fmt(row.amount, totalsDecimals)}</td>
              </tr>
            )
          })}
        </tbody>
      </table>

      {/* ---------- TANDA TANGAN ---------- */}
      {/* signatureNameTop is computed above from whichever of the totals table or the left E&O.E
          column ends lower, plus a real SIGNATURE_GAP_MM buffer — guarantees clearance at any
          row count instead of an old fixed top that left 0.03mm at 4 rows. */}
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
