import type { CSSProperties, ReactNode } from 'react'
import { PrintMetaTable } from '@/components/shared/PrintMetaTable'
import type { CompanyPrintHeader } from '@/features/administration/types'
import { terbilangUsd } from '@/shared/lib/numberToWords'
import { DEJAVU_FONT_FACES } from '../pages/invoicePrintConstants'
import {
  BANK_CLAUSE_1,
  BANK_CLAUSE_2,
  BANK_CLAUSE_3,
  FONT_PT,
  ITEM_COLS,
  LOGO_HEIGHT_MM,
  LOGO_LEFT_MM,
  LOGO_URL,
  MARGIN_MM,
  PAGE_HEIGHT_MM,
  PAGE_WIDTH_MM,
  RIGHT_COL_START_MM,
  SIGNATURE,
  TOTALS_BOX,
  formatDdMmYyyy,
  formatNum,
} from '../pages/salesOrderPrintConstants'
import type { SalesOrder } from '../types'

function renderCell(key: string, item: SalesOrder['items'][number], index: number, decimalsOn: boolean): ReactNode {
  // Per-line now — each line's own tax_amount (already resolved server-side), not a document-wide
  // rate, since different lines can carry different taxes.
  const inclusiveAmount = Number(item.amount) + Number(item.tax_amount)
  switch (key) {
    case 'no':
      return index + 1
    case 'itemNo':
      return item.item_code ?? ''
    case 'description':
      return item.item_name ?? ''
    case 'qty':
      return formatNum(item.qty, decimalsOn ? 3 : 0)
    case 'uom':
      return item.uom ?? ''
    case 'unitPrice':
      return formatNum(item.rate, decimalsOn ? 2 : 0)
    case 'amount':
      return formatNum(inclusiveAmount, decimalsOn ? 2 : 0)
    default:
      return ''
  }
}

interface SalesOrderPrintLayoutProps {
  salesOrder: SalesOrder
  companyName: string
  printHeader: CompanyPrintHeader | undefined
  fontFamily: string
  decimalsOn: boolean
  signatureLeftLabel: string
  signatureRightLabel: string
  /** Extra classes on the outer page div — the bulk-print page adds `print:break-before-page` to every copy after the first. */
  className?: string
}

/**
 * Classic dot-matrix-era SO.pdf replica — every mm/pt value comes from salesOrderPrintConstants.ts,
 * itself measured off SalesOrder.pdf's own text/vector layers, not eyeballed. Shared by
 * SalesOrderPrintPage (single document) and SalesOrderBulkPrintPage (N stacked copies).
 *
 * "Tampilkan Desimal" (decimalsOn) drives QTY/U.PRICE/AMOUNT in the item table AND "Amount
 * Excluding Tax"/"Total Amount Due" in the totals box identically (OFF: 0 decimals; ON: QTY 3,
 * money 2) — "Add Total Tax Amount" is the one deliberate exception, always 2 decimals regardless
 * of the toggle, confirmed directly off the PDF (its own sample prints "733,333.26" in the exact
 * same render where every other number shows 0 decimals).
 */
