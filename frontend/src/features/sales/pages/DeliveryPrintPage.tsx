import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { loadDeliveryPrintOptions, saveDeliveryPrintOptions, type PrintOptions } from '@/shared/lib/printOptions'
import { useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { fetchDelivery } from '../api/deliveryApi'
import { DEJAVU_FONT_STACK, legacyCompanyName } from './invoicePrintConstants'
import { A4, HALF } from './deliveryPrintConstants'
import { DeliveryPortraitLayout } from './DeliveryPortraitLayout'
import { DeliveryHalfLayout } from './DeliveryHalfLayout'

const DEFAULT_SIGNATURE_LEFT_LABEL = '(AUTHORISED SIGNATURE)'
const DEFAULT_SIGNATURE_RIGHT_LABEL = "Receiver's Signature & Company Stamp"

/**
 * Delivery Order print — two pixel-measured templates (DeliveryPortraitLayout for A4,
 * DeliveryHalfLayout for Half), chosen by Paper Type. Logo is no longer a separate toggle: it's
 * tied to Paper Type itself (A4 always shows it, Half never does), per the ticket's final design
 * decision. Print Options here is intentionally a 5-control subset of the shared dialog (Paper
 * Type, Font Style, Tampilkan Desimal, and the two signature-label textboxes, A4-only) — no
 * Font Size, no per-column decimal selects, no Tax/Discount (Delivery carries no pricing/tax
 * authority in this system).
 */
export function DeliveryPrintPage() {
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
    ...loadDeliveryPrintOptions(),
  }))
  const handlePrintOptionsChange = (next: PrintOptions) => {
    setPrintOptions(next)
    saveDeliveryPrintOptions(next)
  }
  const [optionsOpen, setOptionsOpen] = useState(false)

  const deliveryQuery = useQuery({
    queryKey: ['deliveries', id],
    queryFn: () => fetchDelivery(id!),
  })
  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()

  if (deliveryQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const delivery = deliveryQuery.data
  if (!delivery) return null

  const companyName = legacyCompanyName(brandingQuery.data?.name ?? 'PT Kalindo Etam')
  const isHalf = printOptions.paperType === 'half'
  const decimalsOn = printOptions.showDecimalTotals ?? false
  const fontFamily = printOptions.fontFamily ?? DEJAVU_FONT_STACK
  const signatureLeftLabel = printOptions.signatureLeftLabel ?? DEFAULT_SIGNATURE_LEFT_LABEL
  const signatureRightLabel = printOptions.signatureRightLabel ?? DEFAULT_SIGNATURE_RIGHT_LABEL
  const paper = isHalf ? HALF : A4

  return (
    <div className="mx-auto flex w-fit flex-col gap-4 bg-white p-6 text-foreground shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:p-0 print:shadow-none">
      {/* @page margin MUST be 0 — the margin is already the layout's own div padding (paper.marginMm
          in deliveryPrintConstants.ts). Setting both (as this used to) double-margins the actual
          printed page (the physical @page inset PLUS the div's own padding on top of it), which is
          also what was pushing Half's content past one physical page. margin:0 here + the div's own
          padding is the same convention InvoicePrintPage.tsx already uses. */}
      <style>
        {`@page { size: ${paper.pageWidthMm}mm ${paper.pageHeightMm}mm; margin: 0; } @media print { * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }`}
      </style>

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Delivery Order Print Preview</h1>
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

      {isHalf ? (
        <DeliveryHalfLayout delivery={delivery} companyName={companyName} companyAddress={printHeaderQuery.data?.address ?? undefined} fontFamily={fontFamily} decimalsOn={decimalsOn} />
      ) : (
        <DeliveryPortraitLayout
          delivery={delivery}
          companyName={companyName}
          printHeader={printHeaderQuery.data}
          fontFamily={fontFamily}
          decimalsOn={decimalsOn}
          signatureLeftLabel={signatureLeftLabel}
          signatureRightLabel={signatureRightLabel}
        />
      )}

      <PrintOptionsDialog
        open={optionsOpen}
        onOpenChange={setOptionsOpen}
        options={printOptions}
        onChange={handlePrintOptionsChange}
        fields={[]}
        showPaperType
        paperTypeOptions={['a4', 'half']}
        showFontSize={false}
        showFontFamily
        defaultFontFamily={DEJAVU_FONT_STACK}
        showDecimalToggle
        showSignatureLabels
        defaultSignatureLeftLabel={DEFAULT_SIGNATURE_LEFT_LABEL}
        defaultSignatureRightLabel={DEFAULT_SIGNATURE_RIGHT_LABEL}
        signatureLabelsDisabledHint={isHalf ? 'Hanya untuk kertas A4' : undefined}
      />
    </div>
  )
}
