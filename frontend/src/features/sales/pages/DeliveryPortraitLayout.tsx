import type { CompanyPrintHeader } from '@/features/administration/types'
import type { Delivery } from '../types'
import { DEJAVU_FONT_FACES } from './invoicePrintConstants'
import { A4, A4_ITEM_COLS, formatDdMmYyyy, formatNum, formatQty } from './deliveryPrintConstants'

/** Extracted directly from DeliveryOrder_potrait.pdf's own embedded image (a tighter crop than the
    shared /kalindo-etam-logo.png SO/Tanda Terima use) — see deliveryPrintConstants.ts's own note. */
const DELIVERY_ORDER_LOGO_URL = '/delivery-order-logo.jpg'

/** Bank clause text below is transcribed character-for-character from DeliveryOrder_potrait.pdf's
    own text layer (pdfplumber word extraction), not retyped from memory. */
const BANK_CLAUSE_1 = 'All cheque and payment should be crossed and made payable to'
const BANK_CLAUSE_2 = 'All cash payment must be made directly to Account Department.'
const BANK_CLAUSE_3 =
  'The property of the goods in this bill shall remain with the seller until full payment has been received and the seller shall have a right of entry of seizure to retake possession in the event that full payment is not made on its due date.'

function MetaRow({ label, value, size = 9, bold = false }: { label: string; value: string; size?: number; bold?: boolean }) {
  return (
    <div
      style={{
        display: 'grid',
        gridTemplateColumns: `${A4.metaLabelWidthMm}mm ${A4.metaColonWidthMm}mm 1fr`,
        fontSize: `${size}pt`,
        fontWeight: bold ? 700 : 400,
      }}
    >
      <span>{label}</span>
      <span>:</span>
      {/* minWidth:0 overrides a grid item's default min-width:auto (= its content's own
          intrinsic width) — without it, an unbreakable value like a long document number never
          wraps and instead overflows the column, getting clipped at the page edge (that's what
          was cutting the year off a wrapped NO value). overflowWrap:anywhere then lets it break
          mid-string exactly like the reference PDF's own "DO/KE/8535/0" / "9/2026" wrap. */}
      <span style={{ minWidth: 0, overflowWrap: 'anywhere' }}>{value}</span>
    </div>
  )
}

export interface DeliveryPortraitLayoutProps {
  delivery: Delivery
  companyName: string
  printHeader: CompanyPrintHeader | undefined
  fontFamily: string
  decimalsOn: boolean
  signatureLeftLabel: string
  signatureRightLabel: string
}

/**
 * A4 template — replica of DeliveryOrder_potrait.pdf (logo + centered kop, "E. & O.E" bank-clause
 * footer, dual signature lines). Every mm/pt value comes from deliveryPrintConstants.ts, itself
 * measured off that PDF's own text/vector layers — nothing here is eyeballed.
 *
 * Two data-mapping decisions confirmed against the ticket (not guessed): "Reference 1 #" =
 * sales_order.document_number (the PDF's own sample happens to show the SO number there), and
 * "Reference 2 #" is always blank (Delivery carries no field for it). TOTAL QTY always renders 3
 * decimals regardless of the "Tampilkan Desimal" toggle (which only affects the item table's own
 * Quantity column) — the reference PDF itself shows "50" in the table but "50.000" in TOTAL QTY
 * within the same render, confirming these are independently formatted.
 */