export function SalesOrderPrintLayout({
  salesOrder,
  companyName,
  printHeader,
  fontFamily,
  decimalsOn,
  signatureLeftLabel,
  signatureRightLabel,
  className,
}: SalesOrderPrintLayoutProps) {
  const totalsDecimals = decimalsOn ? 2 : 0

  return (
    <div
      className={className}
      style={{
        display: 'flex',
        flexDirection: 'column',
        width: `${PAGE_WIDTH_MM}mm`,
        minHeight: `${PAGE_HEIGHT_MM}mm`,
        boxSizing: 'border-box',
        padding: MARGIN_MM,
        fontFamily,
        color: '#000',
        lineHeight: 1.2,
      }}
    >
      <style>{DEJAVU_FONT_FACES}</style>

      {/* ---------- Kop: logo out-of-flow + teks rata tengah penuh ---------- */}
      <div style={{ position: 'relative' }}>
        <img
          src={LOGO_URL}
          alt={companyName}
          style={{ position: 'absolute', left: `${LOGO_LEFT_MM}mm`, top: '50%', transform: 'translateY(-50%)', height: `${LOGO_HEIGHT_MM}mm`, width: 'auto' }}
        />
        <div style={{ textAlign: 'center' }}>
          <p style={{ margin: 0, fontSize: `${FONT_PT.companyName}pt`, fontWeight: 700 }}>{companyName}</p>
          <div style={{ marginTop: '1.2mm' }}>
            {printHeader?.npwp && (
              <p style={{ margin: 0, fontSize: `${FONT_PT.kopBody}pt` }}>
                <span style={{ fontWeight: 700 }}>Co. Reg. No.</span> : {printHeader.npwp}
              </p>
            )}
            {printHeader?.address && <p style={{ margin: 0, fontSize: `${FONT_PT.kopBody}pt` }}>{printHeader.address}</p>}
            {printHeader?.phone && <p style={{ margin: 0, fontSize: `${FONT_PT.kopBody}pt` }}>TEL : {printHeader.phone}</p>}
            {printHeader?.email && <p style={{ margin: 0, fontSize: `${FONT_PT.kopBody}pt` }}>EMAIL : {printHeader.email}</p>}
          </div>
        </div>
      </div>

      {/* ---------- Judul ---------- */}
      <p style={{ margin: 0, marginTop: '4.5mm', textAlign: 'center', fontSize: `${FONT_PT.title}pt`, fontWeight: 700 }}>SALES ORDER</p>
      <div style={{ marginTop: '1mm', borderTop: '2.25pt solid #000' }} />

      {/* ---------- Dua kolom: customer (kiri) / meta (kanan) ---------- */}
      <div style={{ display: 'grid', gridTemplateColumns: `${RIGHT_COL_START_MM}mm 1fr`, marginTop: '2.5mm' }}>
        <div>
          <p style={{ margin: 0, fontSize: `${FONT_PT.metaBody}pt`, fontWeight: 700 }}>{salesOrder.customer?.customer_name ?? '—'}</p>
          {salesOrder.customer?.address && <p style={{ margin: '2mm 0 0', fontSize: `${FONT_PT.metaBody}pt` }}>{salesOrder.customer.address}</p>}
          <div style={{ marginTop: '2mm' }}>
            <PrintMetaTable
              size={FONT_PT.metaBody}
              rows={[
                { label: 'Attn', value: salesOrder.attention ?? '', bold: true, valueBold: false },
                { label: 'Tel', value: salesOrder.tel ?? salesOrder.customer?.phone ?? '', bold: true, valueBold: false },
                { label: 'Fax', value: salesOrder.fax ?? '', bold: true, valueBold: false },
              ]}
            />
          </div>
        </div>
        <div>
          <PrintMetaTable
            size={FONT_PT.metaBody}
            rows={[
              { label: 'NO', value: salesOrder.document_number ?? '—', bold: true, size: FONT_PT.metaNo },
              { label: 'Date', value: formatDdMmYyyy(salesOrder.order_date) },
              { label: 'Reference 1 #', value: salesOrder.reference ?? '' },
              { label: 'Reference 2 #', value: '' },
              { label: 'Payment Terms', value: salesOrder.terms_of_payment?.name ?? '' },
              { label: 'Customer #', value: salesOrder.customer?.customer_code ?? '' },
              { label: 'Sales Person', value: salesOrder.sales_person?.name ?? '' },
              { label: 'Page', value: '1 of 1' },
            ]}
          />
        </div>
      </div>

      {/* ---------- Tabel item ---------- */}
      <div style={{ flex: '1 0 auto', marginTop: '3mm' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed', fontSize: `${FONT_PT.tableBody}pt` }}>
          <colgroup>
            {ITEM_COLS.map((col) => (
              <col key={col.key} style={{ width: `${col.percent}%` }} />
            ))}
          </colgroup>
          {/* table-header-group repeats this row on every printed page for a multi-page order. */}
          <thead style={{ display: 'table-header-group' }}>
            <tr style={{ borderTop: '0.75pt solid #000', borderBottom: '0.75pt solid #000' }}>
              {ITEM_COLS.map((col) => (
                <th key={col.key} style={{ textAlign: col.align, fontWeight: 400, padding: '1mm 1.5mm', fontSize: `${FONT_PT.tableHeader}pt` }}>
                  {col.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {salesOrder.items.map((item, index) => (
              <tr key={item.id} style={{ breakInside: 'avoid' }}>
                {ITEM_COLS.map((col) => (
                  <td
                    key={col.key}
                    style={{
                      textAlign: col.align,
                      verticalAlign: 'top',
                      padding: '1mm 1.5mm',
                      whiteSpace: col.key === 'description' ? 'normal' : 'nowrap',
                      overflowWrap: col.key === 'description' ? 'break-word' : undefined,
                    }}
                  >
                    {renderCell(col.key, item, index, decimalsOn)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* ---------- Jumlah dalam huruf ---------- */}
      <p style={{ margin: 0, fontSize: `${FONT_PT.terbilang}pt` }}>RP : {terbilangUsd(salesOrder.grand_total)}</p>
      <div style={{ marginTop: '2.5mm', borderTop: '0.75pt solid #000' }} />

      {/* ---------- Footer: E.&O.E (kiri) / kotak total (kanan) ---------- */}
      <div style={{ marginTop: '2mm', display: 'grid', gridTemplateColumns: `${RIGHT_COL_START_MM}mm 1fr`, gap: '4mm' }}>
        <div>
          <p style={{ margin: 0, fontSize: `${FONT_PT.eoeTitle}pt`, fontWeight: 700, fontStyle: 'italic' }}>E. &amp; O.E</p>
          {/* Numbered manually (own number column, no period) rather than a native <ol> — <ol>
              always renders "1." with a trailing period, but SalesOrder.pdf's own numbering is
              bare "1"/"2"/"3". */}
          <div style={{ marginTop: '1mm', display: 'flex', flexDirection: 'column', gap: '1mm', fontSize: `${FONT_PT.eoeBody}pt` }}>
            <div style={{ display: 'flex', gap: '1.5mm' }}>
              <span>1</span>
              <span>
                {BANK_CLAUSE_1}
                <br />
                <span style={{ fontWeight: 700 }}>{companyName}</span>
                <br />
                <span style={{ fontWeight: 700 }}>BCA NO A/C. 0271461312</span>
              </span>
            </div>
            <div style={{ display: 'flex', gap: '1.5mm' }}>
              <span>2</span>
              <span>{BANK_CLAUSE_2}</span>
            </div>
            <div style={{ display: 'flex', gap: '1.5mm' }}>
              <span>3</span>
              <span>{BANK_CLAUSE_3}</span>
            </div>
          </div>
          <p style={{ margin: 0, marginTop: '3mm', fontSize: `${FONT_PT.forCompany}pt`, fontWeight: 700 }}>For {companyName}</p>
        </div>

        <div style={{ alignSelf: 'start', border: `${TOTALS_BOX.borderMm}mm solid #000` }}>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <colgroup>
              <col style={{ width: `${TOTALS_BOX.labelColPercent}%` }} />
              <col style={{ width: `${TOTALS_BOX.rpColPercent}%` }} />
              <col style={{ width: `${TOTALS_BOX.amountColPercent}%` }} />
            </colgroup>
            <tbody>
              <tr>
                <td style={{ ...totalsCellStyle, fontStyle: 'italic' }}>Amount Excluding Tax</td>
                <td style={{ ...totalsCellStyle, fontStyle: 'italic' }}>RP</td>
                <td style={{ ...totalsCellStyle, fontStyle: 'italic', textAlign: 'right' }}>{formatNum(salesOrder.total_amount, totalsDecimals)}</td>
              </tr>
              <tr>
                <td style={{ ...totalsCellStyle, fontStyle: 'italic' }}>Add Total Tax Amount</td>
                <td style={{ ...totalsCellStyle, fontStyle: 'italic' }}>RP</td>
                {/* Always 2 decimals regardless of the toggle — see this component's own doc comment. */}
                <td style={{ ...totalsCellStyle, fontStyle: 'italic', textAlign: 'right' }}>{formatNum(salesOrder.tax_amount, 2)}</td>
              </tr>
              <tr>
                <td colSpan={3} style={{ height: `${TOTALS_BOX.blankRowHeightMm}mm`, padding: 0 }} />
              </tr>
              <tr style={{ borderTop: '0.75pt solid #000' }}>
                <td style={totalsCellStyle}>Total Amount Due</td>
                <td style={totalsCellStyle}>RP</td>
                <td style={{ ...totalsCellStyle, textAlign: 'right', fontSize: `${FONT_PT.totalsBoxValueFinal}pt` }}>
                  {formatNum(salesOrder.grand_total, totalsDecimals)}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      {/* ---------- Dua garis tanda tangan ---------- */}
      <div style={{ position: 'relative', marginTop: `${SIGNATURE.gapAboveLineMm}mm`, height: '6mm' }}>
        <div style={{ position: 'absolute', left: `${SIGNATURE.leftStartMm}mm`, width: `${SIGNATURE.leftWidthMm}mm`, borderTop: '0.75pt solid #000' }} />
        <p
          style={{
            position: 'absolute',
            left: `${SIGNATURE.leftStartMm}mm`,
            width: `${SIGNATURE.leftWidthMm}mm`,
            top: '1mm',
            margin: 0,
            textAlign: 'center',
            fontSize: `${FONT_PT.signatureLabel}pt`,
            whiteSpace: 'nowrap',
          }}
        >
          {signatureLeftLabel}
        </p>
        <div style={{ position: 'absolute', left: `${SIGNATURE.rightStartMm}mm`, width: `${SIGNATURE.rightWidthMm}mm`, borderTop: '0.75pt solid #000' }} />
        <p
          style={{
            position: 'absolute',
            left: `${SIGNATURE.rightStartMm}mm`,
            width: `${SIGNATURE.rightWidthMm}mm`,
            top: '1mm',
            margin: 0,
            textAlign: 'center',
            fontSize: `${FONT_PT.signatureLabel}pt`,
            whiteSpace: 'nowrap',
          }}
        >
          {signatureRightLabel}
        </p>
      </div>
    </div>
  )
}

const totalsCellStyle: CSSProperties = {
  fontSize: `${FONT_PT.totalsBoxLabel}pt`,
  fontWeight: 700,
  padding: `${TOTALS_BOX.rowPaddingVerticalMm}mm ${TOTALS_BOX.rowPaddingHorizontalMm}mm`,
}
