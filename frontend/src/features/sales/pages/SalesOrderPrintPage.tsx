import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { type PrintOptions } from '@/shared/lib/printOptions'
import { useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { fetchSalesOrder } from '../api/salesOrderApi'
import { SalesOrderPrintLayout } from '../components/SalesOrderPrintLayout'

/**
 * Classic dot-matrix-era layout matching the legacy system's SO print exactly (SO.pdf) —
 * deliberately NOT Invoice/Delivery's modern bordered style. Same plumbing as those pages
 * (print:hidden toolbar, PrintOptionsDialog, window.print()). The document body itself lives in
 * SalesOrderPrintLayout, shared with SalesOrderBulkPrintPage.
 */
export function SalesOrderPrintPage() {
  const { id } = useParams<{ id: string }>()
  const [printOptions, setPrintOptions] = useState<PrintOptions>({
    fontSize: 'medium',
    paperType: 'a4',
    // SO.pdf shows 2 decimals throughout (150.00, 33,333.34) — this page's own default,
    // unlike Invoice/Delivery's 0-decimal legacy-compat default.
    qtyDecimals: 2,
    priceDecimals: 2,
    amountDecimals: 2,
  })
  const [optionsOpen, setOptionsOpen] = useState(false)

  const salesOrderQuery = useQuery({
    queryKey: ['sales-orders', id],
    queryFn: () => fetchSalesOrder(id!),
  })
  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()

  if (salesOrderQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const salesOrder = salesOrderQuery.data
  if (!salesOrder) return null

  const companyName = brandingQuery.data?.name ?? 'PT. KALINDO ETAM'

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-[12mm]">
      {/* margin: 0 on @page suppresses the browser's own print header/footer chrome (page title
          + date on top, URL + page number on bottom) — that's not part of the document, it's
          browser UI. Document margins come from this wrapper's own print:p-[12mm] instead. */}
      <style>{'@page { size: A4; margin: 0; }'}</style>

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Sales Order Print Preview</h1>
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

      <SalesOrderPrintLayout salesOrder={salesOrder} printOptions={printOptions} companyName={companyName} printHeader={printHeaderQuery.data} />

      <PrintOptionsDialog open={optionsOpen} onOpenChange={setOptionsOpen} options={printOptions} onChange={setPrintOptions} fields={['qty', 'price', 'amount']} />
    </div>
  )
}
