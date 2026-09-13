import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { loadSalesOrderPrintOptions, saveSalesOrderPrintOptions, type PrintOptions } from '@/shared/lib/printOptions'
import { useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { fetchSalesOrder } from '../api/salesOrderApi'
import { SalesOrderPrintLayout } from '../components/SalesOrderPrintLayout'
import { DEJAVU_FONT_STACK, legacyCompanyName } from './invoicePrintConstants'
import { PAGE_HEIGHT_MM, PAGE_WIDTH_MM } from './salesOrderPrintConstants'

/** Both extracted character-for-character from SalesOrder.pdf's own signature-line text. */
const DEFAULT_SIGNATURE_LEFT_LABEL = '(AUTHORISED SIGNATURE)'
const DEFAULT_SIGNATURE_RIGHT_LABEL = 'APPROVED BY'

/**
 * Classic dot-matrix-era layout matching the legacy system's SO print exactly (SalesOrder.pdf) —
 * deliberately NOT Invoice/Delivery's modern bordered style. Print Options here is a 4-control
 * subset of the shared dialog (Font Style, Tampilkan Desimal, two signature-label textboxes) — no
 * Paper Type (Sales Order is always A4 portrait), no Font Size, no per-column decimal selects, no
 * Tax/Discount (those toggles exist on the shared PrintOptions type but this page simply never
 * renders/reads them). The document body itself lives in SalesOrderPrintLayout, shared with
 * SalesOrderBulkPrintPage.
 */
export function SalesOrderPrintPage() {
  const { id } = useParams<{ id: string }>()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(() => ({
    fontSize: 'medium',
    paperType: 'a4',
    qtyDecimals: 0,
    priceDecimals: 0,
    amountDecimals: 0,
    showDecimalTotals: false,
    signatureLeftLabel: DEFAULT_SIGNATURE_LEFT_LABEL,
    signatureRightLabel: DEFAULT_SIGNATURE_RIGHT_LABEL,
    ...loadSalesOrderPrintOptions(),
  }))
  const handlePrintOptionsChange = (next: PrintOptions) => {
    setPrintOptions(next)
    saveSalesOrderPrintOptions(next)
  }
  const [optionsOpen, setOptionsOpen] = useState(false)

  const salesOrderQuery = useQuery({
    queryKey: ['sales-orders', id],
    queryFn: () => fetchSalesOrder(id!),
  })
  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()

  if (salesOrderQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const salesOrder = salesOrderQuery.data
  if (!salesOrder) return null

  const companyName = legacyCompanyName(brandingQuery.data?.name ?? 'PT Kalindo Etam')
  const fontFamily = printOptions.fontFamily ?? DEJAVU_FONT_STACK
  const decimalsOn = printOptions.showDecimalTotals ?? false
  const signatureLeftLabel = printOptions.signatureLeftLabel ?? DEFAULT_SIGNATURE_LEFT_LABEL
  const signatureRightLabel = printOptions.signatureRightLabel ?? DEFAULT_SIGNATURE_RIGHT_LABEL

  return (
    <div className="mx-auto flex w-fit flex-col gap-4 bg-white p-6 text-foreground shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:p-0 print:shadow-none">
      {/* @page margin MUST be 0 — the margin is already the layout's own div padding (MARGIN_MM in
          salesOrderPrintConstants.ts). Setting both would double-margin the real printed page (the
          @page inset plus the div's own padding stacked on top of it) — same bug already fixed for
          Delivery Order's print page. */}
      <style>{`@page { size: ${PAGE_WIDTH_MM}mm ${PAGE_HEIGHT_MM}mm; margin: 0; } @media print { * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }`}</style>

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Sales Order Print Preview</h1>
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

      <SalesOrderPrintLayout
        salesOrder={salesOrder}
        companyName={companyName}
        printHeader={printHeaderQuery.data}
        fontFamily={fontFamily}
        decimalsOn={decimalsOn}
        signatureLeftLabel={signatureLeftLabel}
        signatureRightLabel={signatureRightLabel}
      />

      <PrintOptionsDialog
        open={optionsOpen}
        onOpenChange={setOptionsOpen}
        options={printOptions}
        onChange={handlePrintOptionsChange}
        fields={[]}
        showFontSize={false}
        showFontFamily
        defaultFontFamily={DEJAVU_FONT_STACK}
        showDecimalToggle
        showSignatureLabels
        defaultSignatureLeftLabel={DEFAULT_SIGNATURE_LEFT_LABEL}
        defaultSignatureRightLabel={DEFAULT_SIGNATURE_RIGHT_LABEL}
      />
    </div>
  )
}
