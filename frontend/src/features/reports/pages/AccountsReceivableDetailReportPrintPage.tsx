import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { formatDate } from '@/lib/utils'
import { defaultPrintOptions, formatMoney, PRINT_FONT_SIZE_PX, type PrintOptions } from '@/shared/lib/printOptions'
import { fetchAccountsReceivables } from '@/features/payment/api/accountsReceivableApi'

/**
 * Read-only print view of the AR Detail Report's current filters — same
 * @media print + window.print() pattern as InvoicePrintPage, capped at the
 * existing 100/page server limit (IndexAccountsReceivableRequest, unchanged).
 */
export function AccountsReceivableDetailReportPrintPage() {
  const [searchParams] = useSearchParams()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(defaultPrintOptions)
  const [optionsOpen, setOptionsOpen] = useState(false)

  const customerId = searchParams.get('customer_id') ?? undefined
  const status = searchParams.get('status') ?? undefined
  const dateFrom = searchParams.get('date_from') ?? undefined
  const dateTo = searchParams.get('date_to') ?? undefined

  const listQuery = useQuery({
    queryKey: ['ar-detail-report-print', customerId, status, dateFrom, dateTo],
    queryFn: () =>
      fetchAccountsReceivables({
        page: 1,
        per_page: 100,
        ...(customerId ? { customer_id: customerId } : {}),
        ...(status ? { status } : {}),
        ...(dateFrom ? { date_from: dateFrom } : {}),
        ...(dateTo ? { date_to: dateTo } : {}),
      }),
  })

  const rows = listQuery.data?.data ?? []
  const total = listQuery.data?.meta.total ?? 0

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0">
      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">AR Detail Report Print Preview</h1>
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
          Showing 100 of {total} receivables — narrow the filters to print everything.
        </p>
      )}

      <div className="border-2 border-foreground/80" style={{ fontSize: PRINT_FONT_SIZE_PX[printOptions.fontSize] }}>
        <div className="border-b-2 border-foreground/80 p-3">
          <h2 className="text-lg font-bold">ACCOUNTS RECEIVABLE DETAIL</h2>
          {(dateFrom || dateTo) && (
            <p>
              Due {dateFrom ? formatDate(dateFrom) : '—'} to {dateTo ? formatDate(dateTo) : '—'}
            </p>
          )}
        </div>

        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b-2 border-foreground/80 text-left">
              <th className="border-r-2 border-foreground/80 p-2">Customer</th>
              <th className="border-r-2 border-foreground/80 p-2">Invoice Number</th>
              <th className="border-r-2 border-foreground/80 p-2">Invoice Date</th>
              <th className="border-r-2 border-foreground/80 p-2">Due Date</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">Total Invoice</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">Paid Amount</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">Outstanding Amount</th>
              <th className="p-2">Status</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} className="border-b border-foreground/30">
                <td className="border-r-2 border-foreground/80 p-2">{row.customer?.customer_name ?? '—'}</td>
                <td className="border-r-2 border-foreground/80 p-2">{row.invoice?.document_number ?? '—'}</td>
                <td className="border-r-2 border-foreground/80 p-2">
                  {row.invoice?.invoice_date ? formatDate(row.invoice.invoice_date) : '—'}
                </td>
                <td className="border-r-2 border-foreground/80 p-2">{formatDate(row.due_date)}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(row.amount, printOptions.amountDecimals)}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">
                  {formatMoney(row.paid_amount, printOptions.amountDecimals)}
                </td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">
                  {formatMoney(row.outstanding_amount, printOptions.amountDecimals)}
                </td>
                <td className="p-2 capitalize">{row.status.replace('_', ' ')}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <PrintOptionsDialog open={optionsOpen} onOpenChange={setOptionsOpen} options={printOptions} onChange={setPrintOptions} />
    </div>
  )
}
