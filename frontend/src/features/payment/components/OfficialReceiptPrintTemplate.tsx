import { PrintMetaTable } from '@/components/shared/PrintMetaTable'
import { terbilangIdrPlain } from '@/shared/lib/numberToWords'
import type { PrintOptions } from '@/shared/lib/printOptions'
import { DETAIL_TABLE_BASE_GAP_MM, DETAIL_TABLE_ROW_HEIGHT_MM, FONT_PT, PAGE_HEIGHT_MM, PAGE_PADDING, PAGE_WIDTH_MM, formatDdMmYyyy, formatNum } from '../pages/officialReceiptPrintConstants'
import type { ReceiptEntry } from '../types'

const TABLE1_COLS = [
  { key: 'account', label: 'A/C', percent: 25 },
  { key: 'description', label: 'Description', percent: 55 },
  { key: 'amount', label: 'Amount (RP)', percent: 20 },
]

const SUBTABLE_COLS = [
  { key: 'doc_date', label: 'Doc Date', percent: 18 },
  { key: 'document_no', label: 'Document #', percent: 37 },
  { key: 'org_amt', label: 'Org. Amt', percent: 22.5 },
  { key: 'paid_amt', label: 'Paid Amt', percent: 22.5 },
]

const SIGNATURE_LABELS = ['Menyetujui,', 'Mengetahui,', 'Dibayar Oleh,', 'Diterima Oleh,']

/** The Piutang control account this receipt's A/C row shows — mirrors ReceivableCategory::accountCode()
    (backend), real codes/names from ChartOfAccountsSeeder::seedPiutangHierarchy(). Kept as a small
    frontend constant (same pattern as PAYMENT_METHOD_LABELS) rather than fetching the full
    Chart-of-Accounts list just to look up 1 of 3 fixed codes.
    Reference PDF shows "112.01.01" (a sub-account level this system's real COA doesn't have — see
    the restructure migration's own comment on why); same deliberate "real system account over the
    legacy PDF's code" deviation PaymentVoucherPrintTemplate already documents for its own A/C column. */
const RECEIVABLE_ACCOUNT_BY_CATEGORY: Record<'C' | 'PK' | 'PL', { code: string; name: string }> = {
  C: { code: '112.01', name: 'Piutang Usaha' },
  PK: { code: '112.02', name: 'Piutang Karyawan' },
  PL: { code: '112.03', name: 'Piutang Lain-lain' },
}

interface OfficialReceiptPrintTemplateProps {
  receipt: ReceiptEntry
  companyName: string
  printOptions: PrintOptions
}

/**
 * Replica of the company's real Official Receipt (mpdf (1).pdf) — structurally mirrors
 * PaymentVoucherPrintTemplate.tsx (same header/table/footer/signature skeleton, same ratio-of-
 * bodyPt font sizing so the Font Size print option scales proportionally), with its own A/C+
 * Description+Amount table carrying a nested Doc Date/Document #/Org. Amt/Paid Amt breakdown.
 *
 * Reversed allocations are excluded (same reasoning as the old PAYMENT RECEIPT layout this
 * replaces: PaymentAllocation.allocated_amount already nets out reversals, so re-including a
 * reversed row here would make the sub-table rows not foot to the Amount it's nested under).
 *
 * payment_type=other_income has no customer/AR allocations at all — falls back to the receipt's
 * own description/income_account so the template never crashes on a null customer, rather than
 * assuming payment_type=customer everywhere.
 */
