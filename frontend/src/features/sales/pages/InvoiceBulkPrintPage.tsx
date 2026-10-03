import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQueries, useQuery } from '@tanstack/react-query'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { ErrorState } from '@/components/shared/ErrorState'
import {
  loadInvoicePaperTypePreference,
  loadShowDiscountPreference,
  saveInvoicePaperTypePreference,
  saveShowDiscountPreference,
  type PrintOptions,
} from '@/shared/lib/printOptions'
import { fetchMyPrintSettings, saveMyPrintSetting } from '@/shared/lib/printSettingApi'
import { useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { fetchInvoice } from '../api/invoiceApi'
import { InvoicePaper } from './InvoicePaper'
import { DEJAVU_FONT_STACK } from './invoicePrintConstants'

/** Same ceiling SalesOrderBulkPrintPage already enforces client-side (no API endpoint backs this
    — it's N single-document fetches, same as there) — matches that existing precedent's number
    rather than inventing a different one. */
export const INVOICE_BULK_PRINT_MAX_DOCUMENTS = 100

/**
 * Bulk print — stacks N InvoicePaper instances (the exact single-invoice template
 * InvoicePrintPage uses), each starting on its own page via `print:break-before-page`, one
 * `window.print()` call for the whole batch. No PDF-merge library: the browser's own "Save as
 * PDF" in the print dialog produces the merged file, same approach SalesOrderBulkPrintPage
 * already uses. Reached from Sales > Invoices' checkbox selection's "Print Invoices" option
 * (`?ids=...`), alongside the existing "Tanda Terima Invoice"/"Laporan Penagihan Harian" options.
 *
 * Unlike SalesOrderBulkPrintPage (which fails the whole page if ANY order fails to load), a
 * failed invoice here renders its own inline error in its own page slot while every other invoice
 * still loads and prints normally — explicit requirement here, since one bad id in a 10-invoice
 * batch shouldn't block printing the other 9. Print Options (paper type, font, tax/decimal/
 * discount, signature labels) is one shared piece of state applied to every InvoicePaper instance
 * at once, loaded/persisted through the exact same functions InvoicePrintPage uses (localStorage +
 * server print-settings) — never a second, independent copy of that logic.
 */
export function InvoiceBulkPrintPage() {
  const [searchParams] = useSearchParams()
  const ids = (searchParams.get('ids') ?? '').split(',').filter(Boolean)

  const [printOptions, setPrintOptions] = useState<PrintOptions>(() => ({
    fontSize: 'medium',
    paperType: loadInvoicePaperTypePreference(),
    // See InvoicePrintPage's own identical block — Qty/Price/Amount decimals are no longer
    // user-configurable, these three just carry their old fixed defaults.
    qtyDecimals: 0,
    priceDecimals: 2,
    amountDecimals: 2,
    showDiscount: loadShowDiscountPreference(),
    showTax: true,
    showDecimalTotals: undefined,
    fontFamily: undefined,
    signatureLeftLabel: 'AUTHORISED SIGNATURE',
    signatureRightLabel: 'AUTHORISED SIGNATURE',
  }))
  const handlePrintOptionsChange = (next: PrintOptions) => {
    setPrintOptions(next)
    saveInvoicePaperTypePreference(next.paperType)
    saveShowDiscountPreference(next.showDiscount ?? false)
    saveMyPrintSetting('invoice', {
      paperType: next.paperType,
      showDiscount: next.showDiscount ?? false,
      dotMatrixHeightMm: next.dotMatrixHeightMm,
      dotMatrixOffsetLeftMm: next.dotMatrixOffsetLeftMm,
      dotMatrixOffsetTopMm: next.dotMatrixOffsetTopMm,
    }).catch(() => {
      // Best-effort — localStorage above already persisted the change for this browser.
    })
  }
  const [optionsOpen, setOptionsOpen] = useState(false)

  const printSettingsQuery = useQuery({ queryKey: ['print-settings'], queryFn: fetchMyPrintSettings })
  useEffect(() => {
    const serverSettings = printSettingsQuery.data?.invoice
    if (serverSettings) setPrintOptions((prev) => ({ ...prev, ...serverSettings }))
  }, [printSettingsQuery.data])
  const format = printOptions.paperType === 'roll' ? 'roll' : 'a4'

  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()

  // Same queryKey shape InvoicePrintPage uses (['invoices', id]) — an invoice already viewed/
  // printed individually in this session is served warm from cache instead of re-fetched.
  const invoiceQueries = useQueries({
    queries: ids.map((id) => ({ queryKey: ['invoices', id], queryFn: () => fetchInvoice(id) })),
  })

  if (ids.length === 0) {
    return <ErrorState message="No invoices selected to print." />
  }

  if (ids.length > INVOICE_BULK_PRINT_MAX_DOCUMENTS) {
    return <ErrorState message={`Maksimal ${INVOICE_BULK_PRINT_MAX_DOCUMENTS} invoice per sekali cetak. Anda memilih ${ids.length} invoice.`} />
  }

  const companyName = brandingQuery.data?.name ?? 'PT. KALINDO ETAM'
  const loadedCount = invoiceQueries.filter((query) => query.data).length

  return (
    <div className="mx-auto flex w-fit flex-col gap-4">
      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">
          Print Invoices Preview — {loadedCount}/{ids.length} Invoices
        </h1>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={() => setOptionsOpen(true)}>
            <Settings2 className="size-4" />
            Print Options
          </Button>
          <Button
            onClick={async () => {
              await document.fonts.ready
              window.print()
            }}
          >
            <Printer className="size-4" />
            Print
          </Button>
        </div>
      </div>

      {ids.map((id, index) => {
        const query = invoiceQueries[index]
        const pageClassName = index > 0 ? 'print:break-before-page' : undefined

        if (query.isLoading) {
          return (
            <div key={id} className={`flex min-h-64 w-full items-center justify-center bg-white${pageClassName ? ` ${pageClassName}` : ''}`}>
              <Loader2 className="size-6 animate-spin text-muted-foreground" />
            </div>
          )
        }

        if (query.isError || !query.data) {
          return (
            <div key={id} className={pageClassName}>
              <ErrorState message={`Failed to load invoice (id: ${id}).`} />
            </div>
          )
        }

        return (
          <InvoicePaper
            key={id}
            invoice={query.data}
            printOptions={printOptions}
            companyName={companyName}
            printHeader={printHeaderQuery.data}
            className={pageClassName}
          />
        )
      })}

      {/* Same Print Options dialog InvoicePrintPage uses — one shared instance here, applied to
          every InvoicePaper above at once. See that page's own doc comment for each field's
          reasoning. */}
      <PrintOptionsDialog
        open={optionsOpen}
        onOpenChange={setOptionsOpen}
        options={printOptions}
        onChange={handlePrintOptionsChange}
        fields={[]}
        showPaperType
        paperTypeOptions={['a4', 'half', 'continuous', 'roll', 'dotmatrix_half', 'dotmatrix_auto']}
        showFontSize={false}
        showFontFamily
        defaultFontFamily={format === 'a4' ? DEJAVU_FONT_STACK : undefined}
        showTax
        showDecimalToggle
        defaultShowDecimalTotals={format === 'a4'}
        showDiscount
        showSignatureLabels
      />
    </div>
  )
}
