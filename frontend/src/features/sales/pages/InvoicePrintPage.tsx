import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import {
  INVOICE_PAPER_TYPE_LABELS,
  INVOICE_PAPER_TYPE_OPTIONS,
  loadInvoicePaperTypePreference,
  loadShowDiscountPreference,
  normalizeInvoicePaperType,
  saveInvoicePaperTypePreference,
  saveShowDiscountPreference,
  type PrintOptions,
} from '@/shared/lib/printOptions'
import { fetchMyPrintSettings, saveMyPrintSetting } from '@/shared/lib/printSettingApi'
import { useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { fetchInvoice } from '../api/invoiceApi'
import { InvoicePaper } from './InvoicePaper'
import { DEJAVU_FONT_STACK } from './invoicePrintConstants'

/**
 * Single-invoice print preview — toolbar (Print Options/Print) and PrintOptionsDialog state live
 * here; the actual paper (every paper-type-specific layout/sizing/@page decision) is InvoicePaper,
 * shared with InvoiceBulkPrintPage so both pages render byte-identical output. See InvoicePaper's
 * own docblock for the Landscape/Portrait/Roll layout details.
 */
export function InvoicePrintPage() {
  const { id } = useParams<{ id: string }>()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(() => ({
    fontSize: 'medium',
    paperType: loadInvoicePaperTypePreference(),
    // SI.pdf shows plain "200" for Qty (no decimals) but "21,000.00" / "4,200,000.00" for
    // price/amount — these are no longer user-configurable (Print Options dropped the three
    // decimal Selects for a single "Decimal" checkbox that only affects the totals block below),
    // so these three just carry their old defaults as fixed values now — see formatNum call
    // sites, which pass literal 0 / 2 / 2 directly rather than reading these fields.
    qtyDecimals: 0,
    priceDecimals: 2,
    amountDecimals: 2,
    showDiscount: loadShowDiscountPreference(),
    showTax: true,
    // Left unset (not false) — both layouts treat unset as ON (2 decimals, matching the legacy
    // Half output invoice-print-spec.md was extracted from). Roll keeps its own separate
    // "unset = off" default (see totalsDecimals).
    showDecimalTotals: undefined,
    // Left unset so both layouts fall back to their own default font (DejaVu Sans Condensed)
    // until the user explicitly picks something else from Font Style.
    fontFamily: undefined,
    signatureLeftLabel: 'AUTHORISED SIGNATURE',
    signatureRightLabel: 'AUTHORISED SIGNATURE',
  }))
  // Persists paperType/showDiscount the same way OutgoingPaymentPrintPage/IncomingPaymentPrintPage
  // already do — load-on-init above, save-on-every-change here. paperType is saved through the
  // Invoice-specific key (loadInvoicePaperTypePreference's own doc comment explains why it isn't
  // the shared PRINT_PAPER_TYPE_STORAGE_KEY Payment print uses). showDecimalTotals is deliberately
  // NOT persisted. showTax defaults to checked on every print open — a fixed default, not a
  // remembered preference — so it isn't persisted either.
  const handlePrintOptionsChange = (next: PrintOptions) => {
    setPrintOptions(next)
    saveInvoicePaperTypePreference(next.paperType)
    saveShowDiscountPreference(next.showDiscount ?? false)
    // Server mirrors exactly what the two localStorage writes above already persist, plus the
    // dot-matrix tuning fields — no new persistence semantics, just a second destination.
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

  // Priority: server setting (if this user has saved one) > localStorage > the defaults above —
  // never blocks the print preview's first paint, and a user with no server row behaves exactly
  // as before this existed.
  const printSettingsQuery = useQuery({ queryKey: ['print-settings'], queryFn: fetchMyPrintSettings })
  useEffect(() => {
    const serverSettings = printSettingsQuery.data?.invoice
    if (serverSettings) {
      setPrintOptions((prev) => ({
        ...prev,
        ...serverSettings,
        // Saved before the paper types were trimmed — map onto the three that still exist.
        paperType: normalizeInvoicePaperType(serverSettings.paperType),
      }))
    }
  }, [printSettingsQuery.data])
  // Roll used to be a separate ?format=roll URL toggle with its own button, independent of the
  // in-dialog Paper Type dropdown that offered A4/Continuous only. Print Options now has exactly
  // one Paper Type field (A4/Half/Continuous/Roll) driving all four, so `format` is just derived
  // from it instead of tracked separately. Only used below for the PrintOptionsDialog's own
  // defaults — every other paper-type-derived value now lives inside InvoicePaper.
  const format = printOptions.paperType === 'roll' ? 'roll' : 'a4'
  // Half's page shell and toolbar use plain CSS (invoiceHalfPrint.css), not Tailwind classes.
  const isHalfSheet = printOptions.paperType === 'half'

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

  const companyName = brandingQuery.data?.name ?? 'PT. KALINDO ETAM'

  return (
    <div className={isHalfSheet ? 'inv-half-shell' : 'mx-auto flex w-fit flex-col gap-4'}>
      <div className={isHalfSheet ? 'inv-no-print' : 'flex items-start justify-between print:hidden'}>
        <h1 className="text-xl font-semibold">Invoice Print Preview</h1>
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

      <InvoicePaper invoice={invoice} printOptions={printOptions} companyName={companyName} printHeader={printHeaderQuery.data} />

      {/* Font Style, Tax, Decimal, and Discount all stay live across every paper type — real
          invoices are routinely untaxed, so hardcoding a tax-on look was wrong. Font Size (pt) has
          been removed entirely (showFontSize=false) — typography is now locked, see both layouts'
          own FONT_PT usage. defaultFontFamily/defaultShowDecimalTotals make the dialog correctly
          show DejaVu/2-decimal as selected for every paper type when the user hasn't explicitly
          overridden them, since that's what actually renders. */}
      <PrintOptionsDialog
        open={optionsOpen}
        onOpenChange={setOptionsOpen}
        options={printOptions}
        onChange={handlePrintOptionsChange}
        fields={[]}
        showPaperType
        paperTypeOptions={INVOICE_PAPER_TYPE_OPTIONS}
        paperTypeLabels={INVOICE_PAPER_TYPE_LABELS}
        dotMatrixTuningPaperType="half"
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
