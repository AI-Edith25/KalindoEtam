import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { defaultPrintOptions, loadOfficialReceiptPrintOptions, saveOfficialReceiptPrintOptions, PRINT_PAPER_PAGE_CSS, type PrintOptions } from '@/shared/lib/printOptions'
import { useCompanyBranding } from '@/features/administration/hooks/useCompany'
import { fetchReceiptEntry } from '../api/receiptEntryApi'
import { OfficialReceiptPrintTemplate } from '../components/OfficialReceiptPrintTemplate'

export function IncomingPaymentPrintPage() {
  const { id } = useParams<{ id: string }>()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(() => ({
    ...defaultPrintOptions,
    // Template always prints 2 decimals (reference PDF: "8,550,001.11") — same override OutgoingPaymentPrintPage
    // applies for Payment Voucher's own 2-decimal reference, without touching the shared 0-decimal default.
    amountDecimals: 2,
    ...loadOfficialReceiptPrintOptions(),
  }))
  const [optionsOpen, setOptionsOpen] = useState(false)

  const handlePrintOptionsChange = (next: PrintOptions) => {
    setPrintOptions(next)
    saveOfficialReceiptPrintOptions(next)
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

  const pageCss = PRINT_PAPER_PAGE_CSS[printOptions.paperType]

  return (
    <div className="mx-auto flex flex-col gap-4 bg-white p-6 text-foreground shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:p-0 print:shadow-none">
      {pageCss && <style>{pageCss}</style>}

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Official Receipt Print Preview</h1>
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

      <OfficialReceiptPrintTemplate receipt={receipt} companyName={brandingQuery.data?.name ?? ''} printOptions={printOptions} />

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
