import type { CSSProperties } from 'react'
import { PrintMetaTable } from '@/components/shared/PrintMetaTable'
import { terbilangIdrPlain } from '@/shared/lib/numberToWords'
import type { PrintOptions } from '@/shared/lib/printOptions'
import {
  DETAIL_TABLE_BASE_GAP_MM,
  DETAIL_TABLE_ROW_HEIGHT_MM,
  FONT_PT,
  PAGE_HEIGHT_MM,
  PAGE_PADDING,
  PAGE_WIDTH_MM,
  formatDdMmYyyy,
  formatNum,
} from '../pages/paymentVoucherPrintConstants'
import type { PaymentEntry, PaymentVoucherLine } from '../types'

const TABLE1_COLS = [
  { key: 'account', label: 'A/C', align: 'left' as const, percent: 30 },
  { key: 'description', label: 'Description', align: 'left' as const, percent: 52 },
  { key: 'amount', label: 'Amount (RP)', align: 'right' as const, percent: 18 },
]

/** Vector-measured percentages of the reference PDF's ~535pt content width. */
const TABLE2_COLS = [
  { key: 'docDate', label: 'Doc Date', align: 'left' as const, percent: 11.6 },
  { key: 'documentNo', label: 'Document #', align: 'left' as const, percent: 17.6 },
  { key: 'referenceNo', label: 'Reference #', align: 'left' as const, percent: 16.6 },
  { key: 'particulars', label: 'Particulars', align: 'left' as const, percent: 19.7 },
  { key: 'orgAmt', label: 'Org. Amt', align: 'right' as const, percent: 14.6 },
  { key: 'paidAmt', label: 'Paid Amt', align: 'right' as const, percent: 17.7 },
]

const SIGNATURE_LABELS = ['Diperiksa,', 'Disetujui,', 'Diketahui,', 'Kasir,', 'Diterima Oleh,']

function accountLabel(account: { code: string; name: string } | null | undefined): string {
  return account ? `${account.code} ${account.name}` : ''
}

/** Table 1's "Description" — the free-text entered on the Purchase Invoice for supplier lines, the expense line's own description otherwise. */
function table1Description(line: PaymentVoucherLine): string {
  return line.purpose_type === 'supplier' ? (line.accounts_payable?.invoice?.remarks ?? '') : (line.description ?? '')
}

interface PaymentVoucherPrintTemplateProps {
  payment: PaymentEntry
  companyName: string
  printOptions: PrintOptions
  /** Default 'PAYMENT VOUCHER' — seam for a future Official Receipt print to reuse this same layout under a different title. */
  title?: string
}

/**
 * Replica of the company's real Payment Voucher (paymentvoucher_vs9oodv4ldpbdtcgmvo6stcs76.pdf).
 * Positions/sizes below are vector-measured off that PDF's own coordinates (pt, converted to mm
 * and to ratios of the 9pt body size) — every font size in this component is expressed as a ratio
 * of `bodyPt` rather than a bare constant, so the Font Size print option still scales the whole
 * document proportionally instead of only the body text.
 *
 * 100% black text — no accent colors (an earlier pass added maroon/blue based on a screenshot
 * read; the vector data confirms the real template has none, reverted).
 *
 * Table 1's A/C column intentionally shows the REAL system account (e.g. "1250 ADVANCE TO
 * SUPPLIERS") rather than the reference PDF's legacy dotted code ("210.01.01 HUTANG SUPPLIER") —
 * that legacy code format doesn't exist in this system's chart of accounts; reformatting the whole
 * COA scheme is a separate, out-of-scope change. Known, accepted deviation from the reference.
 *
 * No "Hal. X / Y" page-number indicator: browsers' print engines don't expose a true current-page
 * or total-page count to in-page HTML/CSS (that's CSS Paged Media, only meaningfully supported by
 * a dedicated PDF-rendering pipeline, e.g. a headless-Chrome server-side render — not built here).
 * ponytail: ceiling is "single source of truth for page count doesn't exist in-browser" — upgrade
 * path is a server-rendered PDF pipeline, if accurate page numbers are ever required.
 */
