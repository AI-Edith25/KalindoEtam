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
 */

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

/** SI.pdf's own always-2-decimal, comma-thousands convention (Section 9) — not user-toggleable on this exact-replica layout. */
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

/**
 * Section 7 column geometry. `width` is this column's box width; `pad` is the header's own
 * inward padding from that box's leading edge (left-aligned) or trailing edge (right-aligned) —
 * 0 wherever the header anchor itself defines the box edge. Data cells always add +0.32mm on
 * top of the header pad (Section 7's documented "data selalu bergeser 0.32mm ke dalam").
 */
const ITEM_COLS: { key: string; label: string; align: ColAlign; width: number; pad: number }[] = [
  { key: 'no', label: 'No', align: 'left', width: 19.99, pad: 10.53 },
  { key: 'itemCode', label: 'ItemCode', align: 'left', width: 27.45, pad: 0 },
  { key: 'description', label: 'Description', align: 'left', width: 48.99, pad: 0 },
  { key: 'qty', label: 'Qty', align: 'right', width: 12.53, pad: 0.53 },
  { key: 'uom', label: 'UOM', align: 'left', width: 24.04, pad: 0 },
  { key: 'unitCost', label: 'HCUnitCost', align: 'right', width: 17.07, pad: 0 },
  { key: 'tax', label: 'HCTax', align: 'right', width: 24.6, pad: 0 },
  { key: 'lineAmt', label: 'HCLineAmt', align: 'right', width: 35.33, pad: 10.72 },
]

function cellPadStyle(col: (typeof ITEM_COLS)[number], isData: boolean): React.CSSProperties {
  const pad = col.pad + (isData ? 0.32 : 0)
  return col.align === 'left' ? { paddingLeft: `${pad}mm` } : { paddingRight: `${pad}mm` }
}

export interface InvoiceHalfSkyBizLayoutProps {
  invoice: Invoice
  companyName: string
  printHeader: CompanyPrintHeader | undefined
  customerTel: string
  location: string
  signatureLeftLabel: string
  signatureRightLabel: string
}

export function InvoiceHalfSkyBizLayout({
  invoice,
  companyName,
  printHeader,
  customerTel,
  location,
  signatureLeftLabel,
  signatureRightLabel,
}: InvoiceHalfSkyBizLayoutProps) {
  return (
    <div
      style={{
        position: 'relative',
        width: '210mm',
        height: '148.5mm',
        overflow: 'visible', // pagination for long invoices is unmeasured (spec Section 12) — flow past this box rather than silently clip line items
        fontFamily: '"DejaVu Sans Condensed", sans-serif',
        color: '#000',
        lineHeight: 1.164,
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
          {ITEM_COLS.map((col) => (
            <col key={col.key} style={{ width: `${col.width}mm` }} />
          ))}
        </colgroup>
        <thead>
          <tr>
            {ITEM_COLS.map((col) => (
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
              {ITEM_COLS.map((col) => {
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
      <div style={{ position: 'absolute', left: '119.27mm', top: '89.08mm', width: '79.08mm', height: '18.49mm', border: '0.50mm solid #000', boxSizing: 'border-box' }} />
      <T top={90.17} left={120.6} size={10} bold>TOTAL</T>
      <T top={90.17} left={154.72} width={10} size={10} bold align="right">RP</T>
      <T top={90.17} left={162.4} width={35} size={10} bold align="right">{fmt(invoice.subtotal)}</T>
      <T top={95.46} left={120.6} size={10} bold>TAX</T>
      <T top={95.46} left={154.72} width={10} size={10} bold align="right">RP</T>
      <T top={95.46} left={162.4} width={35} size={10} bold align="right">{fmt(invoice.tax_amount)}</T>
      <T top={100.75} left={121.12} size={10} bold>Grand Total</T>
      <T top={100.75} left={155.25} width={10} size={10} bold align="right">RP</T>
      <T top={100.75} left={162.4} width={35} size={10} bold align="right">{fmt(invoice.grand_total)}</T>

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
