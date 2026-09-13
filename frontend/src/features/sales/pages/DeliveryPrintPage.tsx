import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { PrintMetaTable } from '@/components/shared/PrintMetaTable'
import { loadDeliveryPrintOptions, saveDeliveryPrintOptions, type PrintOptions } from '@/shared/lib/printOptions'
import { useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { fetchDelivery } from '../api/deliveryApi'
import { DEJAVU_FONT_FACES, DEJAVU_FONT_STACK } from './invoicePrintConstants'

/** Same static asset SalesOrderPrintLayout.tsx / TandaTerimaInvoicePrintPage.tsx use — not the admin-configurable company branding logo (which may not be set). */
const KALINDO_ETAM_LOGO_URL = '/kalindo-etam-logo.png'

const SIGNATURE_COLUMNS = ['Tanda Terima,', 'Dikeluarkan Oleh,', 'Diantar Oleh,', 'Diperiksa Oleh,', 'Security,', 'Hormat Kami,']

type DeliveryPaperKey = 'a4' | 'half'

/** Both paper types are 210mm wide (Half is A5 landscape, not A5 portrait) — only the height and
    margins differ. Own div padding (never a real @page margin) so the on-screen preview box is
    always the same size as the printed page, same convention invoicePrintConstants.ts documents
    for Invoice's Portrait layout. */
const PAPER: Record<DeliveryPaperKey, { heightMm: number; paddingMm: string; logoHeightMm: number }> = {
  a4: { heightMm: 297, paddingMm: '10mm 12mm', logoHeightMm: 18 },
  half: { heightMm: 148.5, paddingMm: '6mm 8mm', logoHeightMm: 12 },
}

/** Half is "one step" smaller than A4 across every element, per spec — a flat -1pt map rather than
    a generic scale factor, since nothing here needs to be recomputed at arbitrary sizes. */
const SIZES: Record<DeliveryPaperKey, Record<'pageMark' | 'title' | 'companyName' | 'meta' | 'tableHeader' | 'tableBody' | 'notes' | 'totalQty' | 'signatureCaption', number>> = {
  a4: { pageMark: 8, title: 15, companyName: 13, meta: 8.5, tableHeader: 9, tableBody: 9, notes: 8.5, totalQty: 9.5, signatureCaption: 8.5 },
  half: { pageMark: 7, title: 14, companyName: 12, meta: 7.5, tableHeader: 8, tableBody: 8, notes: 7.5, totalQty: 8.5, signatureCaption: 7.5 },
}

/** DO.pdf shows plain en-US grouping with no decimals for quantities ("150", not "150.00"). */
function formatNum(value: number | string, decimals: number): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

/** OFF -> whole number ("25"), ON -> 3 decimals ("25.000") — formatting only, never changes the underlying qty value. */
function formatQty(value: number | string, decimalsOn: boolean): string {
  return formatNum(value, decimalsOn ? 3 : 0)
}

/** delivery_date arrives as a plain YYYY-MM-DD string — split it directly rather than re-parsing through a Date object, which shifts the calendar date in any timezone ahead of UTC (same pitfall dateMath.ts's addDays() already documents). */
function formatDdMmYyyy(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${day}/${month}/${year}`
}

/** No | PKode | Nama Barang | Quantity | UOM — fixed percentage widths shared by the item table and
    the total-row line below it, so "150 ZAK" always lands under the Quantity/UOM columns regardless
    of how long any item's name happens to be. */
const ITEM_COL_WIDTHS = ['6%', '14%', '44%', '18%', '18%']

/**
 * Delivery Order print — replicates Invoice print's own Print Options pattern (Paper Type: A4/Half,
 * Font Style, Tampilkan Desimal/Logo checkboxes, editable signature labels — all reusing
 * PrintOptionsDialog/printOptions.ts/invoicePrintConstants.ts as-is, no parallel implementation) on
 * top of the classic clouderp-style DO body: two aligned meta columns (PrintMetaTable, shared with
 * Invoice's Portrait layout), a header-rule-only item table that repeats on every page, and a
 * 6-column signature footer where only the two outer columns get a signature line.
 *
 * Unlike Invoice, Delivery carries no pricing/tax authority in this system — the item table is
 * quantities only, and "Tampilkan Desimal" here controls Qty's own decimal precision (0 vs 3),
 * not a totals-box toggle (Delivery has no totals box).
 */
export function DeliveryPrintPage() {
  const { id } = useParams<{ id: string }>()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(() => ({
    fontSize: 'medium',
    paperType: 'a4',
    qtyDecimals: 0,
    priceDecimals: 0,
    amountDecimals: 0,
    showDecimalTotals: false,
    showLogo: true,
    signatureLeftLabel: 'AUTHORISED SIGNATURE',
    signatureRightLabel: 'AUTHORISED SIGNATURE',
    ...loadDeliveryPrintOptions(),
  }))
  const handlePrintOptionsChange = (next: PrintOptions) => {
    setPrintOptions(next)
    saveDeliveryPrintOptions(next)
  }
  const [optionsOpen, setOptionsOpen] = useState(false)

  const deliveryQuery = useQuery({
    queryKey: ['deliveries', id],
    queryFn: () => fetchDelivery(id!),
  })
  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()

  if (deliveryQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const delivery = deliveryQuery.data
  if (!delivery) return null

  const companyName = brandingQuery.data?.name ?? 'PT. KALINDO ETAM'
  const totalQty = delivery.items.reduce((sum, item) => sum + Number(item.qty), 0)
  const uniformUom = delivery.items.length > 0 && delivery.items.every((item) => item.uom === delivery.items[0].uom) ? delivery.items[0].uom : ''
  // Same source the Delivery form itself uses (DeliveryEditorPage.tsx) — the Delivery's own
  // remarks, already seeded from the Sales Order's remarks at creation time and editable from
  // there; the Sales Order fallback only covers a Delivery that somehow never got that default
  // (e.g. a pre-existing record). Hidden entirely when empty — no "Notes: -" clutter.
  const notes = delivery.remarks || delivery.sales_order?.remarks || ''

  const isHalf = printOptions.paperType === 'half'
  const paperKey: DeliveryPaperKey = isHalf ? 'half' : 'a4'
  const paper = PAPER[paperKey]
  const sizes = SIZES[paperKey]
  const decimalsOn = printOptions.showDecimalTotals ?? false
  const showLogo = printOptions.showLogo ?? true
  const fontFamily = printOptions.fontFamily ?? DEJAVU_FONT_STACK
  const signatureLeftLabel = printOptions.signatureLeftLabel ?? 'AUTHORISED SIGNATURE'
  const signatureRightLabel = printOptions.signatureRightLabel ?? 'AUTHORISED SIGNATURE'

  return (
    <div
      className="mx-auto bg-white p-6 text-foreground shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:p-0 print:shadow-none"
      style={{ width: '210mm', minHeight: `${paper.heightMm}mm` }}
    >
      {/* margin: 0 on @page suppresses the browser's own print header/footer chrome — document
          margins come from the content div's own padding below instead, so screen and print
          always agree on paper size (same convention as InvoicePrintPage.tsx). */}
      <style>
        {`@page { size: 210mm ${paper.heightMm}mm; margin: 0; } @media print { * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }`}
      </style>

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Delivery Order Print Preview</h1>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={() => setOptionsOpen(true)}>
            <Settings2 className="size-4" />
            Print Options
          </Button>
          <Button onClick={() => window.print()}>
            <Printer className="size-4" />
            Print
          </Button>
        </div>
      </div>

      <div
        style={{
          display: 'flex',
          flexDirection: 'column',
          width: '100%',
          minHeight: `${paper.heightMm}mm`,
          boxSizing: 'border-box',
          padding: paper.paddingMm,
          fontFamily,
          color: '#000',
        }}
      >
        <style>{DEJAVU_FONT_FACES}</style>

        <div className="flex items-start justify-between">
          <p style={{ fontSize: `${sizes.pageMark}pt` }}>1 of 1</p>
          <p className="flex-1 text-center" style={{ fontSize: `${sizes.title}pt`, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.5px' }}>
            Delivery Order
          </p>
          <div style={{ width: '8mm' }} />
        </div>

        <div className="mt-2 grid grid-cols-2 gap-4 border-b border-black pb-2">
          <div className="flex flex-col gap-0.5">
            <div className="flex items-start gap-2">
              {showLogo && (
                <img
                  src={KALINDO_ETAM_LOGO_URL}
                  alt={companyName}
                  style={{ height: `${paper.logoHeightMm}mm`, width: 'auto', objectFit: 'contain', flexShrink: 0 }}
                />
              )}
              <div className="flex flex-col gap-0.5">
                <p style={{ fontSize: `${sizes.companyName}pt`, fontWeight: 700 }}>{companyName}</p>
                {printHeaderQuery.data?.address && <p style={{ fontSize: `${sizes.meta}pt` }}>{printHeaderQuery.data.address}</p>}
              </div>
            </div>
            <div className="mt-2">
              <PrintMetaTable
                size={sizes.meta}
                rows={[
                  { label: 'Driver', value: delivery.driver ?? '' },
                  { label: 'Fleet', value: delivery.fleet ?? '' },
                  {
                    label: 'Kepada Yth',
                    value: (
                      <div className="flex flex-col">
                        {delivery.customer?.customer_name && <span style={{ fontWeight: 700 }}>{delivery.customer.customer_name}</span>}
                        {delivery.customer?.phone && <span>{delivery.customer.phone}</span>}
                        {delivery.customer?.address && <span>{delivery.customer.address}</span>}
                      </div>
                    ),
                  },
                ]}
              />
            </div>
          </div>
          <div>
            <PrintMetaTable
              size={sizes.meta}
              rows={[
                { label: 'NO', value: delivery.document_number ?? '—', valueBold: true },
                { label: 'Date', value: formatDdMmYyyy(delivery.delivery_date) },
                { label: 'SO. No', value: delivery.sales_order?.document_number ?? '' },
                { label: 'Sales Person', value: delivery.sales_order?.sales_person?.name ?? '' },
                { label: 'Location', value: delivery.warehouse?.name ?? '' },
              ]}
            />
          </div>
        </div>

        {/* flex: 1 0 auto stretches the table to fill remaining page height on a short delivery
            (reserving the empty space before the total row, per spec) while letting a long one
            grow past one page and paginate naturally — same technique InvoicePortraitLayout uses. */}
        <div style={{ flex: '1 0 auto', marginTop: '3mm' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed', fontSize: `${sizes.tableBody}pt` }}>
            <colgroup>
              {ITEM_COL_WIDTHS.map((width) => (
                <col key={width} style={{ width }} />
              ))}
            </colgroup>
            {/* table-header-group repeats this row on every printed page for a multi-page delivery. */}
            <thead style={{ display: 'table-header-group' }}>
              <tr style={{ borderTop: '0.53mm solid #000', borderBottom: '0.53mm solid #000' }}>
                <th style={{ textAlign: 'center', fontWeight: 700, padding: '1mm 1.5mm', fontSize: `${sizes.tableHeader}pt` }}>No</th>
                <th style={{ textAlign: 'left', fontWeight: 700, padding: '1mm 1.5mm', fontSize: `${sizes.tableHeader}pt` }}>PKode</th>
                <th style={{ textAlign: 'left', fontWeight: 700, padding: '1mm 1.5mm', fontSize: `${sizes.tableHeader}pt` }}>Nama Barang</th>
                <th style={{ textAlign: 'right', fontWeight: 700, padding: '1mm 1.5mm', fontSize: `${sizes.tableHeader}pt` }}>Quantity</th>
                <th style={{ textAlign: 'left', fontWeight: 700, padding: '1mm 1.5mm', fontSize: `${sizes.tableHeader}pt` }}>UOM</th>
              </tr>
            </thead>
            <tbody>
              {delivery.items.map((item, index) => (
                <tr key={item.id} style={{ breakInside: 'avoid' }}>
                  <td style={{ textAlign: 'center', verticalAlign: 'top', padding: '1mm 1.5mm' }}>{index + 1}</td>
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
          <div className="mb-2">
            <PrintMetaTable size={sizes.notes} rows={[{ label: 'Notes', value: notes }]} />
          </div>
        )}

        {/* Total-qty row + signature block travel together so they can never split across a page. */}
        <div style={{ breakInside: 'avoid' }}>
          <div
            style={{
              borderTop: '0.53mm solid #000',
              display: 'flex',
              justifyContent: 'flex-end',
              gap: '2mm',
              paddingTop: '1mm',
              fontSize: `${sizes.totalQty}pt`,
              fontWeight: 700,
            }}
          >
            <span>{formatQty(totalQty, decimalsOn)}</span>
            <span>{uniformUom}</span>
          </div>

          <div className="mt-4 grid grid-cols-6 gap-2 text-center" style={{ fontSize: `${sizes.signatureCaption}pt` }}>
            {SIGNATURE_COLUMNS.map((caption, index) => {
              const isOuter = index === 0 || index === SIGNATURE_COLUMNS.length - 1
              const signatureLabel = index === 0 ? signatureLeftLabel : signatureRightLabel
              return (
                <div key={caption} className="flex flex-col items-center gap-1">
                  {isOuter && (
                    <>
                      <div style={{ height: '18mm' }} />
                      <div style={{ width: '85%', borderTop: '0.3mm solid #000' }} />
                      {signatureLabel && <p>({signatureLabel})</p>}
                    </>
                  )}
                  <p>{caption}</p>
                </div>
              )
            })}
          </div>
        </div>
      </div>

      <PrintOptionsDialog
        open={optionsOpen}
        onOpenChange={setOptionsOpen}
        options={printOptions}
        onChange={handlePrintOptionsChange}
        fields={[]}
        showPaperType
        paperTypeOptions={['a4', 'half']}
        showFontSize={false}
        showFontFamily
        defaultFontFamily={DEJAVU_FONT_STACK}
        showDecimalToggle
        showLogo
        showSignatureLabels
      />
    </div>
  )
}
