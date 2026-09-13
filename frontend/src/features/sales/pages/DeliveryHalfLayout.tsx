import type { ReactNode } from 'react'
import { DEJAVU_FONT_FACES } from './invoicePrintConstants'
import { HALF, HALF_ITEM_COLS, formatDdMmYyyy, formatQty } from './deliveryPrintConstants'
import type { Delivery } from '../types'

const SIGNATURE_COLUMNS = ['Tanda Terima,', 'Dikeluarkan Oleh,', 'Diantar Oleh,', 'Diperiksa Oleh,', 'Security,', 'Hormat Kami,']

function LeftMetaRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: `${HALF.leftLabelWidthMm}mm ${HALF.leftColonWidthMm}mm 1fr`, fontSize: '9pt' }}>
      <span>{label}</span>
      <span>:</span>
      {/* minWidth:0 overrides a grid item's default min-width:auto (its content's own intrinsic
          width) so a long unbreakable value actually wraps within the column instead of
          overflowing and getting clipped at the page edge — same fix as A4's own MetaRow. */}
      <span style={{ minWidth: 0, overflowWrap: 'anywhere' }}>{value}</span>
    </div>
  )
}

function RightMetaRow({ label, value }: { label: string; value: string }) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: `${HALF.rightLabelWidthMm}mm ${HALF.rightColonWidthMm}mm 1fr`, fontSize: '9pt' }}>
      <span>{label}</span>
      <span>:</span>
      <span style={{ minWidth: 0, overflowWrap: 'anywhere' }}>{value}</span>
    </div>
  )
}

export interface DeliveryHalfLayoutProps {
  delivery: Delivery
  companyName: string
  companyAddress: string | undefined
  fontFamily: string
  decimalsOn: boolean
}

/**
 * Half template — replica of DeliveryOrder_lanscape.pdf (no logo, "1 of 1" top-left, plain 6-column
 * signature caption row with no lines/labels). Every mm/pt value comes from
 * deliveryPrintConstants.ts, measured off that PDF's own text/vector layers.
 *
 * Two structural corrections confirmed against the PDF itself (not the ticket's own guess): a thin
 * rule DOES exist below the item-table header (the ticket said there shouldn't be one), and TOTAL
 * QTY here (unlike A4's) follows the "Tampilkan Desimal" toggle same as the item rows.
 */
