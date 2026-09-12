import { useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import JsBarcode from 'jsbarcode'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import {
  loadInvoicePaperTypePreference,
  loadShowDiscountPreference,
  PRINT_PAPER_PAGE_CSS,
  saveInvoicePaperTypePreference,
  saveShowDiscountPreference,
  type PrintOptions,
} from '@/shared/lib/printOptions'
import { useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { useAuth } from '@/app/AuthContext'
import { fetchInvoice } from '../api/invoiceApi'
import { DEJAVU_FONT_STACK, InvoicePaperLayout } from './InvoicePaperLayout'

/** Roll format's paper width — actual thermal printer width unconfirmed (58mm vs 80mm are both
    common), so this is the one knob to turn if it turns out to be the wrong one. Content width
    leaves ~4mm margin each side, matching Roll_paper.pdf's effective print area. */
const ROLL_PAPER_WIDTH_MM = 80
const ROLL_CONTENT_WIDTH_MM = ROLL_PAPER_WIDTH_MM - 8

/**
 * A4/Half/Continuous now share one template (InvoicePaperLayout) whose absolute coordinates
 * assume a 210mm-wide reference frame — see that file's own doc comment. A4's own usable width
 * (210mm, @page margin:0) already equals that frame exactly, so both map to 210 here. Continuous
 * is physically wider (9.5in = 241.3mm) with a 6mm @page margin each side (PRINT_PAPER_PAGE_CSS.
 * continuous) — usable content width = 241.3 - 12 = 229.3mm — so it's the only one that actually
 * scales (~1.09x) via InvoicePaperLayout's own contentWidthMm/scaleX handling.
 */
const CONTENT_WIDTH_MM: Record<'a4' | 'half' | 'continuous', number> = {
  a4: 210,
  half: 210,
  continuous: 229.3,
}

/** SI.pdf shows en-US grouping (comma thousands, dot decimal) with no currency symbol in the table — same reasoning as SO/DO print's own formatNum, not the shared id-ID formatMoney/formatQty. */
function formatNum(value: number | string, decimals: number): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

/** Roll_paper.pdf's own date format — same split-string approach as InvoicePaperLayout's own ddmmyyyy helper, same reasoning. */
function formatYyyyDotMmDotDd(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${year}.${month}.${day}`
}

/**
 * A4/Half/Continuous all render InvoicePaperLayout — the classic dot-matrix-era layout matching
 * the legacy system's Invoice print exactly (SI.pdf), the last of the SO/DO/Invoice print series.
 * A4 and Continuous used to have their own separate, older markup (different fonts, an extra
 * "Sales" column, Reference 2/Page No/Attn/Tel/Fax fields, a broken amount-in-words line) — that's
 * gone; all three paper types are now pixel-identical in layout/typography/structure and differ
 * only in @page size + margin + InvoicePaperLayout's own horizontal contentWidthMm scale (see
 * CONTENT_WIDTH_MM above and that file's own doc comment).
 *
 * Goods and Transportation invoices share this exact layout. Two Transportation-only gaps are
 * deliberate, not bugs: ItemCode/UOM render blank (createTransportation() never stores either —
 * no form field collects them), and "Location" renders blank (Transportation invoices carry no
 * sales_order_id/delivery_id, so there is no warehouse/branch to source it from). SI.pdf's own
 * sample Transportation invoice happens to show non-blank values for both, most likely a legacy-
 * system convention this schema doesn't capture — confirmed with the user not to fabricate
 * placeholder text for either field.
 *
 * A second "Roll" format renders alongside this A4/Continuous/Half layout from the same
 * query/data — an 80mm thermal-receipt style with its own sans-serif font, a Code128 barcode of
 * the document number, and one more schema gap of its own: "BIN" has no backing column anywhere
 * (Item/InvoiceItem/Warehouse all lack it), so it renders blank for every invoice, not just
 * Transportation. Paper Type is a single field inside "Print Options" (A4 / Half / Continuous /
 * Roll) — there is no separate toolbar toggle for it.
 *
 * Tax/Decimal/Discount are three independent checkboxes layered on top of every paper type:
 * Tax adds an HCTax column (A4/Continuous/Half) or TAX column (Roll) plus a TAX line in the
 * totals block; Discount adds a DISC line; Decimal switches the totals block between 0 and 2
 * decimals (table columns always show their own fixed decimals — Qty 0, money columns 2 —
 * regardless of this toggle). The totals block itself only expands into TOTAL/TAX/DISC/Grand
 * Total when Tax or Discount is on; with both off it collapses back to a bare Grand Total, same
 * as before either checkbox existed.
 */
export function InvoicePrintPage() {
  const { id } = useParams<{ id: string }>()
  const barcodeRef = useRef<SVGSVGElement>(null)
  const rollContentRef = useRef<HTMLDivElement>(null)
  const { user } = useAuth()
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
    showTax: false,
    // Left unset (not false) — InvoicePaperLayout (a4/half/continuous) treats unset as ON (2
    // decimals, matching the legacy Half output invoice-print-spec.md was extracted from, now
    // shared by all three). Roll keeps its own separate "unset = off" default (see totalsDecimals).
    showDecimalTotals: undefined,
    // Left unset so InvoicePaperLayout falls back to its own default font (DejaVu Sans Condensed,
    // shared by a4/half/continuous) until the user explicitly picks something else from Font Style.
    fontFamily: undefined,
    fontSizePt: 10,
    signatureLeftLabel: 'AUTHORISED SIGNATURE',
    signatureRightLabel: 'AUTHORISED SIGNATURE',
  }))
  // Persists paperType/showDiscount the same way OutgoingPaymentPrintPage/IncomingPaymentPrintPage
  // already do — load-on-init above, save-on-every-change here. paperType is saved through the
  // Invoice-specific key (loadInvoicePaperTypePreference's own doc comment explains why it isn't
  // the shared PRINT_PAPER_TYPE_STORAGE_KEY Payment print uses). showTax/showDecimalTotals are
  // new and deliberately NOT persisted — every print starts from the "Default OFF" ticket spec.
  const handlePrintOptionsChange = (next: PrintOptions) => {
    setPrintOptions(next)
    saveInvoicePaperTypePreference(next.paperType)
    saveShowDiscountPreference(next.showDiscount ?? false)
  }
  const [optionsOpen, setOptionsOpen] = useState(false)
  // Roll used to be a separate ?format=roll URL toggle with its own button, independent of the
  // in-dialog Paper Type dropdown that offered A4/Continuous only. Print Options now has exactly
  // one Paper Type field (A4/Half/Continuous/Roll) driving all four, so `format` is just derived
  // from it instead of tracked separately.
  const format = printOptions.paperType === 'roll' ? 'roll' : 'a4'
  const isContinuous = format === 'a4' && printOptions.paperType === 'continuous'
  const isHalf = format === 'a4' && printOptions.paperType === 'half'
  const showDiscount = printOptions.showDiscount ?? false
  const showTax = printOptions.showTax ?? false
  const showBreakdown = showTax || showDiscount
  const totalsDecimals = printOptions.showDecimalTotals ? 2 : 0

  const invoiceQuery = useQuery({
    queryKey: ['invoices', id],
    queryFn: () => fetchInvoice(id!),
  })
  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()

  // Above the loading/null early returns so this hook always runs — the barcode <svg> only
  // exists once format is 'roll' and the invoice has loaded, hence the ref-null guard inside.
  const documentNumber = invoiceQuery.data?.document_number
  const [rollHeightMm, setRollHeightMm] = useState(150)
  useEffect(() => {
    if (format === 'roll' && barcodeRef.current && documentNumber) {
      JsBarcode(barcodeRef.current, documentNumber, { format: 'CODE128', width: 1, height: 35, margin: 0, displayValue: false })
    }
  }, [format, documentNumber])

  // Runs after the barcode effect above (declaration order = effect execution order for the
  // same commit) so the barcode's own height is already in the DOM before this measures it.
  // +2mm safety margin against sub-pixel rounding.
  useEffect(() => {
    if (format === 'roll' && rollContentRef.current) {
      const heightPx = rollContentRef.current.scrollHeight
      setRollHeightMm(Math.ceil((heightPx * 25.4) / 96) + 2)
    }
  }, [format, documentNumber, showTax])

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
  const tel = invoice.sales_order?.tel ?? invoice.customer?.phone ?? ''
  const location = invoice.delivery?.warehouse?.name ?? ''
  const contentWidthMm = isContinuous ? CONTENT_WIDTH_MM.continuous : isHalf ? CONTENT_WIDTH_MM.half : CONTENT_WIDTH_MM.a4

  return (
    <div
      className={
        format === 'roll'
          ? 'mx-auto flex flex-col gap-4 bg-white p-6 text-foreground shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:p-[2mm] print:shadow-none'
          // A4/Half/Continuous all render InvoicePaperLayout, a fixed-width absolutely-positioned
          // page (see that component) — this wrapper just centers it on screen and drops to zero
          // padding for print, since each paper type's own @page margin + the component's own
          // baked-in coordinates already account for every inset (PRINT_PAPER_PAGE_CSS).
          : 'mx-auto bg-white p-6 text-foreground shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:p-0 print:shadow-none'
      }
      style={
        format === 'roll'
          ? { width: `${ROLL_CONTENT_WIDTH_MM}mm` }
          : { width: `${contentWidthMm}mm` }
      }
    >
      {/* margin: 0 on @page suppresses the browser's own print header/footer chrome (page title
          + date on top, URL + page number on bottom) — that's not part of the document, it's
          browser UI. Document margins come from this wrapper's own padding instead (Roll) or
          @page's own margin plus InvoicePaperLayout's own baked-in coordinates (A4/Half/Continuous,
          see PRINT_PAPER_PAGE_CSS — kept as a single source of truth rather than a second
          hardcoded copy here). */}
      <style>
        {(format === 'roll'
          ? `@page { size: ${ROLL_PAPER_WIDTH_MM}mm ${rollHeightMm}mm; margin: 0; }`
          : isHalf
            ? PRINT_PAPER_PAGE_CSS.half
            : isContinuous
              ? PRINT_PAPER_PAGE_CSS.continuous
              : '@page { size: A4; margin: 0; }') +
          /* Without this, Chrome drops background/border colors that rely on print-color-adjust
             defaults, thinning out table borders and the totals box on some printers/PDF drivers. */
          ' @media print { * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }'}
      </style>

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

      {format === 'a4' && (
        <InvoicePaperLayout
          invoice={invoice}
          companyName={companyName}
          printHeader={printHeaderQuery.data}
          customerTel={tel}
          location={location}
          signatureLeftLabel={printOptions.signatureLeftLabel ?? 'AUTHORISED SIGNATURE'}
          signatureRightLabel={printOptions.signatureRightLabel ?? 'AUTHORISED SIGNATURE'}
          fontFamily={printOptions.fontFamily}
          fontSizePt={printOptions.fontSizePt}
          showTax={showTax}
          showDiscount={showDiscount}
          showDecimalTotals={printOptions.showDecimalTotals}
          contentWidthMm={contentWidthMm}
        />
      )}

      {format === 'roll' && (
      <div ref={rollContentRef} className="flex flex-col gap-2 text-black" style={{ fontFamily: 'Arial, Helvetica, sans-serif', fontSize: '9px' }}>
        <div className="flex flex-col items-center gap-0.5 text-center">
          <p className="text-[11px] font-bold">{companyName}</p>
          {printHeaderQuery.data?.npwp && <p>Co Reg. No. : {printHeaderQuery.data.npwp}</p>}
          {printHeaderQuery.data?.address && <p>{printHeaderQuery.data.address}</p>}
          {printHeaderQuery.data?.phone && <p>Tel : {printHeaderQuery.data.phone}</p>}
          <p className="mt-1 text-[11px] font-bold">INVOICE</p>
        </div>

        <div className="flex flex-col gap-0.5">
          <p className="font-bold">{invoice.customer?.customer_name ?? '—'}</p>
          {invoice.customer?.address && <p>{invoice.customer.address}</p>}
        </div>

        <table className="w-full border-collapse border border-black text-left">
          <tbody>
            <tr>
              <td className="border border-black px-1 py-0.5">{invoice.customer?.customer_code ?? ''}</td>
              <td className="border border-black px-1 py-0.5">{invoice.terms_of_payment?.name ?? ''}</td>
            </tr>
            <tr>
              <td className="border border-black px-1 py-0.5">{invoice.document_number ?? ''}</td>
              <td className="border border-black px-1 py-0.5">{formatYyyyDotMmDotDd(invoice.invoice_date)}</td>
            </tr>
          </tbody>
        </table>

        <table className="w-full border-collapse text-left">
          <thead>
            <tr className="border-b border-black">
              <th className="py-0.5 pr-1 font-normal">ITEM</th>
              <th className="py-0.5 pr-1 font-normal">BIN</th>
              <th className="py-0.5 pr-1 text-right font-normal">QTY</th>
              <th className="py-0.5 pr-1 font-normal">UOM</th>
              <th className="py-0.5 pr-1 text-right font-normal">PRICE</th>
              {showTax && <th className="py-0.5 pr-1 text-right font-normal">TAX</th>}
              <th className="py-0.5 text-right font-normal">AMOUNT</th>
            </tr>
          </thead>
          <tbody>
            {invoice.items.flatMap((item) => [
              <tr key={item.id}>
                <td className="pt-1 pr-1 align-top font-bold">{item.item_code ?? ''}</td>
                <td className="pt-1 pr-1 align-top"></td>
                <td className="pt-1 pr-1 text-right align-top">{formatNum(item.qty, 0)}</td>
                <td className="pt-1 pr-1 align-top">{item.uom ?? ''}</td>
                <td className="pt-1 pr-1 text-right align-top">{formatNum(item.rate, 2)}</td>
                {showTax && <td className="pt-1 pr-1 text-right align-top">{formatNum(item.tax_amount, 2)}</td>}
                <td className="pt-1 text-right align-top">{formatNum(item.amount, 2)}</td>
              </tr>,
              <tr key={`${item.id}-desc`}>
                <td className="pb-1" colSpan={showTax ? 7 : 6}>
                  <div>{item.item_name}</div>
                  {/* Appended below the description rather than as its own column — a 7th/8th
                      column on this 80mm width risks overflow/truncation the narrow Roll
                      layout can't afford (see ROLL_CONTENT_WIDTH_MM). */}
                  {(item.sales_person?.name ?? invoice.sales_person?.name) && (
                    <div className="text-[8px]">Sales: {item.sales_person?.name ?? invoice.sales_person?.name}</div>
                  )}
                </td>
              </tr>,
            ])}
          </tbody>
        </table>

        <div className="mt-1 flex flex-col gap-0.5 border-t border-black pt-1">
          {showBreakdown && (
            <div className="flex items-center justify-between">
              <span>TOTAL</span>
              <span>{formatNum(invoice.subtotal, totalsDecimals)}</span>
            </div>
          )}
          {showTax && (
            <div className="flex items-center justify-between">
              <span>TAX</span>
              <span>{formatNum(invoice.tax_amount, totalsDecimals)}</span>
            </div>
          )}
          {showDiscount && (
            <div className="flex items-center justify-between">
              <span>DISC</span>
              <span>{formatNum(invoice.discount_amount, totalsDecimals)}</span>
            </div>
          )}
          <div className="flex items-center justify-between font-bold">
            <span>GRAND TOTAL</span>
            <span>{formatNum(invoice.grand_total, totalsDecimals)}</span>
          </div>
        </div>

        <p>ISSUED BY : {user?.name ?? ''}</p>

        <div className="mt-1 flex flex-col gap-2 border-t border-black pt-1">
          <p>
            I acknowledged that the contents &amp; quantity had been checked &amp; calculated, therefore I will take responsibility for any mistake,
            fault &amp; error.
          </p>
          <p>Goods sold are not exchangeable/refundable. We STRICTLY do not accept change of mind returns/exchanges.</p>
        </div>

        <svg ref={barcodeRef} className="mt-2 h-9 w-full" />
      </div>
      )}

      {/* Font Size/Style, Tax, Decimal, and Discount all stay live across every InvoicePaperLayout
          paper type — real invoices are routinely untaxed, so hardcoding a tax-on look was wrong.
          defaultFontFamily/defaultShowDecimalTotals make the dialog correctly show DejaVu/2-decimal
          as selected for A4/Half/Continuous alike when the user hasn't explicitly overridden them,
          since that's what actually renders (see InvoicePaperLayout's own fallbacks). */}
      <PrintOptionsDialog
        open={optionsOpen}
        onOpenChange={setOptionsOpen}
        options={printOptions}
        onChange={handlePrintOptionsChange}
        fields={[]}
        showPaperType
        paperTypeOptions={['a4', 'half', 'continuous', 'roll']}
        useNumericFontSize
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
