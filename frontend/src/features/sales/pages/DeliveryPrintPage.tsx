import { useState, type ReactNode } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { type PrintOptions } from '@/shared/lib/printOptions'
import { useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { fetchDelivery } from '../api/deliveryApi'

/** DO.pdf shows plain en-US grouping with no decimals for quantities ("150", not "150.00"). */
function formatNum(value: number | string, decimals: number): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

/** delivery_date arrives as a plain YYYY-MM-DD string — split it directly rather than re-parsing through a Date object, which shifts the calendar date in any timezone ahead of UTC (same pitfall dateMath.ts's addDays() already documents). */
function formatDdMmYyyy(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${day}/${month}/${year}`
}

function MetaRow({ label, value, bold }: { label: string; value: ReactNode; bold?: boolean }) {
  return (
    <div className="flex">
      <span className="w-28 shrink-0">{label}</span>
      <span className="shrink-0">:</span>
      <span className={`pl-2 ${bold ? 'font-bold' : ''}`}>{value}</span>
    </div>
  )
}

/**
 * Classic dot-matrix-era layout matching the legacy system's DO print exactly (DO.pdf) — a
 * genuinely different, plainer layout than SalesOrderPrintPage.tsx, not the same header
 * reused: no logo, no Co.Reg.No/TEL/EMAIL, company name+address left-aligned and folded into
 * the meta grid's left column rather than a separate centered block, "1 of 1" as a page
 * indicator in the top-left corner rather than a meta row. Only the print plumbing/CSS is
 * shared (print:hidden toolbar, @page margin:0 + print:p-[12mm] wrapper to suppress the
 * browser's own print header/footer, Times New Roman) and the signature footer's general
 * shape. No pricing/tax/terbilang at all — Delivery carries no pricing authority in this
 * system, and DO.pdf's table is quantities only.
 */
export function DeliveryPrintPage() {
  const { id } = useParams<{ id: string }>()
  const [printOptions, setPrintOptions] = useState<PrintOptions>({
    fontSize: 'medium',
    paperType: 'a4',
    qtyDecimals: 0,
    priceDecimals: 0,
    amountDecimals: 0,
  })
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

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-[12mm]">
      <style>{'@page { size: A4; margin: 0; }'}</style>

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
        className="flex min-h-[27.3cm] flex-col text-black"
        style={{
          fontFamily: '"Times New Roman", "Tinos", "Liberation Serif", serif',
          fontSize: printOptions.fontSize === 'small' ? '11px' : printOptions.fontSize === 'large' ? '15px' : '13px',
        }}
      >
        <div className="flex items-start justify-between">
          <p className="text-xs">1 of 1</p>
          <p className="flex-1 text-center text-lg font-bold">DELIVERY ORDER</p>
          <div className="w-8 shrink-0" />
        </div>

        <div className="mt-2 grid grid-cols-2 gap-4 border-b border-black pb-2">
          <div className="flex flex-col gap-0.5">
            <p className="text-base font-bold">{companyName}</p>
            {printHeaderQuery.data?.address && <p>{printHeaderQuery.data.address}</p>}
            <div className="mt-2 flex flex-col gap-0.5">
              <MetaRow label="Driver" value={delivery.driver ?? ''} />
              <MetaRow label="Fleet" value={delivery.fleet ?? ''} />
              <MetaRow label="Kepada Yth" value={delivery.customer?.customer_name ?? ''} />
            </div>
          </div>
          <div className="flex flex-col gap-0.5">
            <MetaRow label="NO" value={delivery.document_number ?? '—'} bold />
            <MetaRow label="Date" value={formatDdMmYyyy(delivery.delivery_date)} />
            <MetaRow label="SO. No" value={delivery.sales_order?.document_number ?? ''} />
            <MetaRow label="Sales Person" value={delivery.sales_order?.sales_person?.name ?? ''} />
            <MetaRow label="Location" value={delivery.warehouse?.name ?? ''} />
          </div>
        </div>

        <table className="w-full border-collapse text-left">
          <thead>
            <tr className="border-b border-black">
              <th className="py-1 pr-2 font-normal">No</th>
              <th className="py-1 pr-2 font-normal">PKode</th>
              <th className="py-1 pr-2 font-normal">Nama Barang</th>
              <th className="py-1 pr-2 text-right font-normal">Quantity</th>
              <th className="py-1 font-normal">UOM</th>
            </tr>
          </thead>
          <tbody>
            {delivery.items.map((item, index) => (
              <tr key={item.id}>
                <td className="py-1 pr-2 align-top">{index + 1}</td>
                <td className="py-1 pr-2 align-top">{item.item_code}</td>
                <td className="py-1 pr-2 align-top">{item.item_name}</td>
                <td className="py-1 pr-2 text-right align-top">{formatNum(item.qty, printOptions.qtyDecimals)}</td>
                <td className="py-1 align-top">{item.uom}</td>
              </tr>
            ))}
          </tbody>
        </table>

        <div className="flex-1" />

        <hr className="border-black" />
        <p className="text-right">
          {formatNum(totalQty, 0)} {uniformUom}
        </p>

        <div className="mt-4 grid grid-cols-6 gap-2 text-center">
          <p>Tanda Terima,</p>
          <p>Dikeluarkan Oleh,</p>
          <p>Diantar Oleh,</p>
          <p>Diperiksa Oleh,</p>
          <p>Security,</p>
          <p>Hormat Kami,</p>
        </div>
      </div>

      <PrintOptionsDialog open={optionsOpen} onOpenChange={setOptionsOpen} options={printOptions} onChange={setPrintOptions} fields={['qty']} />
    </div>
  )
}
