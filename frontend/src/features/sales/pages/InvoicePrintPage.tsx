import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { fetchInvoice } from '../api/invoiceApi'

/**
 * Print-optimized layout, not a PDF — @media print CSS + the browser's
 * native print dialog (window.print()). AppLayout hides its Sidebar/Header
 * chrome under print:hidden, so this page's content is all that prints.
 */
export function InvoicePrintPage() {
  const { id } = useParams<{ id: string }>()

  const invoiceQuery = useQuery({
    queryKey: ['invoices', id],
    queryFn: () => fetchInvoice(id!),
  })

  if (invoiceQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const invoice = invoiceQuery.data
  if (!invoice) return null

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-6 bg-background p-6 text-foreground print:max-w-none print:p-0">
      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Invoice Print Preview</h1>
        <Button onClick={() => window.print()}>
          <Printer className="size-4" />
          Print
        </Button>
      </div>

      <div className="flex items-start justify-between border-b pb-4">
        <div>
          <h2 className="text-2xl font-bold">INVOICE</h2>
          <p className="text-sm text-muted-foreground">{invoice.document_number}</p>
        </div>
        <div className="text-right text-sm">
          <p>Invoice Date: {formatDate(invoice.invoice_date)}</p>
          <p>Due Date: {formatDate(invoice.due_date)}</p>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4 text-sm">
        <div>
          <p className="font-medium text-muted-foreground">Bill To</p>
          <p className="font-semibold">{invoice.customer?.customer_name}</p>
          <p>{invoice.customer?.address}</p>
          <p>{invoice.customer?.phone}</p>
        </div>
        <div className="text-right">
          <p className="font-medium text-muted-foreground">Delivery Reference</p>
          <p>{invoice.delivery?.document_number ?? '—'}</p>
        </div>
      </div>

      <table className="w-full border-collapse text-sm">
        <thead>
          <tr className="border-b text-left">
            <th className="py-2">Item</th>
            <th className="py-2 text-right">Qty</th>
            <th className="py-2 text-right">Rate</th>
            <th className="py-2 text-right">Amount</th>
          </tr>
        </thead>
        <tbody>
          {invoice.items.map((line) => (
            <tr key={line.id} className="border-b">
              <td className="py-2">
                {line.item_name}
                <span className="block text-xs text-muted-foreground">{line.item_code}</span>
              </td>
              <td className="py-2 text-right">
                {formatNumber(line.qty)} {line.uom}
              </td>
              <td className="py-2 text-right">{formatCurrency(line.rate)}</td>
              <td className="py-2 text-right">{formatCurrency(line.amount)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className="flex flex-col items-end gap-1 text-sm">
        <div className="flex w-64 justify-between">
          <span className="text-muted-foreground">Subtotal</span>
          <span>{formatCurrency(invoice.subtotal)}</span>
        </div>
        <div className="flex w-64 justify-between">
          <span className="text-muted-foreground">Discount</span>
          <span>-{formatCurrency(invoice.discount_amount)}</span>
        </div>
        <div className="flex w-64 justify-between">
          <span className="text-muted-foreground">Tax</span>
          <span>{formatCurrency(invoice.tax_amount)}</span>
        </div>
        <div className="flex w-64 justify-between border-t pt-1 text-base font-semibold">
          <span>Grand Total</span>
          <span>{formatCurrency(invoice.grand_total)}</span>
        </div>
        <div className="flex w-64 justify-between">
          <span className="text-muted-foreground">Paid</span>
          <span>{formatCurrency(invoice.paid_amount)}</span>
        </div>
        <div className="flex w-64 justify-between font-medium">
          <span>Outstanding</span>
          <span>{formatCurrency(invoice.outstanding_amount)}</span>
        </div>
      </div>

      {invoice.remarks && (
        <div className="border-t pt-4 text-sm">
          <p className="font-medium text-muted-foreground">Notes</p>
          <p>{invoice.remarks}</p>
        </div>
      )}
    </div>
  )
}
