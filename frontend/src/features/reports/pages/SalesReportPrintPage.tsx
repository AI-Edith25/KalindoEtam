import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { formatDate } from '@/lib/utils'
import { defaultPrintOptions, formatMoney, formatQty, PRINT_FONT_SIZE_PX, type PrintOptions } from '@/shared/lib/printOptions'
import { fetchSalesOrders } from '@/features/sales/api/salesOrderApi'

/**
 * Read-only print view of the Sales Report's current filters — same
 * @media print + window.print() pattern as InvoicePrintPage, capped at the
 * existing 100/page server limit (IndexSalesOrderRequest, unchanged).
 */
export function SalesReportPrintPage() {
  const [searchParams] = useSearchParams()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(defaultPrintOptions)
  const [optionsOpen, setOptionsOpen] = useState(false)

  const customerId = searchParams.get('customer_id') ?? undefined
  const itemId = searchParams.get('item_id') ?? undefined
  const status = searchParams.get('status') ?? undefined
  const dateFrom = searchParams.get('date_from') ?? undefined
  const dateTo = searchParams.get('date_to') ?? undefined

  const ordersQuery = useQuery({
    queryKey: ['sales-report-print', customerId, itemId, status, dateFrom, dateTo],
    queryFn: () =>
      fetchSalesOrders({
        page: 1,
        per_page: 100,
        ...(customerId ? { customer_id: customerId } : {}),
        ...(itemId ? { item_id: itemId } : {}),
        ...(status ? { status } : {}),
        ...(dateFrom ? { date_from: dateFrom } : {}),
        ...(dateTo ? { date_to: dateTo } : {}),
      }),
  })

  const orders = ordersQuery.data?.data ?? []
  const rows = orders.flatMap((order) =>
    order.items.map((item) => ({
      id: item.id,
      document_number: order.document_number,
      customer_name: order.customer?.customer_name ?? '—',
      order_date: order.order_date,
      status: order.status,
      item_name: item.item_name ?? item.item_code ?? '—',
      qty: item.qty,
      amount: item.amount,
    })),
  )
  const total = ordersQuery.data?.meta.total ?? 0

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0">
      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Sales Report Print Preview</h1>
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

      {total > 100 && (
        <p className="rounded border border-amber-500/50 bg-amber-500/10 p-2 text-sm print:hidden">
          Showing 100 of {total} orders — narrow the filters to print everything.
        </p>
      )}

      <div className="border-2 border-foreground/80" style={{ fontSize: PRINT_FONT_SIZE_PX[printOptions.fontSize] }}>
        <div className="border-b-2 border-foreground/80 p-3">
          <h2 className="text-lg font-bold">SALES REPORT</h2>
          {(dateFrom || dateTo) && (
            <p>
              {dateFrom ? formatDate(dateFrom) : '—'} to {dateTo ? formatDate(dateTo) : '—'}
            </p>
          )}
        </div>

        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b-2 border-foreground/80 text-left">
              <th className="border-r-2 border-foreground/80 p-2">Sales No</th>
              <th className="border-r-2 border-foreground/80 p-2">Customer</th>
              <th className="border-r-2 border-foreground/80 p-2">Date</th>
              <th className="border-r-2 border-foreground/80 p-2">Item</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">Qty</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">Sales Amount</th>
              <th className="p-2">Status</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} className="border-b border-foreground/30">
                <td className="border-r-2 border-foreground/80 p-2">{row.document_number ?? '—'}</td>
                <td className="border-r-2 border-foreground/80 p-2">{row.customer_name}</td>
                <td className="border-r-2 border-foreground/80 p-2">{formatDate(row.order_date)}</td>
                <td className="border-r-2 border-foreground/80 p-2">{row.item_name}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatQty(row.qty, printOptions.qtyDecimals)}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(row.amount, printOptions.amountDecimals)}</td>
                <td className="p-2 capitalize">{row.status}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <PrintOptionsDialog open={optionsOpen} onOpenChange={setOptionsOpen} options={printOptions} onChange={setPrintOptions} />
    </div>
  )
}
