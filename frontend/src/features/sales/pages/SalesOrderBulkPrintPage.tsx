import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQueries } from '@tanstack/react-query'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { ErrorState } from '@/components/shared/ErrorState'
import { loadSalesOrderPrintOptions, saveSalesOrderPrintOptions, type PrintOptions } from '@/shared/lib/printOptions'
import { useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { fetchSalesOrder } from '../api/salesOrderApi'
import { SalesOrderPrintLayout } from '../components/SalesOrderPrintLayout'
import { DEJAVU_FONT_STACK, legacyCompanyName } from './invoicePrintConstants'
import { PAGE_HEIGHT_MM, PAGE_WIDTH_MM } from './salesOrderPrintConstants'

const DEFAULT_SIGNATURE_LEFT_LABEL = '(AUTHORISED SIGNATURE)'
const DEFAULT_SIGNATURE_RIGHT_LABEL = 'APPROVED BY'

/** Same ceiling the backend enforces nowhere (bulk print never hits an API endpoint — it's N single-document fetches) but the ticket asks for explicitly: "batasi jumlah maksimal dokumen per sekali print". */
export const BULK_PRINT_MAX_DOCUMENTS = 100

/**
 * Bulk print — stacks N copies of SalesOrderPrintLayout (the exact single-
 * document layout SalesOrderPrintPage uses), each starting on its own page
 * via `break-before-page`, one `window.print()` call. No PDF-merge library:
 * the browser's own "Save as PDF" in the print dialog produces the merged
 * file, same client-side-only approach every print feature in this app
 * already uses. Reached from SalesOrderListPage's selection with `?ids=`.
 */
export function SalesOrderBulkPrintPage() {
  const [searchParams] = useSearchParams()
  const ids = (searchParams.get('ids') ?? '').split(',').filter(Boolean)

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

  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()

  const orderQueries = useQueries({
    queries: ids.map((id) => ({ queryKey: ['sales-orders', id], queryFn: () => fetchSalesOrder(id) })),
  })

  if (ids.length === 0) {
    return <ErrorState message="No documents selected to print." />
  }

  if (ids.length > BULK_PRINT_MAX_DOCUMENTS) {
    return <ErrorState message={`Maksimal ${BULK_PRINT_MAX_DOCUMENTS} dokumen per sekali cetak. Anda memilih ${ids.length} dokumen.`} />
  }

  const isLoading = orderQueries.some((query) => query.isLoading)
  const isError = orderQueries.some((query) => query.isError)

  if (isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (isError) {
    return <ErrorState message="Failed to load one or more selected documents." />
  }

  const salesOrders = orderQueries.map((query) => query.data!).filter(Boolean)
  const companyName = legacyCompanyName(brandingQuery.data?.name ?? 'PT Kalindo Etam')
  const fontFamily = printOptions.fontFamily ?? DEJAVU_FONT_STACK
  const decimalsOn = printOptions.showDecimalTotals ?? false
  const signatureLeftLabel = printOptions.signatureLeftLabel ?? DEFAULT_SIGNATURE_LEFT_LABEL
  const signatureRightLabel = printOptions.signatureRightLabel ?? DEFAULT_SIGNATURE_RIGHT_LABEL

  return (
    <div className="mx-auto flex w-fit flex-col gap-4 bg-white p-6 text-foreground shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:p-0 print:shadow-none">
      <style>{`@page { size: ${PAGE_WIDTH_MM}mm ${PAGE_HEIGHT_MM}mm; margin: 0; } @media print { * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }`}</style>

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Bulk Print — {salesOrders.length} Sales Orders</h1>
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

      {salesOrders.map((salesOrder, index) => (
        <SalesOrderPrintLayout
          key={salesOrder.id}
          salesOrder={salesOrder}
          companyName={companyName}
          printHeader={printHeaderQuery.data}
          fontFamily={fontFamily}
          decimalsOn={decimalsOn}
          signatureLeftLabel={signatureLeftLabel}
          signatureRightLabel={signatureRightLabel}
          className={index > 0 ? 'print:break-before-page' : undefined}
        />
      ))}

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
