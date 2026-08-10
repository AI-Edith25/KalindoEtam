import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { formatDate } from '@/lib/utils'
import {
  defaultPrintOptions,
  formatMoney,
  formatQty,
  loadPaperTypePreference,
  PRINT_FONT_SIZE_PX,
  PRINT_PAPER_PAGE_CSS,
  savePaperTypePreference,
  type PrintOptions,
} from '@/shared/lib/printOptions'
import { useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { fetchInvoice } from '../api/invoiceApi'
import { INVOICE_TYPE_LABELS } from '../lib/invoiceTypeLabels'
import { discountLabel } from '../lib/discount'
import type { InvoicePaymentHistoryLine } from '../types'

const PAYMENT_METHOD_LABELS: Record<InvoicePaymentHistoryLine['payment_method'], string> = {
  cash: 'Cash',
  bank_transfer: 'Bank Transfer',
  cheque: 'Cheque',
  qris: 'QRIS',
  credit_card: 'Credit Card',
}

/**
 * Full-bordered "cetak" style matching the legacy system's dot-matrix
 * invoice print, with the same pre-print options (Font Size, Decimal
 * Qty/Price/Amount) users already know, plus Paper Type — A4 (default,
 * unaffected: no @page override) or Continuous 9.5"x11", which narrows the
 * live preview immediately and adds a `<style>@page{...}</style>` so the
 * real print output (Ctrl+P / the Print button) follows suit. Not a PDF —
 * @media print CSS + the browser's native print dialog (window.print()).
 * AppLayout hides its Sidebar/Header chrome under print:hidden, so this
 * page's content is all that prints.
 */
export function InvoicePrintPage() {
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

  const invoiceQuery = useQuery({
    queryKey: ['invoices', id],
    queryFn: () => fetchInvoice(id!),
  })
  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()

  if (invoiceQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const invoice = invoiceQuery.data
  if (!invoice) return null

  const compact = printOptions.paperType === 'continuous'
  const pageCss = PRINT_PAPER_PAGE_CSS[printOptions.paperType]

  return (
    <div
      className={`mx-auto flex flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0 ${compact ? 'max-w-[9.5in]' : 'max-w-3xl'}`}
    >
      {pageCss && <style>{pageCss}</style>}

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Invoice Print Preview</h1>
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
            {printHeaderQuery.data?.address && <p className="text-xs">{printHeaderQuery.data.address}</p>}
            {(printHeaderQuery.data?.phone || printHeaderQuery.data?.email) && (
              <p className="text-xs">{[printHeaderQuery.data?.phone, printHeaderQuery.data?.email].filter(Boolean).join(' · ')}</p>
            )}
            {printHeaderQuery.data?.npwp && <p className="text-xs">NPWP: {printHeaderQuery.data.npwp}</p>}
            <h2 className={compact ? 'text-base font-bold' : 'text-lg font-bold'}>INVOICE</h2>
            <p>{invoice.document_number}</p>
            <p>{INVOICE_TYPE_LABELS[invoice.invoice_type]} Invoice</p>
          </div>
          <div className="text-right">
            <p>Invoice Date: {formatDate(invoice.invoice_date)}</p>
            <p>Due Date: {formatDate(invoice.due_date)}</p>
            {invoice.sales_order?.sales_person && <p>Sales Person: {invoice.sales_order.sales_person.name}</p>}
            {invoice.sales_order?.branch && <p>Branch: {invoice.sales_order.branch.name}</p>}
          </div>
        </div>

        <div className={`grid grid-cols-2 gap-4 border-b-2 border-foreground/80 ${compact ? 'p-2' : 'p-3'}`}>
          <div>
            <p className="font-medium">Bill To</p>
            <p className="font-semibold">{invoice.customer?.customer_name}</p>
            <p>{invoice.customer?.address}</p>
            <p>{invoice.customer?.phone}</p>
          </div>
          <div className="text-right">
            <p className="font-medium">Delivery Reference</p>
            <p>{invoice.delivery?.document_number ?? '—'}</p>
            {invoice.terms_of_payment && <p>Terms: {invoice.terms_of_payment.name}</p>}
          </div>
        </div>

        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b-2 border-foreground/80 text-left">
              <th className="border-r-2 border-foreground/80 p-2">Item</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">Qty</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">Rate</th>
              <th className="p-2 text-right">Amount</th>
            </tr>
          </thead>
          <tbody>
            {invoice.items.map((line) => (
              <tr key={line.id} className="border-b border-foreground/30">
                <td className="border-r-2 border-foreground/80 p-2">
                  {line.item_name}
                  <span className="block text-xs">{line.item_code}</span>
                </td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">
                  {formatQty(line.qty, printOptions.qtyDecimals)} {line.uom}
                </td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(line.rate, printOptions.priceDecimals)}</td>
                <td className="p-2 text-right">{formatMoney(line.amount, printOptions.amountDecimals)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        <div className="flex flex-col items-end gap-1 border-t-2 border-foreground/80 p-3">
          <div className="flex w-64 justify-between">
            <span>Subtotal</span>
            <span>{formatMoney(invoice.subtotal, printOptions.amountDecimals)}</span>
          </div>
          <div className="flex w-64 justify-between">
            <span>{discountLabel(invoice.discount_type, invoice.discount_percentage)}</span>
            <span>-{formatMoney(invoice.discount_amount, printOptions.amountDecimals)}</span>
          </div>
          <div className="flex w-64 justify-between">
            <span>Tax</span>
            <span>{formatMoney(invoice.tax_amount, printOptions.amountDecimals)}</span>
          </div>
          <div className="flex w-64 justify-between border-t border-foreground/80 pt-1 text-base font-semibold">
            <span>Grand Total</span>
            <span>{formatMoney(invoice.grand_total, printOptions.amountDecimals)}</span>
          </div>
          <div className="flex w-64 justify-between">
            <span>Paid</span>
            <span>{formatMoney(invoice.paid_amount, printOptions.amountDecimals)}</span>
          </div>
          <div className="flex w-64 justify-between font-medium">
            <span>Outstanding</span>
            <span>{formatMoney(invoice.outstanding_amount, printOptions.amountDecimals)}</span>
          </div>
        </div>

        {invoice.payment_history.length > 0 && (
          <div className="border-t-2 border-foreground/80 p-3">
            <p className="font-medium">Payment</p>
            <p>
              {PAYMENT_METHOD_LABELS[invoice.payment_history[invoice.payment_history.length - 1].payment_method]}
              {invoice.payment_history[invoice.payment_history.length - 1].cash_account_name
                ? ` — ${invoice.payment_history[invoice.payment_history.length - 1].cash_account_name}`
                : ''}
            </p>
          </div>
        )}

        {invoice.remarks && (
          <div className="border-t-2 border-foreground/80 p-3">
            <p className="font-medium">Notes</p>
            <p>{invoice.remarks}</p>
          </div>
        )}

        <div className="grid grid-cols-2 gap-8 border-t-2 border-foreground/80 p-3 pt-10">
          <div className="text-center">
            <p className="font-semibold">{brandingQuery.data?.name ?? 'PT. KALINDO ETAM'}</p>
            <div className="mt-10 border-t border-foreground/80 pt-1">(AUTHORISED SIGNATURE)</div>
          </div>
          <div className="text-center">
            <p className="font-semibold">{brandingQuery.data?.name ?? 'PT. KALINDO ETAM'}</p>
            <div className="mt-10 border-t border-foreground/80 pt-1">(AUTHORISED SIGNATURE)</div>
          </div>
        </div>
      </div>

      <PrintOptionsDialog open={optionsOpen} onOpenChange={setOptionsOpen} options={printOptions} onChange={handlePrintOptionsChange} showPaperType />
    </div>
  )
}
