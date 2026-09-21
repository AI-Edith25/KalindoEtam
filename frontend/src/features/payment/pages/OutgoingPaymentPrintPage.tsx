import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { defaultPrintOptions, loadPaymentVoucherPrintOptions, savePaymentVoucherPrintOptions, PRINT_PAPER_PAGE_CSS, type PrintOptions } from '@/shared/lib/printOptions'
import { useCompanyBranding } from '@/features/administration/hooks/useCompany'
import { fetchPaymentEntry } from '../api/paymentEntryApi'
import { PaymentVoucherPrintTemplate } from '../components/PaymentVoucherPrintTemplate'

export function OutgoingPaymentPrintPage() {
  const { id } = useParams<{ id: string }>()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(() => ({
    ...defaultPrintOptions,
    ...loadPaymentVoucherPrintOptions(),
  }))
  const [optionsOpen, setOptionsOpen] = useState(false)

  const handlePrintOptionsChange = (next: PrintOptions) => {
    setPrintOptions(next)
    savePaymentVoucherPrintOptions(next)
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

  const pageCss = PRINT_PAPER_PAGE_CSS[printOptions.paperType]

  return (
    <div className="mx-auto flex flex-col gap-4 bg-white p-6 text-foreground shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:p-0 print:shadow-none">
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

      <PaymentVoucherPrintTemplate payment={payment} companyName={brandingQuery.data?.name ?? ''} printOptions={printOptions} />

      <PrintOptionsDialog
        open={optionsOpen}
        onOpenChange={setOptionsOpen}
        options={printOptions}
        onChange={handlePrintOptionsChange}
        fields={['amount']}
        showPaperType
        paperTypeOptions={['a4', 'letter']}
      />
    </div>
  )
}