export function DeliveryHalfLayout({ delivery, companyName, companyAddress, fontFamily, decimalsOn }: DeliveryHalfLayoutProps) {
  const totalQty = delivery.items.reduce((sum, item) => sum + Number(item.qty), 0)
  const uniformUom = delivery.items.length > 0 && delivery.items.every((item) => item.uom === delivery.items[0].uom) ? delivery.items[0].uom : ''
  const notes = delivery.remarks || delivery.sales_order?.remarks || ''

  return (
    <div
      style={{
        display: 'flex',
        flexDirection: 'column',
        width: `${HALF.pageWidthMm}mm`,
        minHeight: `${HALF.pageHeightMm}mm`,
        boxSizing: 'border-box',
        padding: HALF.marginMm,
        fontFamily,
        color: '#000',
        lineHeight: 1.15,
      }}
    >
      <style>{DEJAVU_FONT_FACES}</style>

      {/* ---------- Baris atas: "1 of 1" kiri, "DELIVERY ORDER" tengah terhadap konten ---------- */}
      <div style={{ display: 'flex', alignItems: 'baseline' }}>
        <span style={{ width: '12mm', fontSize: '9pt' }}>1 of 1</span>
        <p style={{ flex: 1, margin: 0, textAlign: 'center', fontSize: '16pt', fontWeight: 700 }}>DELIVERY ORDER</p>
        <span style={{ width: '12mm' }} />
      </div>

      {/* ---------- Nama perusahaan + alamat, rata kiri ---------- */}
      <p style={{ margin: 0, marginTop: '2mm', fontSize: '14pt', fontWeight: 700 }}>{companyName}</p>
      {companyAddress && (
        <p style={{ margin: 0, fontSize: '9pt' }}>{companyAddress}</p>
      )}

      {/* ---------- Dua kolom meta ---------- */}
      <div style={{ display: 'grid', gridTemplateColumns: `${HALF.rightColStartMm}mm 1fr`, marginTop: '4mm' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1.06mm' }}>
          <LeftMetaRow label="Driver" value={delivery.driver ?? ''} />
          <LeftMetaRow label="Fleet" value={delivery.fleet ?? ''} />
          <LeftMetaRow
            label="Kepada Yth"
            value={
              <div style={{ display: 'flex', flexDirection: 'column' }}>
                <span>{delivery.customer?.customer_name ?? ''}</span>
                {delivery.customer?.phone && <span>{delivery.customer.phone}</span>}
                {delivery.customer?.address && <span style={{ fontSize: '8pt' }}>{delivery.customer.address}</span>}
              </div>
            }
          />
        </div>
        <div style={{ display: 'flex', flexDirection: 'column' }}>
          <RightMetaRow label="NO" value={delivery.document_number ?? '—'} />
          <RightMetaRow label="Date" value={formatDdMmYyyy(delivery.delivery_date)} />
          <RightMetaRow label="SO. No" value={delivery.sales_order?.document_number ?? ''} />
          <RightMetaRow label="Sales Person" value={delivery.sales_order?.sales_person?.name ?? ''} />
          <RightMetaRow label="Location" value={delivery.warehouse?.name ?? ''} />
        </div>
      </div>

      {/* ---------- Garis ganda + tabel item ---------- */}
      <div style={{ marginTop: '2mm', borderTop: '1mm solid #000' }} />
      <div style={{ marginTop: '2.86mm', borderTop: '0.5pt solid #000' }} />
      <div style={{ flex: '1 0 auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed', fontSize: '10pt' }}>
          <colgroup>
            <col style={{ width: `${HALF_ITEM_COLS.no}%` }} />
            <col style={{ width: `${HALF_ITEM_COLS.pkode}%` }} />
            <col style={{ width: `${HALF_ITEM_COLS.namaBarang}%` }} />
            <col style={{ width: `${HALF_ITEM_COLS.qty}%` }} />
            <col style={{ width: `${HALF_ITEM_COLS.uom}%` }} />
          </colgroup>
          {/* table-header-group repeats this row on every printed page for a multi-page delivery. */}
          <thead style={{ display: 'table-header-group' }}>
            <tr style={{ borderBottom: '0.5pt solid #000' }}>
              <th style={{ textAlign: 'left', fontWeight: 400, padding: '1mm 1.5mm' }}>No</th>
              <th style={{ textAlign: 'left', fontWeight: 400, padding: '1mm 1.5mm' }}>PKode</th>
              <th style={{ textAlign: 'left', fontWeight: 400, padding: '1mm 1.5mm' }}>Nama Barang</th>
              <th colSpan={2} style={{ textAlign: 'right', fontWeight: 400, padding: '1mm 1.5mm' }}>
                QuantityUOM
              </th>
            </tr>
          </thead>
          <tbody>
            {delivery.items.map((item, index) => (
              <tr key={item.id} style={{ breakInside: 'avoid' }}>
                <td style={{ textAlign: 'left', verticalAlign: 'top', padding: '1mm 1.5mm' }}>{index + 1}</td>
                <td style={{ textAlign: 'left', verticalAlign: 'top', padding: '1mm 1.5mm' }}>{item.item_code}</td>
                <td style={{ textAlign: 'left', verticalAlign: 'top', padding: '1mm 1.5mm' }}>{item.item_name}</td>
                <td style={{ textAlign: 'right', verticalAlign: 'top', padding: '1mm 1.5mm' }}>{formatQty(item.qty, decimalsOn)}</td>
                <td style={{ textAlign: 'left', verticalAlign: 'top', padding: '1mm 1.5mm' }}>{item.uom}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {notes && (
        <div style={{ marginBottom: '1mm', fontSize: '9pt' }}>
          <span>Notes : {notes}</span>
        </div>
      )}

      {/* ---------- Garis tebal penutup + total qty ---------- */}
      <div style={{ breakInside: 'avoid' }}>
        <div style={{ borderTop: '1mm solid #000', paddingTop: '1mm', textAlign: 'right', fontSize: '9pt' }}>
          {formatQty(totalQty, decimalsOn)} {uniformUom}
        </div>

        {/* ---------- Baris 6 label tanda tangan, satu baris, rata kiri per kolom ---------- */}
        <div style={{ marginTop: '3mm', display: 'grid', gridTemplateColumns: 'repeat(6, 1fr)', fontSize: '9pt' }}>
          {SIGNATURE_COLUMNS.map((caption) => (
            <span key={caption} style={{ whiteSpace: 'nowrap' }}>
              {caption}
            </span>
          ))}
        </div>

        {/* Blank space reserved for a real pen signature under each label — measured directly off
            DeliveryOrder_lanscape.pdf itself (caption row to the page's own bottom margin is
            ~19mm there); the flex:1 0 auto table above already fills whatever's left of the page,
            so without this explicit reservation that gap collapses to nothing. */}
        <div style={{ height: '19mm' }} />
      </div>
    </div>
  )
}
