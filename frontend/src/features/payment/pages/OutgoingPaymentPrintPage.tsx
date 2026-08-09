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
import { fetchPaymentEntry } from '../api/paymentEntryApi'

/**
 * Same bordered "cetak" shell as IncomingPaymentPrintPage. Branches on
 * payment_type exactly like OutgoingPaymentDetailPage: general_expense has
 * no supplier/allocation context at all (Category + Description instead),
 * supplier lists every settled Accounts Payable line (not just items[0]
 * like the detail page truncates to). No Unallocated concept — Outgoing
 * settles an AP directly, there's no fan-out/remainder.
 */
export function OutgoingPaymentPrintPage() {
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

  const paymentQuery = useQuery({
    queryKey: ['payment-entries', id],
    queryFn: () => fetchPaymentEntry(id!),
  })
  const brandingQuery = useCompanyBranding()

  if (paymentQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const payment = paymentQuery.data
  if (!payment) return null

  const compact = printOptions.paperType === 'continuous'
  const pageCss = PRINT_PAPER_PAGE_CSS[printOptions.paperType]
  const isSupplierPayment = payment.payment_type === 'supplier'

  return (
    <div
      className={`mx-auto flex flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0 ${compact ? 'max-w-[9.5in]' : 'max-w-3xl'}`}
    >
      {pageCss && <style>{pageCss}</style>}

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Payment Voucher Print Preview</h1>
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
            <h2 className={compact ? 'text-base font-bold' : 'text-lg font-bold'}>PAYMENT VOUCHER</h2>
            <p>{payment.document_number}</p>
          </div>
          <div className="text-right">
            <p>Payment Date: {formatDate(payment.payment_date)}</p>
          </div>
        </div>

        <div className={`grid grid-cols-2 gap-4 border-b-2 border-foreground/80 ${compact ? 'p-2' : 'p-3'}`}>
          {isSupplierPayment ? (
            <div>
              <p className="font-medium">Paid To</p>
              <p className="font-semibold">{payment.supplier?.supplier_name ?? '—'}</p>
            </div>
          ) : (
            <div>
              <p className="font-medium">Category</p>
              <p className="font-semibold">{payment.expense_account?.name ?? '—'}</p>
              {payment.description && <p className="text-xs text-foreground/70">{payment.description}</p>}
            </div>
          )}
          <div className="text-right">
            <p className="font-medium">Payment Method</p>
            <p>{payment.cash_account?.name ?? '—'}</p>
          </div>
        </div>

        {isSupplierPayment && (
          <table className="w-full border-collapse">
            <thead>
              <tr className="border-b-2 border-foreground/80 text-left">
                <th className="border-r-2 border-foreground/80 p-2">Reference</th>
                <th className="p-2 text-right">Amount</th>
              </tr>
            </thead>
            <tbody>
              {payment.items.length > 0 ? (
                payment.items.map((item) => (
                  <tr key={item.id} className="border-b border-foreground/30">
                    <td className="border-r-2 border-foreground/80 p-2">{item.accounts_payable.reference_number}</td>
                    <td className="p-2 text-right">{formatMoney(item.paid_amount, printOptions.amountDecimals)}</td>
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
        )}

        <div className="flex flex-col items-end gap-1 border-t-2 border-foreground/80 p-3">
          <div className="flex w-64 justify-between text-base font-semibold">
            <span>Amount Paid</span>
            <span>{formatMoney(payment.total_amount, printOptions.amountDecimals)}</span>
          </div>
        </div>

        {payment.remarks && (
          <div className="border-t-2 border-foreground/80 p-3">
            <p className="font-medium">Notes</p>
            <p>{payment.remarks}</p>
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