export function DeliveryPortraitLayout({
  delivery,
  companyName,
  printHeader,
  fontFamily,
  decimalsOn,
  signatureLeftLabel,
  signatureRightLabel,
}: DeliveryPortraitLayoutProps) {
  const totalQty = delivery.items.reduce((sum, item) => sum + Number(item.qty), 0)

  return (
    <div
      style={{
        display: 'flex',
        flexDirection: 'column',
        width: `${A4.pageWidthMm}mm`,
        minHeight: `${A4.pageHeightMm}mm`,
        boxSizing: 'border-box',
        padding: A4.marginMm,
        fontFamily,
        color: '#000',
        lineHeight: 1.2,
      }}
    >
      <style>{DEJAVU_FONT_FACES}</style>

      {/* ---------- Kop: logo out-of-flow + teks rata tengah ---------- */}
      <div style={{ position: 'relative' }}>
        <img
          src={DELIVERY_ORDER_LOGO_URL}
          alt={companyName}
          style={{ position: 'absolute', left: `${A4.logoLeftMm}mm`, top: '50%', transform: 'translateY(-50%)', height: `${A4.logoHeightMm}mm`, width: 'auto' }}
        />
        <div style={{ textAlign: 'center' }}>
          <p style={{ margin: 0, fontSize: '15.75pt', fontWeight: 700 }}>{companyName}</p>
          <div style={{ marginTop: '1.2mm' }}>
            {printHeader?.npwp && (
              <p style={{ margin: 0, fontSize: '9pt' }}>
                <span style={{ fontWeight: 700 }}>Co. Reg. No.</span> : {printHeader.npwp}
              </p>
            )}
            {printHeader?.address && <p style={{ margin: 0, fontSize: '9pt' }}>{printHeader.address}</p>}
            <p style={{ margin: 0, fontSize: '9pt' }}>
              TEL : {printHeader?.phone ?? ''}     FAX :
            </p>
            {printHeader?.email && <p style={{ margin: 0, fontSize: '9pt' }}>EMAIL : {printHeader.email}</p>}
          </div>
        </div>
      </div>

      {/* ---------- Judul ---------- */}
      <p style={{ margin: 0, marginTop: '4.5mm', textAlign: 'center', fontSize: '14.25pt', fontWeight: 700 }}>DELIVERY ORDER</p>
      <div style={{ marginTop: '1mm', borderTop: '2.25pt solid #000' }} />

      {/* ---------- Dua kolom: customer (kiri) / meta (kanan) ---------- */}
      <div style={{ display: 'grid', gridTemplateColumns: `${A4.rightColStartMm}mm 1fr`, marginTop: '2.5mm' }}>
        <div>
          <p style={{ margin: 0, fontSize: '9pt', fontWeight: 700 }}>{delivery.customer?.customer_name ?? '—'}</p>
          {delivery.customer?.address && <p style={{ margin: 0, fontSize: '9pt' }}>{delivery.customer.address}</p>}
          <div style={{ marginTop: '14mm', display: 'flex', flexDirection: 'column', gap: '0.85mm' }}>
            <div style={{ display: 'flex', gap: '2mm', fontSize: '9pt' }}>
              <span style={{ fontWeight: 700, width: '8mm' }}>Attn</span>
              <span style={{ fontWeight: 700 }}>:</span>
              <span>{delivery.sales_order?.attention ?? ''}</span>
            </div>
            <div style={{ display: 'flex', gap: '2mm', fontSize: '9pt' }}>
              <span style={{ fontWeight: 700, width: '8mm' }}>Tel</span>
              <span style={{ fontWeight: 700 }}>:</span>
              <span>{delivery.sales_order?.tel ?? delivery.customer?.phone ?? ''}</span>
            </div>
            <div style={{ display: 'flex', gap: '2mm', fontSize: '9pt' }}>
              <span style={{ fontWeight: 700, width: '8mm' }}>Fax</span>
              <span style={{ fontWeight: 700 }}>:</span>
              <span>{delivery.sales_order?.fax ?? ''}</span>
            </div>
          </div>
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1.5mm' }}>
          <MetaRow label="NO" value={delivery.document_number ?? '—'} size={10.5} bold />
          <MetaRow label="Date" value={formatDdMmYyyy(delivery.delivery_date)} />
          <MetaRow label="Reference 1 #" value={delivery.sales_order?.document_number ?? ''} />
          <MetaRow label="Reference 2 #" value="" />
          <MetaRow label="Customer #" value={delivery.customer?.customer_code ?? ''} />
          <MetaRow label="Sales Person" value={delivery.sales_order?.sales_person?.name ?? ''} />
          <MetaRow label="Page" value="1 of 1" />
        </div>
      </div>

      {/* ---------- Tabel item ---------- */}
      <div style={{ flex: '1 0 auto', marginTop: '3mm' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed', fontSize: '10pt' }}>
          <colgroup>
            <col style={{ width: `${A4_ITEM_COLS.no}%` }} />
            <col style={{ width: `${A4_ITEM_COLS.itemNo}%` }} />
            <col style={{ width: `${A4_ITEM_COLS.description}%` }} />
            <col style={{ width: `${A4_ITEM_COLS.qty}%` }} />
            <col style={{ width: `${A4_ITEM_COLS.uom}%` }} />
          </colgroup>
          <thead style={{ display: 'table-header-group' }}>
            <tr style={{ borderTop: '0.75pt solid #000', borderBottom: '0.75pt solid #000' }}>
              <th style={{ textAlign: 'left', fontWeight: 400, padding: '1mm 1.5mm' }}>NO</th>
              <th style={{ textAlign: 'left', fontWeight: 400, padding: '1mm 1.5mm' }}>ITEM NO.</th>
              <th style={{ textAlign: 'left', fontWeight: 400, padding: '1mm 1.5mm' }}>DESCRIPTION</th>
              <th colSpan={2} style={{ textAlign: 'right', fontWeight: 400, padding: '1mm 1.5mm' }}>
                QUANTITYUOM
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

      {/* ---------- Footer: E.&O.E (kiri) / TOTAL QTY (kanan) ---------- */}
      <div style={{ borderTop: '0.75pt solid #000', paddingTop: '2mm' }}>
        <div style={{ display: 'grid', gridTemplateColumns: `${A4.rightColStartMm}mm 1fr` }}>
          <div style={{ paddingRight: '4mm' }}>
            <p style={{ margin: 0, fontSize: '10.5pt', fontWeight: 700, fontStyle: 'italic' }}>E. &amp; O.E</p>
            <ol style={{ margin: 0, marginTop: '1mm', paddingLeft: '4mm', fontSize: '8.25pt' }}>
              <li>
                {BANK_CLAUSE_1}
                <br />
                <span style={{ fontWeight: 700 }}>{companyName}</span>
                <br />
                <span style={{ fontWeight: 700 }}>BCA NO A/C. 0271461312</span>
              </li>
              <li>{BANK_CLAUSE_2}</li>
              <li>{BANK_CLAUSE_3}</li>
            </ol>
          </div>
          <p style={{ margin: 0, fontSize: '11pt', fontWeight: 700 }}>TOTAL QTY : {formatNum(totalQty, 3)}</p>
        </div>

        <p style={{ margin: 0, marginTop: '4mm', fontSize: '9.74pt', fontWeight: 700 }}>For {companyName}</p>

        {/* Dua garis tanda tangan — koordinat absolut persis PDF referensi (kiri: 0.92-44.87mm,
            kanan: 110.70-173.41mm dari tepi konten), label rata tengah terhadap garisnya masing2. */}
        <div style={{ position: 'relative', marginTop: '15mm', height: '6mm' }}>
          <div style={{ position: 'absolute', left: '0.92mm', width: '43.95mm', borderTop: '0.75pt solid #000' }} />
          <p style={{ position: 'absolute', left: '0.92mm', width: '43.95mm', top: '1.5mm', margin: 0, textAlign: 'center', fontSize: '9pt', whiteSpace: 'nowrap' }}>
            {signatureLeftLabel}
          </p>
          <div style={{ position: 'absolute', left: '110.70mm', width: '62.71mm', borderTop: '0.75pt solid #000' }} />
          <p style={{ position: 'absolute', left: '110.70mm', width: '62.71mm', top: '1.5mm', margin: 0, textAlign: 'center', fontSize: '9pt', whiteSpace: 'nowrap' }}>
            {signatureRightLabel}
          </p>
        </div>
      </div>
    </div>
  )
}