export function OfficialReceiptPrintTemplate({ receipt, companyName, printOptions }: OfficialReceiptPrintTemplateProps) {
  const decimals = printOptions.amountDecimals
  const bodyPt = FONT_PT[printOptions.fontSize]

  const receivedFromName = receipt.customer?.customer_name ?? receipt.description ?? ''
  const cashAccountName = receipt.cash_account?.name ?? ''
  const account =
    receipt.payment_type === 'customer' && receipt.customer
      ? RECEIVABLE_ACCOUNT_BY_CATEGORY[receipt.customer.receivable_category]
      : receipt.income_account
        ? { code: receipt.income_account.code, name: receipt.income_account.name }
        : { code: '', name: '' }
  const chequeNo = receipt.giro_number || receipt.reference_number || ''
  const activeItems = receipt.items.filter((item) => !item.is_reversed)

  const extraRows = Math.max(0, activeItems.length - 1)
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
      {/* Letterhead — the reference PDF has no logo/company-name placement of its own, so this keeps
          the same small top-left spot the old PAYMENT RECEIPT layout used. */}
      <p style={{ margin: '0 0 2mm 0', fontWeight: 700, fontSize: `${bodyPt}pt` }}>{companyName}</p>

      {/* ---------- Header: two columns, no outer border ---------- */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr' }}>
        <div>
          <PrintMetaTable size={bodyPt} rows={[{ label: 'Diterima Dari', value: receivedFromName, bold: true, valueBold: false }]} />
          <p style={{ margin: 0, fontSize: `${bodyPt * (8 / 9)}pt`, fontWeight: 700 }}>Keterangan :</p>
          <p style={{ margin: 0 }}>
            {receivedFromName}
            {cashAccountName ? `; ${cashAccountName}` : ''}
          </p>
        </div>
        <div style={{ textAlign: 'right' }}>
          <p style={{ margin: 0, fontSize: `${bodyPt * (14 / 9)}pt`, fontWeight: 700 }}>OFFICIAL RECEIPT</p>
          <div style={{ display: 'inline-block', marginTop: '1mm', textAlign: 'left' }}>
            <PrintMetaTable
              size={bodyPt}
              rows={[
                { label: 'NO', value: receipt.document_number ?? '', bold: true },
                { label: 'Date', value: formatDdMmYyyy(receipt.receipt_date) },
                { label: 'Account', value: cashAccountName },
                { label: 'Cheque No', value: chequeNo },
              ]}
            />
          </div>
        </div>
      </div>

      {/* ---------- Table: A/C | Description | Amount (RP) header, then one aggregate data row, then a
          nested Doc Date / Document # / Org. Amt / Paid Amt breakdown sub-table ---------- */}
      <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed', marginTop: '2mm', border: '0.75pt solid #000' }}>
        <colgroup>
          {TABLE1_COLS.map((col) => (
            <col key={col.key} style={{ width: `${col.percent}%` }} />
          ))}
        </colgroup>
        <thead>
          <tr style={{ borderBottom: '0.75pt solid #000' }}>
            {TABLE1_COLS.map((col, index) => (
              <th
                key={col.key}
                style={{
                  textAlign: col.key === 'amount' ? 'right' : 'left',
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
          <tr>
            <td style={{ padding: '1mm 1.5mm', textAlign: 'left', borderRight: '0.75pt solid #000', verticalAlign: 'top' }}>
              {account.code} {account.name.toUpperCase()}
            </td>
            <td style={{ padding: '1mm 1.5mm', textAlign: 'left', borderRight: '0.75pt solid #000', verticalAlign: 'top' }}>
              {account.name.toUpperCase()}, {receivedFromName}, {cashAccountName}
            </td>
            <td style={{ padding: '1mm 1.5mm', textAlign: 'right', verticalAlign: 'top' }}>{formatNum(receipt.total_amount, decimals)}</td>
          </tr>
          {activeItems.length > 0 && (
            <tr>
              <td colSpan={2} style={{ padding: 0, borderRight: '0.75pt solid #000' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed' }}>
                  <colgroup>
                    {SUBTABLE_COLS.map((col) => (
                      <col key={col.key} style={{ width: `${col.percent}%` }} />
                    ))}
                  </colgroup>
                  <thead>
                    <tr style={{ borderTop: '0.75pt solid #000', borderBottom: '0.75pt solid #000' }}>
                      {SUBTABLE_COLS.map((col) => (
                        <th
                          key={col.key}
                          style={{
                            textAlign: col.key === 'org_amt' || col.key === 'paid_amt' ? 'right' : 'left',
                            fontWeight: 700,
                            fontSize: `${bodyPt * (8.8 / 9)}pt`,
                            padding: '1mm 1.5mm',
                          }}
                        >
                          {col.label}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {activeItems.map((item) => (
                      <tr key={item.id}>
                        <td style={{ padding: '1mm 1.5mm', textAlign: 'left' }}>{formatDdMmYyyy(item.accounts_receivable.invoice?.invoice_date)}</td>
                        <td style={{ padding: '1mm 1.5mm', textAlign: 'left' }}>
                          {item.accounts_receivable.invoice?.document_number ?? item.accounts_receivable.reference_number}
                        </td>
                        <td style={{ padding: '1mm 1.5mm', textAlign: 'right' }}>{formatNum(item.accounts_receivable.amount, decimals)}</td>
                        <td style={{ padding: '1mm 1.5mm', textAlign: 'right' }}>{formatNum(item.received_amount, decimals)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </td>
              <td />
            </tr>
          )}
        </tbody>
      </table>

      {/* Pure whitespace, no grid — same shrink-as-rows-grow spacer as Payment Voucher's template. */}
      <div style={{ height: `${detailSpacerMm}mm` }} />

      {/* ---------- Footer: terbilang (kiri) / TOTAL (kanan) ---------- */}
      <div style={{ borderTop: '0.75pt solid #000', paddingTop: '1.3mm', display: 'grid', gridTemplateColumns: '1fr auto', gap: '4mm', alignItems: 'start' }}>
        <p style={{ margin: 0 }}>RP: {terbilangIdrPlain(receipt.total_amount, decimals, '')}</p>
        <p style={{ margin: 0, whiteSpace: 'nowrap' }}>
          <span style={{ fontWeight: 700 }}>TOTAL : RP</span>{' '}
          <span style={{ fontWeight: 700, fontSize: `${bodyPt * (10.5 / 9)}pt` }}>{formatNum(receipt.total_amount, decimals)}</span>
        </p>
      </div>

      {/* ---------- Signature block: 4 equal columns, label above the line ---------- */}
      <div style={{ marginTop: '16.4mm', display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '2mm' }}>
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
