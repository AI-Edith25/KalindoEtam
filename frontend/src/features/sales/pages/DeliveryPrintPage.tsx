import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { formatDate } from '@/lib/utils'
import {
  defaultPrintOptions,
  formatQty,
  loadPaperTypePreference,
  PRINT_FONT_SIZE_PX,
  PRINT_PAPER_PAGE_CSS,
  savePaperTypePreference,
  type PrintOptions,
} from '@/shared/lib/printOptions'
import { useCompanyBranding } from '@/features/administration/hooks/useCompany'
import { fetchDelivery } from '../api/deliveryApi'

/**
 * Packing-list style (quantities only, no pricing, since Delivery carries
 * no pricing authority in this system). Same bordered "cetak" look and
 * pre-print options as InvoicePrintPage — including Paper Type (A4 default,
 * unaffected; Continuous 9.5"x11" narrows the live preview and adds a
 * `<style>@page{...}</style>` so the real print output follows suit).
 * Only the Decimal Qty control applies here. Not a PDF — @media print CSS +
 * the browser's native print dialog (window.print()).
 */
export function DeliveryPrintPage() {
  const { id } = useParams<{ id: string }>()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(() => ({
    ...defaultPrintOptions,
    paperType: loadPaperTypePreference(),
  }))
  const [optionsOpen, setOptionsOpen] = useState(false)

  const handlePrintOptionsChange = (next: PrintOptions) => {
    setPrintOptions(next)
    savePaperTypePreference(next.paperType)
  }

  const deliveryQuery = useQuery({
    queryKey: ['deliveries', id],
    queryFn: () => fetchDelivery(id!),
  })
  const brandingQuery = useCompanyBranding()

  if (deliveryQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const delivery = deliveryQuery.data
  if (!delivery) return null

  const compact = printOptions.paperType === 'continuous'
  const pageCss = PRINT_PAPER_PAGE_CSS[printOptions.paperType]

  return (
    <div
      className={`mx-auto flex flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0 ${compact ? 'max-w-[9.5in]' : 'max-w-3xl'}`}
    >
      {pageCss && <style>{pageCss}</style>}

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Delivery Print Preview</h1>
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

      <div className="border-2 border-foreground/80" style={{ fontSize: PRINT_FONT_SIZE_PX[printOptions.fontSize] }}>
        <div className={`flex items-start justify-between border-b-2 border-foreground/80 ${compact ? 'p-2' : 'p-3'}`}>
          <div>
            <p className="font-semibold">{brandingQuery.data?.name ?? '—'}</p>
            <h2 className={compact ? 'text-base font-bold' : 'text-lg font-bold'}>DELIVERY NOTE</h2>
            <p>{delivery.document_number}</p>
          </div>
          <div className="text-right">
            <p>Delivery Date: {formatDate(delivery.delivery_date)}</p>
            <p>Due Date: {formatDate(delivery.due_date)}</p>
          </div>
        </div>

        <div className={`grid grid-cols-2 gap-4 border-b-2 border-foreground/80 ${compact ? 'p-2' : 'p-3'}`}>
          <div>
            <p className="font-medium">Ship To</p>
            <p className="font-semibold">{delivery.customer?.customer_name ?? '—'}</p>
          </div>
          <div className="text-right">
            <p className="font-medium">Warehouse</p>
            <p>{delivery.warehouse?.name ?? '—'}</p>
          </div>
        </div>

        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b-2 border-foreground/80 text-left">
              <th className="border-r-2 border-foreground/80 p-2">Item Code</th>
              <th className="border-r-2 border-foreground/80 p-2">Item Name</th>
              <th className="p-2 text-right">Qty</th>
            </tr>
          </thead>
          <tbody>
            {delivery.items.map((line) => (
              <tr key={line.id} className="border-b border-foreground/30">
                <td className="border-r-2 border-foreground/80 p-2">{line.item_code}</td>
                <td className="border-r-2 border-foreground/80 p-2">{line.item_name}</td>
                <td className="p-2 text-right">
                  {formatQty(line.qty, printOptions.qtyDecimals)} {line.uom}
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        {delivery.remarks && (
          <div className="border-t-2 border-foreground/80 p-3">
            <p className="font-medium">Notes</p>
            <p>{delivery.remarks}</p>
          </div>
        )}

        <div className={`grid grid-cols-3 border-t-2 border-foreground/80 ${compact ? 'gap-2 p-2 pt-6' : 'gap-4 p-3 pt-10'}`}>
          <div className="text-center">
            <div className="border-t border-foreground/80 pt-1">Tanda Terima</div>
            <p className={compact ? 'text-[9px] text-foreground/70' : 'text-xs text-foreground/70'}>Toko/Penerima Barang</p>
          </div>
          <div className="text-center">
            <div className="border-t border-foreground/80 pt-1">Dikeluarkan</div>
            <p className={compact ? 'text-[9px] text-foreground/70' : 'text-xs text-foreground/70'}>Kepala Gudang</p>
          </div>
          <div className="text-center">
            <div className="border-t border-foreground/80 pt-1">Prepared By</div>
            <p className={compact ? 'text-[9px] text-foreground/70' : 'text-xs text-foreground/70'}>Admin</p>
          </div>
        </div>
      </div>

      <PrintOptionsDialog open={optionsOpen} onOpenChange={setOptionsOpen} options={printOptions} onChange={handlePrintOptionsChange} fields={['qty']} showPaperType />
    </div>
  )
}
