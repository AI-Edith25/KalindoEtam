import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { formatDate } from '@/lib/utils'
import { defaultPrintOptions, formatMoney, PRINT_FONT_SIZE_PX, type PrintOptions } from '@/shared/lib/printOptions'
import { fetchAccountsReceivableAging } from '../api/accountsReceivableAgingApi'

/**
 * Read-only print view of the AR Aging Report's current filters — same
 * @media print + window.print() pattern as InvoicePrintPage. Never
 * paginated / never capped — already bounded by customer count, same as
 * the report page itself.
 */
export function AccountsReceivableAgingReportPrintPage() {
  const [searchParams] = useSearchParams()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(defaultPrintOptions)
  const [optionsOpen, setOptionsOpen] = useState(false)

  const customerId = searchParams.get('customer_id') ?? undefined
  const asOfDate = searchParams.get('as_of_date') ?? undefined

  const listQuery = useQuery({
    queryKey: ['ar-aging-report-print', customerId, asOfDate],
    queryFn: () =>
      fetchAccountsReceivableAging({
        ...(customerId ? { customer_id: customerId } : {}),
        ...(asOfDate ? { as_of_date: asOfDate } : {}),
      }),
  })

  const rows = listQuery.data?.rows ?? []
  const totals = listQuery.data?.totals

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0">
      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">AR Aging Report Print Preview</h1>
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
        <div className="border-b-2 border-foreground/80 p-3">
          <h2 className="text-lg font-bold">ACCOUNTS RECEIVABLE AGING</h2>
          <p>As of {listQuery.data ? formatDate(listQuery.data.as_of_date) : '—'}</p>
        </div>

        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b-2 border-foreground/80 text-left">
              <th className="border-r-2 border-foreground/80 p-2">Customer</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">0-30 Days</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">31-60 Days</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">61-90 Days</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">Over 90 Days</th>
              <th className="p-2 text-right">Total Outstanding</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.customer_id} className="border-b border-foreground/30">
                <td className="border-r-2 border-foreground/80 p-2">{row.customer_name}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(row.bucket_0_30, printOptions.amountDecimals)}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(row.bucket_31_60, printOptions.amountDecimals)}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(row.bucket_61_90, printOptions.amountDecimals)}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">
                  {formatMoney(row.bucket_over_90, printOptions.amountDecimals)}
                </td>
                <td className="p-2 text-right font-medium">{formatMoney(row.total_outstanding, printOptions.amountDecimals)}</td>
              </tr>
            ))}
          </tbody>
          {totals && (
            <tfoot>
              <tr className="border-t-2 border-foreground/80 font-semibold">
                <td className="border-r-2 border-foreground/80 p-2">Total</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(totals.bucket_0_30, printOptions.amountDecimals)}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(totals.bucket_31_60, printOptions.amountDecimals)}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(totals.bucket_61_90, printOptions.amountDecimals)}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">
                  {formatMoney(totals.bucket_over_90, printOptions.amountDecimals)}
                </td>
                <td className="p-2 text-right">{formatMoney(totals.total_outstanding, printOptions.amountDecimals)}</td>
              </tr>
            </tfoot>
          )}
        </table>
      </div>

      <PrintOptionsDialog open={optionsOpen} onOpenChange={setOptionsOpen} options={printOptions} onChange={setPrintOptions} />
    </div>
  )
}
