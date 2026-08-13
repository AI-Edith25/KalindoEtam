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
  loadPaperTypePreference,
  PRINT_FONT_SIZE_PX,
  PRINT_PAPER_PAGE_CSS,
  savePaperTypePreference,
  type PrintOptions,
} from '@/shared/lib/printOptions'
import { useCompanyBranding } from '@/features/administration/hooks/useCompany'
import { fetchReceiptEntry } from '../api/receiptEntryApi'
import { PAYMENT_METHOD_LABELS } from '../lib/paymentMethodLabels'

/**
 * Same bordered "cetak" shell and pre-print options as InvoicePrintPage —
 * only Decimal Amount applies here (no qty/price on a payment receipt).
 * Reversed allocations are excluded from the list: allocated_amount is a
 * maintained running counter that already nets out reversals (see
 * PaymentAllocationService::reverse()), so including reversed rows here
 * would make the list not foot to the Allocated total printed above it.
 */
export function IncomingPaymentPrintPage() {
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

  const receiptQuery = useQuery({
    queryKey: ['receipt-entries', id],
    queryFn: () => fetchReceiptEntry(id!),
  })
  const brandingQuery = useCompanyBranding()

  if (receiptQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const receipt = receiptQuery.data
  if (!receipt) return null

  const compact = printOptions.paperType === 'continuous'
  const pageCss = PRINT_PAPER_PAGE_CSS[printOptions.paperType]
  const activeItems = receipt.items.filter((item) => !item.is_reversed)
  const unallocated = Number(receipt.unallocated_amount)

  return (
    <div
      className={`mx-auto flex flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0 ${compact ? 'max-w-[9.5in]' : 'max-w-3xl'}`}
    >
      {pageCss && <style>{pageCss}</style>}

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Payment Receipt Print Preview</h1>
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
            <h2 className={compact ? 'text-base font-bold' : 'text-lg font-bold'}>PAYMENT RECEIPT</h2>
            <p>{receipt.document_number}</p>
          </div>
          <div className="text-right">
            <p>Payment Date: {formatDate(receipt.receipt_date)}</p>
          </div>
        </div>

        <div className={`grid grid-cols-2 gap-4 border-b-2 border-foreground/80 ${compact ? 'p-2' : 'p-3'}`}>
          <div>
            <p className="font-medium">Received From</p>
            <p className="font-semibold">{receipt.customer?.customer_name ?? '—'}</p>
          </div>
          <div className="text-right">
            <p className="font-medium">Cash/Bank Account</p>
            <p>{receipt.cash_account?.name ?? '—'}</p>
            <p className="font-medium">Payment Method</p>
            <p>
              {receipt.payment_method ? PAYMENT_METHOD_LABELS[receipt.payment_method] : '—'}
              {(receipt.payment_method === 'giro' || receipt.payment_method === 'cheque') && receipt.giro_number
                ? ` — ${receipt.giro_number}${receipt.giro_due_date ? ` (due ${formatDate(receipt.giro_due_date)})` : ''}`
                : ''}
            </p>
          </div>
        </div>

        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b-2 border-foreground/80 text-left">
              <th className="border-r-2 border-foreground/80 p-2">Invoice / Reference</th>
              <th className="p-2 text-right">Amount</th>
            </tr>
          </thead>
          <tbody>
            {activeItems.length > 0 ? (
              activeItems.map((item) => (
                <tr key={item.id} className="border-b border-foreground/30">
                  <td className="border-r-2 border-foreground/80 p-2">
                    {item.accounts_receivable.invoice?.document_number ?? item.accounts_receivable.reference_number}
                  </td>
                  <td className="p-2 text-right">{formatMoney(item.received_amount, printOptions.amountDecimals)}</td>
                </tr>
              ))
            ) : (
              <tr>
                <td colSpan={2} className="p-2 text-center text-foreground/60">
                  No allocations.
                </td>
              </tr>
            )}
          </tbody>
        </table>

        <div className="flex flex-col items-end gap-1 border-t-2 border-foreground/80 p-3">
          <div className="flex w-64 justify-between">
            <span>Amount Received</span>
            <span>{formatMoney(receipt.total_amount, printOptions.amountDecimals)}</span>
          </div>
          <div className="flex w-64 justify-between text-base font-semibold">
            <span>Allocated</span>
            <span>{formatMoney(receipt.allocated_amount, printOptions.amountDecimals)}</span>
          </div>
          {unallocated > 0 && (
            <div className="flex w-64 justify-between border-t border-foreground/80 pt-1 font-medium">
              <span>Unallocated</span>
              <span>{formatMoney(unallocated, printOptions.amountDecimals)}</span>
            </div>
          )}
        </div>

        {receipt.remarks && (
          <div className="border-t-2 border-foreground/80 p-3">
            <p className="font-medium">Notes</p>
            <p>{receipt.remarks}</p>
          </div>
        )}

        <div className="grid grid-cols-2 gap-8 border-t-2 border-foreground/80 p-3 pt-10">
          <div className="text-center">
            <div className="border-t border-foreground/80 pt-1">Dibayar oleh</div>
          </div>
          <div className="text-center">
            <div className="border-t border-foreground/80 pt-1">Diterima oleh</div>
          </div>
        </div>
      </div>

      <PrintOptionsDialog
        open={optionsOpen}
        onOpenChange={setOptionsOpen}
        options={printOptions}
        onChange={handlePrintOptionsChange}
        fields={['amount']}
        showPaperType
      />
    </div>
  )
}
