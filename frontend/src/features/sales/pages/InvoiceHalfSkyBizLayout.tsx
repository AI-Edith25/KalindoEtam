import type { CompanyPrintHeader } from '@/features/administration/types'
import { terbilangIdr } from '@/shared/lib/numberToWords'
import type { Invoice } from '../types'

/**
 * Precise replica of the legacy SkyBiz salesinvoice.php print (DocumentTemplate=23) for Half
 * (A5 landscape, 210x148.5mm) paper — see invoice-print-spec.md at the repo root, which is the
 * single source of truth for every mm value below (extracted from the PDF's own content stream,
 * not estimated). Absolute positioning throughout per that spec's own Section 0 rule 3: mPDF
 * places every text baseline independently with irregular gaps, which flow/flex layout cannot
 * reproduce. Do not "fix" the irregular spacing — it's how the original renders.
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
 */

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
  return (
    <div
      style={{
        position: 'absolute',
        top: `${top}mm`,
        left: `${left}mm`,
        width: width != null ? `${width}mm` : undefined,
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
  return <div style={{ position: 'absolute', top: `${top}mm`, left: `${left}mm`, width: `${width}mm`, height: `${height}mm`, background: color }} />
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
 * Row positions measured directly (pdfminer bbox extraction, not estimated) from two real legacy
 * exports sharing this exact meta-block shape: tax.pdf (TOTAL/TAX/Grand Total) and tax+disc.pdf
 * (TOTAL/TAX/DISC/Grand Total). Both confirm the first row always starts at a fixed 90.75mm and
 * every later row uses a constant 5.29mm step from the row before it — EXCEPT stepping into DISC
 * specifically, which measured a real, repeatable 4.76mm (not a rounding artifact — same kind of
 * "irregular gap, don't smooth it" quirk the rest of this page's spec already documents). A row
 * with no real reference sample (Grand Total alone, or DISC without Tax) reuses these same two
 * constants as the most defensible extrapolation.
 */
const TOTALS_FIRST_ROW_TOP = 90.75
const TOTALS_NORMAL_PITCH = 5.29
const TOTALS_DISC_PITCH = 4.76

function buildTotalsRows(invoice: Invoice, showTax: boolean, showDiscount: boolean) {
  const rows: { label: string; amount: number | string; top: number; isFinal?: boolean }[] = []
  const push = (label: string, amount: number | string, pitchFromPrev: number, isFinal?: boolean) => {
    const top = rows.length === 0 ? TOTALS_FIRST_ROW_TOP : rows[rows.length - 1].top + pitchFromPrev
    rows.push({ label, amount, top, isFinal })
  }
  if (showTax || showDiscount) push('TOTAL', invoice.subtotal, TOTALS_NORMAL_PITCH)
  if (showTax) push('TAX', invoice.tax_amount, TOTALS_NORMAL_PITCH)
  if (showDiscount) push('DISC', invoice.discount_amount, TOTALS_DISC_PITCH)
  push('Grand Total', invoice.grand_total, TOTALS_NORMAL_PITCH, true)
  return rows
}

export interface InvoiceHalfSkyBizLayoutProps {
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
  /** Tampilkan Desimal, totals box only (item-table money columns always show 2 decimals regardless, per Section 9) — unset defaults to ON (2 decimals) here, unlike A4/Continuous/Roll's own default-OFF, because the legacy Half output the spec was extracted from always showed 2-decimal totals. */
  showDecimalTotals?: boolean
}

export function InvoiceHalfSkyBizLayout({
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
}: InvoiceHalfSkyBizLayoutProps) {
  const scale = (fontSizePt ?? 10) / 10
  const effectiveFontFamily = fontFamily ?? DEJAVU_FONT_STACK
  const itemCols = getItemCols(showTax)
  const totalsDecimals = (showDecimalTotals ?? true) ? 2 : 0
  const totalsRows = buildTotalsRows(invoice, showTax, showDiscount)

  return (
    <div
      style={{
        position: 'relative',
        width: '210mm',
        height: '148.5mm',
        overflow: 'visible', // pagination for long invoices is unmeasured (spec Section 12) — flow past this box rather than silently clip line items
        fontFamily: effectiveFontFamily,
        color: '#000',
        lineHeight: 1.164,
        transform: scale !== 1 ? `scale(${scale})` : undefined,
        transformOrigin: 'top left',
      }}
    >
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
      <T top={82.44} left={10} size={8}>{terbilangIdr(invoice.grand_total)}</T>
      <T top={88.58} left={10} size={10} bold italic>E. &amp; O.E</T>
      <T top={92.79} left={10} size={9}>1. All cheque and payment should be crossed and made payable to</T>
      <T top={97.29} left={13.7} size={9} bold>{legacyCompanyName(companyName)}</T>
      <T top={102.05} left={13.7} size={9} bold>BCA NO A/C. 0271461312</T>

      {/* ---------- KOTAK TOTAL ---------- */}
      {/* Box hugs its rows tightly rather than sitting at the spec sample's fixed 18.49mm height —
          it does NOT just track the last row's position plus fixed padding: measured directly
          (pdfminer bbox) from tax.pdf (3 rows, box 89.08–107.57mm) and tax+disc.pdf (4 rows,
          89.08–110.22mm), the closing gap after the last row actually SHRINKS as rows are added
          (6.24mm at 3 rows, down to 4.13mm at 4) rather than staying constant — so total box
          height is fit directly against those two real measurements (10.54 + 2.65mm per row)
          instead of extrapolated from a per-row-padding assumption, which is what previously
          produced a box tall enough to run into the signature block at 4 rows. */}
      <div
        style={{
          position: 'absolute',
          left: '119.27mm',
          top: '89.08mm',
          width: '79.08mm',
          height: `${10.54 + totalsRows.length * 2.65}mm`,
          border: '0.50mm solid #000',
          boxSizing: 'border-box',
        }}
      />
      {totalsRows.map((row) => {
        const { top } = row
        const labelLeft = row.isFinal ? 121.12 : 120.6
        const rpLeft = row.isFinal ? 155.25 : 154.72
        return (
          <div key={row.label}>
            <T top={top} left={labelLeft} size={10} bold>{row.label}</T>
            <T top={top} left={rpLeft} width={10} size={10} bold align="right">RP</T>
            <T top={top} left={162.4} width={35} size={10} bold align="right">{fmt(row.amount, totalsDecimals)}</T>
          </div>
        )
      })}

      {/* ---------- TANDA TANGAN ---------- */}
      <T top={110.25} left={10.79} width={65} size={9} bold align="center">{invoice.customer?.customer_name ?? '—'}</T>
      <T top={110.25} left={133.82} width={65} size={9} bold align="center">{companyName}</T>
      <Line top={135.12} left={10.26} width={65} height={0.5} color="#000" />
      <Line top={134.86} left={133.82} width={65} height={0.5} color="#000" />
      <T top={136.18} left={10.26} width={65} size={9} align="center">({signatureLeftLabel})</T>
      <T top={136.18} left={133.82} width={65} size={9} align="center">({signatureRightLabel})</T>
    </div>
  )
}