export function PaymentVoucherPrintTemplate({ payment, companyName, printOptions, title = 'PAYMENT VOUCHER' }: PaymentVoucherPrintTemplateProps) {
  const decimals = printOptions.amountDecimals
  const bodyPt = FONT_PT[printOptions.fontSize]
  const lines = payment.lines

  // Table 2's real data rows carry NO border (vector-confirmed: only the header row is
  // bordered, the space below is pure whitespace) — the base gap already accounts for exactly 1
  // data row, each additional row eats into it so the footer stays roughly where the template has
  // it. Clamped at 0 once rows exceed what fits; the page then overflows normally.
  const extraRows = Math.max(0, lines.length - 1)
  const detailSpacerMm = Math.max(0, DETAIL_TABLE_BASE_GAP_MM - extraRows * DETAIL_TABLE_ROW_HEIGHT_MM)

  return (
    <div
      style={{
        display: 'flex',
        flexDirection: 'column',
        width: `${PAGE_WIDTH_MM}mm`,
        minHeight: `${PAGE_HEIGHT_MM}mm`,
        boxSizing: 'border-box',
        padding: PAGE_PADDING,
        fontFamily: 'Arial, Helvetica, sans-serif',
        fontSize: `${bodyPt}pt`,
        color: '#000',
        lineHeight: 1.3,
      }}
    >
      {/* ---------- Header: two columns, no outer border ---------- */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr' }}>
        <div>
          <PrintMetaTable size={bodyPt} rows={[{ label: 'Bank Account', value: payment.cash_account?.name ?? '' }]} />
          <p style={{ margin: 0, fontSize: `${bodyPt * (8 / 9)}pt`, fontWeight: 700 }}>Keterangan :</p>
          <p style={{ margin: 0 }}>
            {companyName}
            {payment.cash_account?.name ? `; ${payment.cash_account.name}` : ''}
          </p>
        </div>
        <div style={{ textAlign: 'right' }}>
          <p style={{ margin: 0, fontSize: `${bodyPt * (14 / 9)}pt`, fontWeight: 700 }}>{title}</p>
          <div style={{ display: 'inline-block', marginTop: '1mm', textAlign: 'left' }}>
            <PrintMetaTable
              size={bodyPt}
              rows={[
                { label: 'NO', value: payment.document_number ?? '', bold: true },
                { label: 'Date', value: formatDdMmYyyy(payment.payment_date) },
                { label: 'Cheque No', value: '' },
              ]}
            />
          </div>
        </div>
      </div>

      {/* ---------- Table 1: A/C | Description | Amount (RP) — header bordered, data row plain (no border) ---------- */}
      <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed', marginTop: '2mm' }}>
        <colgroup>
          {TABLE1_COLS.map((col) => (
            <col key={col.key} style={{ width: `${col.percent}%` }} />
          ))}
        </colgroup>
        <thead>
          <tr style={{ borderTop: '0.75pt solid #000', borderBottom: '0.75pt solid #000' }}>
            {TABLE1_COLS.map((col, index) => (
              <th
                key={col.key}
                style={{
                  textAlign: col.key === 'account' ? 'left' : col.key === 'amount' ? 'right' : 'center',
                  fontWeight: 400,
                  fontSize: `${bodyPt * (8.8 / 9)}pt`,
                  padding: '1mm 1.5mm',
                  borderRight: index < TABLE1_COLS.length - 1 ? '0.75pt solid #000' : undefined,
                }}
              >
                {col.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {lines.map((line, index) => {
            const account = line.purpose_type === 'supplier' ? line.gl_account : line.expense_account
            return (
              <tr key={line.id ?? index}>
                <td style={{ padding: '1mm 1.5mm', textAlign: 'left' }}>{accountLabel(account)}</td>
                <td style={{ padding: '1mm 1.5mm', textAlign: 'left' }}>{table1Description(line)}</td>
                <td style={{ padding: '1mm 1.5mm', textAlign: 'right' }}>{formatNum(line.amount, decimals)}</td>
              </tr>
            )
          })}
        </tbody>
      </table>

      {/* ---------- Table 2: Doc Date | Document # | Reference # | Particulars | Org. Amt | Paid Amt
          Header bordered (top/bottom + column separators); data rows carry NO border at all —
          plain floating text, matching the reference PDF exactly. ---------- */}
      <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed', marginTop: '8mm' }}>
        <colgroup>
          {TABLE2_COLS.map((col) => (
            <col key={col.key} style={{ width: `${col.percent}%` }} />
          ))}
        </colgroup>
        {/* table-header-group repeats this row on every printed page for a multi-page voucher. */}
        <thead style={{ display: 'table-header-group' }}>
          <tr style={{ borderTop: '0.75pt solid #000', borderBottom: '0.75pt solid #000' }}>
            {TABLE2_COLS.map((col, index) => (
              <th
                key={col.key}
                style={{
                  textAlign: 'center',
                  fontWeight: 700,
                  padding: '1mm 1.5mm',
                  borderRight: index < TABLE2_COLS.length - 1 ? '0.75pt solid #000' : undefined,
                }}
              >
                {col.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {lines.map((line, index) => (
            <tr key={line.id ?? index} style={{ breakInside: 'avoid' }}>
              <td style={dataCellStyle}>{line.purpose_type === 'supplier' ? formatDdMmYyyy(line.accounts_payable?.invoice?.invoice_date) : ''}</td>
              <td style={dataCellStyle}>{line.purpose_type === 'supplier' ? (line.accounts_payable?.invoice?.document_number ?? '') : ''}</td>
              <td style={dataCellStyle}>{line.purpose_type === 'supplier' ? (line.accounts_payable?.invoice?.reference_number ?? '') : ''}</td>
              <td style={dataCellStyle}>{line.notes ?? ''}</td>
              <td style={{ ...dataCellStyle, textAlign: 'right' }}>
                {formatNum(line.purpose_type === 'supplier' ? (line.accounts_payable?.amount ?? line.amount) : line.amount, decimals)}
              </td>
              <td style={{ ...dataCellStyle, textAlign: 'right' }}>{formatNum(line.amount, decimals)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      {/* Pure whitespace, no grid — shrinks as real data rows are added so the footer below lands close to its template position on a short voucher. */}
      <div style={{ height: `${detailSpacerMm}mm` }} />

      {/* ---------- Footer: terbilang (kiri) / TOTAL (kanan) — ordinary flow after table 2, so on a
          multi-page voucher this naturally lands on the true last page with no tfoot trick needed. ---------- */}
      <div style={{ borderTop: '0.75pt solid #000', paddingTop: '1.3mm', display: 'grid', gridTemplateColumns: '1fr auto', gap: '4mm', alignItems: 'start' }}>
        <p style={{ margin: 0 }}>RP: {terbilangIdrPlain(payment.total_amount, decimals)}</p>
        <p style={{ margin: 0, whiteSpace: 'nowrap' }}>
          <span style={{ fontWeight: 700 }}>TOTAL : RP</span>{' '}
          <span style={{ fontWeight: 700, fontSize: `${bodyPt * (10.5 / 9)}pt` }}>{formatNum(payment.total_amount, decimals)}</span>
        </p>
      </div>

      {/* ---------- Signature block: 5 equal columns ---------- */}
      <div style={{ marginTop: '16.4mm', display: 'grid', gridTemplateColumns: 'repeat(5, 1fr)', gap: '2mm' }}>
        {SIGNATURE_LABELS.map((label) => (
          <div key={label}>
            <p style={{ margin: 0, fontSize: `${bodyPt * (10 / 9)}pt` }}>{label}</p>
            <div style={{ height: '22mm' }} />
            <div style={{ width: '80%', borderTop: '0.75pt solid #000' }} />
          </div>
        ))}
      </div>
    </div>
  )
}

const dataCellStyle: CSSProperties = {
  padding: '1mm 1.5mm',
  verticalAlign: 'top',
}
