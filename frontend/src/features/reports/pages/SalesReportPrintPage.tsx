import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { formatDate } from '@/lib/utils'
import { defaultPrintOptions, formatMoney, formatQty, PRINT_FONT_SIZE_PX, type PrintOptions } from '@/shared/lib/printOptions'
import { fetchProductSales } from '../api/productSalesApi'

/**
 * Read-only print view of the active Sales Report tab's current filters. Only Product Sales is
 * built this commit — the other 3 tabs' print views land alongside their own tabs.
 */
export function SalesReportPrintPage() {
  const [searchParams] = useSearchParams()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(defaultPrintOptions)
  const [optionsOpen, setOptionsOpen] = useState(false)

  const dateFrom = searchParams.get('date_from') ?? undefined
  const dateTo = searchParams.get('date_to') ?? undefined
  const params = {
    customer_id: searchParams.get('customer_id') ?? undefined,
    item_id: searchParams.get('item_id') ?? undefined,
    item_group_id: searchParams.get('item_group_id') ?? undefined,
    sales_person_id: searchParams.get('sales_person_id') ?? undefined,
    branch_id: searchParams.get('branch_id') ?? undefined,
    status: searchParams.get('status') ?? undefined,
    date_from: dateFrom,
    date_to: dateTo,
  }

  const listQuery = useQuery({
    queryKey: ['product-sales-print', params],
    queryFn: () => fetchProductSales({ page: 1, per_page: 500, ...params }),
  })

  const rows = listQuery.data?.data ?? []
  const total = listQuery.data?.meta.total ?? 0
  const kpis = listQuery.data?.meta.kpis

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0">
      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Product Sales Print Preview</h1>
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

      {total > 500 && (
        <p className="rounded border border-amber-500/50 bg-amber-500/10 p-2 text-sm print:hidden">
          Showing 500 of {total} items — narrow the filters to print everything.
        </p>
      )}

      <div className="border-2 border-foreground/80" style={{ fontSize: PRINT_FONT_SIZE_PX[printOptions.fontSize] }}>
        <div className="border-b-2 border-foreground/80 p-3">
          <h2 className="text-lg font-bold">PRODUCT SALES REPORT</h2>
          {(dateFrom || dateTo) && (
            <p>
              {dateFrom ? formatDate(dateFrom) : '—'} to {dateTo ? formatDate(dateTo) : '—'}
            </p>
          )}
          {kpis && (
            <p>
              Total Qty: {formatQty(kpis.total_qty, printOptions.qtyDecimals)} — Total Revenue: {formatMoney(kpis.total_revenue, printOptions.amountDecimals)}
            </p>
          )}
        </div>

        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b-2 border-foreground/80 text-left">
              <th className="border-r-2 border-foreground/80 p-2">Item Code</th>
              <th className="border-r-2 border-foreground/80 p-2">Description</th>
              <th className="border-r-2 border-foreground/80 p-2">Item Group</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">Qty</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">Amount Excl. Tax</th>
              <th className="p-2 text-right">Amount Incl. Tax</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} className="border-b border-foreground/30">
                <td className="border-r-2 border-foreground/80 p-2">{row.item_code ?? '—'}</td>
                <td className="border-r-2 border-foreground/80 p-2">{row.item_name}</td>
                <td className="border-r-2 border-foreground/80 p-2">{row.item_group_name ?? 'Unassigned'}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatQty(row.qty, printOptions.qtyDecimals)}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(row.amount, printOptions.amountDecimals)}</td>
                <td className="p-2 text-right">{formatMoney(row.amount_incl_tax, printOptions.amountDecimals)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <PrintOptionsDialog open={optionsOpen} onOpenChange={setOptionsOpen} options={printOptions} onChange={setPrintOptions} />
    </div>
  )
}
