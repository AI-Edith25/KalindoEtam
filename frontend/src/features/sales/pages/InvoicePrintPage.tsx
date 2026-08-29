import { useEffect, useRef, useState, type ReactNode } from 'react'
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
import { terbilangIdr } from '@/shared/lib/numberToWords'
import { useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { useAuth } from '@/app/AuthContext'
import { fetchInvoice } from '../api/invoiceApi'

/** Roll format's paper width — actual thermal printer width unconfirmed (58mm vs 80mm are both
    common), so this is the one knob to turn if it turns out to be the wrong one. Content width
    leaves ~4mm margin each side, matching Roll_paper.pdf's effective print area. */
const ROLL_PAPER_WIDTH_MM = 80
const ROLL_CONTENT_WIDTH_MM = ROLL_PAPER_WIDTH_MM - 8

/**
 * Continuous paper's own margin (see PRINT_PAPER_PAGE_CSS.continuous — 6mm) subtracted from
 * 11in on both edges, converted to cm to match the A4 block's own min-h-[27.3cm] convention
 * (297mm A4 minus 12mm page's own p-[12mm] wrapper padding = 27.3cm). This number — 9.5"×11"
 * at a flat 6mm margin — was already sitting in printOptions.ts, unconfirmed against the real
 * printer; flagged for the ops team to verify against actual continuous stock (sprocket-hole
 * tractor-feed strips often need a wider left/right margin than a flat 6mm allows) and adjusted
 * here once confirmed.
 */
const CONTINUOUS_CONTENT_HEIGHT_CM = 11 * 2.54 - 1.2

/**
 * Half is A5 LANDSCAPE — 210 x 148mm (595.276 x 420.945pt in the reference PDF's own page box),
 * not a portrait 148 x 210mm sheet. Tighter 6mm margin than A4's 12mm wrapper padding, same
 * margin-via-@page + zero wrapper padding convention as Continuous (PRINT_PAPER_PAGE_CSS.half),
 * so this is the single source of truth for the page box (on-screen preview included — see the
 * wrapper's own width/minHeight style below, which reads these same two constants).
 */
const HALF_PAGE_WIDTH_MM = 210
const HALF_PAGE_HEIGHT_MM = 148
const HALF_PAGE_MARGIN_MM = 6
/** Available content height per printed page, after the @page margin on both edges — the divisor for estimating how many physical pages the table will span (see the halfPageCount effect below). */
const HALF_CONTENT_HEIGHT_MM = HALF_PAGE_HEIGHT_MM - HALF_PAGE_MARGIN_MM * 2

/** SI.pdf shows en-US grouping (comma thousands, dot decimal) with no currency symbol in the table — same reasoning as SO/DO print's own formatNum, not the shared id-ID formatMoney/formatQty. */
function formatNum(value: number | string, decimals: number): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

/** invoice_date/due_date arrive as plain YYYY-MM-DD strings — split directly rather than re-parsing through a Date object, which shifts the calendar date in any timezone ahead of UTC (same pitfall SO/DO print already document). */
function formatDdMmYyyy(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${day}/${month}/${year}`
}

/** Roll_paper.pdf's own date format — same split-string approach as formatDdMmYyyy, same reasoning. */
function formatYyyyDotMmDotDd(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${year}.${month}.${day}`
}

function MetaRow({ label, value, bold }: { label: string; value: ReactNode; bold?: boolean }) {
  return (
    <div className="flex">
      <span className="w-28 shrink-0">{label}</span>
      <span className="shrink-0">:</span>
      <span className={`pl-2 ${bold ? 'font-bold' : ''}`}>{value}</span>
    </div>
  )
}

/**
 * Classic dot-matrix-era layout matching the legacy system's Invoice print exactly (SI.pdf) —
 * replaces the old modern bordered/card style wholesale, the last of the SO/DO/Invoice print
 * series. Left-aligned header with no logo (DO's convention) but with TEL/EMAIL lines (SO's
 * convention) — SI.pdf's own header is a genuine hybrid of the two. Same print plumbing as
 * SO/DO (print:hidden toolbar, @page margin:0 + print:p-[12mm] wrapper, Times New Roman); own
 * from-scratch JSX, no shared print "shell" component exists in this codebase to extend.
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
  const halfContentRef = useRef<HTMLDivElement>(null)
  const [halfPageCount, setHalfPageCount] = useState(1)
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
    showDecimalTotals: false,
    fontFamily: '"Times New Roman", "Tinos", "Liberation Serif", serif',
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

  // Half's own "Page No: 1 of N" — this file has no repeating per-page header/footer (that's a
  // materially bigger feature than "the number is right"), so N is estimated by measuring the
  // whole flowing content's rendered height and dividing by one page's available content height
  // (HALF_CONTENT_HEIGHT_MM) — same "measure the DOM, feed the number back in" technique as
  // rollHeightMm above, just producing a page count instead of a page size.
  useEffect(() => {
    if (isHalf && halfContentRef.current) {
      const heightMm = (halfContentRef.current.scrollHeight * 25.4) / 96
      setHalfPageCount(Math.max(1, Math.ceil(heightMm / HALF_CONTENT_HEIGHT_MM)))
    }
  }, [isHalf, documentNumber, showTax, showDiscount])

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
  const attn = invoice.sales_order?.attention ?? ''
  const tel = invoice.sales_order?.tel ?? invoice.customer?.phone ?? ''
  const fax = invoice.sales_order?.fax ?? ''
  const location = invoice.delivery?.warehouse?.name ?? ''

  return (
    <div
      className={
        format === 'roll'
          ? 'mx-auto flex flex-col gap-4 bg-background p-6 text-foreground print:p-[2mm]'
          : isHalf
            // Same margin-via-@page, zero-wrapper-padding convention as Continuous just below —
            // explicit width/minHeight (not max-w-3xl) so the on-screen preview is proportioned
            // like a 148x210mm sheet too, not just the print output. See PRINT_PAPER_PAGE_CSS.half.
            ? 'mx-auto flex flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0'
            : isContinuous
              // @page's own margin (PRINT_PAPER_PAGE_CSS.continuous) does the inset here — no
              // extra wrapper padding on top of it, unlike A4's margin:0-on-@page + p-[12mm].
              ? 'mx-auto flex max-w-3xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0'
              : 'mx-auto flex max-w-3xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-[12mm]'
      }
      style={
        format === 'roll'
          ? { width: `${ROLL_CONTENT_WIDTH_MM}mm` }
          : isHalf
            ? { width: `${HALF_PAGE_WIDTH_MM}mm`, minHeight: `${HALF_PAGE_HEIGHT_MM}mm` }
            : undefined
      }
    >
      {/* margin: 0 on @page suppresses the browser's own print header/footer chrome (page title
          + date on top, URL + page number on bottom) — that's not part of the document, it's
          browser UI. Document margins come from this wrapper's own padding instead (A4/Roll) or
          @page's own margin (Continuous/Half, see PRINT_PAPER_PAGE_CSS — kept as a single source
          of truth rather than a second hardcoded copy here). */}
      <style>
        {format === 'roll'
          ? `@page { size: ${ROLL_PAPER_WIDTH_MM}mm ${rollHeightMm}mm; margin: 0; }`
          : isHalf
            ? PRINT_PAPER_PAGE_CSS.half
            : isContinuous
              ? PRINT_PAPER_PAGE_CSS.continuous
              : '@page { size: A4; margin: 0; }'}
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
      <div
        ref={halfContentRef}
        className="flex flex-col text-black"
        style={{
          minHeight: isHalf ? `${HALF_CONTENT_HEIGHT_MM}mm` : isContinuous ? `${CONTINUOUS_CONTENT_HEIGHT_CM}cm` : '27.3cm',
          fontFamily: printOptions.fontFamily ?? '"Times New Roman", "Tinos", "Liberation Serif", serif',
          fontSize: `${printOptions.fontSizePt ?? 10}pt`,
        }}
      >
        <div className={isHalf ? 'flex flex-col' : 'flex flex-col gap-0.5'}>
          <p className={isHalf ? 'text-base font-bold' : 'text-xl font-bold'}>{companyName}</p>
          {/* "header perusahaan lebih ringkas" on Half — tighter line spacing via the smaller
              base font + no gap-0.5, not fewer fields; every line below still renders as normal. */}
          {printHeaderQuery.data?.address && <p>{printHeaderQuery.data.address}</p>}
          {printHeaderQuery.data?.phone && <p>TEL : {printHeaderQuery.data.phone}</p>}
          {printHeaderQuery.data?.email && <p>EMAIL : {printHeaderQuery.data.email}</p>}
        </div>

        <p className="mt-3 text-center text-lg font-bold">INVOICE</p>
        <hr className="mt-2 border-black" />

        <div className="mt-2 grid grid-cols-2 gap-4 border-b border-black pb-2">
          <div className="flex flex-col gap-0.5">
            <p className="font-bold">{invoice.customer?.customer_name ?? '—'}</p>
            {invoice.customer?.address && <p>{invoice.customer.address}</p>}
            <div className="mt-2 flex flex-col gap-0.5">
              <MetaRow label="Attn" value={attn} />
              <MetaRow label="Tel" value={tel} />
              <MetaRow label="Fax" value={fax} />
            </div>
          </div>
          <div className="flex flex-col gap-0.5">
            <MetaRow label="NO" value={invoice.document_number ?? '—'} bold />
            <MetaRow label="Date" value={formatDdMmYyyy(invoice.invoice_date)} />
            <MetaRow label="Reference 1" value={invoice.reference_1 ?? ''} />
            <MetaRow label="Reference 2" value={invoice.reference_2 ?? ''} />
            <MetaRow label="Payment Term" value={invoice.terms_of_payment?.name ?? ''} />
            <MetaRow label="Jatuh Tempo" value={formatDdMmYyyy(invoice.due_date)} />
            <MetaRow label="Sales Person" value={invoice.sales_person?.name ?? ''} />
            <MetaRow label="Page No" value={isHalf ? `1 of ${halfPageCount}` : '1 of 1'} />
            <MetaRow label="Location" value={location} />
          </div>
        </div>

        <table className="w-full border-collapse text-left">
          <thead>
            <tr className="border-b border-black">
              <th className="py-1 pr-2 font-normal">No</th>
              <th className="py-1 pr-2 font-normal">ItemCode</th>
              <th className="py-1 pr-2 font-normal">Description</th>
              <th className="py-1 pr-2 font-normal">Sales</th>
              <th className="py-1 pr-2 text-right font-normal">Qty</th>
              <th className="py-1 pr-2 font-normal">UOM</th>
              <th className="py-1 pr-2 text-right font-normal">HCUnitCost</th>
              {showTax && <th className="py-1 pr-2 text-right font-normal">HCTax</th>}
              <th className="py-1 text-right font-normal">HCLineAmt</th>
            </tr>
          </thead>
          <tbody>
            {invoice.items.map((item, index) => (
              // break-inside-avoid for Continuous/Half — a genuinely multi-page invoice on
              // continuous stock or a Half page must not split a row across the page break; A4 is
              // left exactly as it already behaved (no page-break rule at all).
              <tr key={item.id} className={isContinuous || isHalf ? 'break-inside-avoid' : undefined}>
                <td className="py-1 pr-2 align-top">{index + 1}</td>
                <td className="py-1 pr-2 align-top">{item.item_code ?? ''}</td>
                <td className="py-1 pr-2 align-top">{item.item_name}</td>
                <td className="py-1 pr-2 align-top">{item.sales_person?.name ?? invoice.sales_person?.name ?? ''}</td>
                <td className="py-1 pr-2 text-right align-top">{formatNum(item.qty, 0)}</td>
                <td className="py-1 pr-2 align-top">{item.uom ?? ''}</td>
                <td className="py-1 pr-2 text-right align-top">{formatNum(item.rate, 2)}</td>
                {showTax && <td className="py-1 pr-2 text-right align-top">{formatNum(item.tax_amount, 2)}</td>}
                <td className="py-1 text-right align-top">{formatNum(item.amount, 2)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        {isHalf && <p className="mt-2">{terbilangIdr(invoice.grand_total)}</p>}

        <div className="flex-1" />

        <p>RP</p>
        <hr className="mt-2 border-black" />

        <div className="mt-2 grid grid-cols-2 gap-4">
          <div>
            <p className="font-bold italic">E. &amp; O.E</p>
            <ol className="mt-1 list-decimal pl-4">
              <li>
                All cheque and payment should be crossed and made payable to
                <br />
                <span className="font-bold">PT. KALINDO ETAM</span>
                <br />
                BCA NO A/C. 0271461312
              </li>
            </ol>
          </div>

          <div className="self-start">
            {/* TOTAL only appears once there's a breakdown to show (Tax and/or Discount on) —
                with both off this collapses to a bare Grand Total, same as before either
                checkbox existed. Real header-level figures only (Invoice.subtotal/tax_amount/
                discount_amount/grand_total) — never recomputed here, matching InvoiceService's
                own grand_total = subtotal - discount_amount + tax_amount. */}
            {showBreakdown && (
              <div className="flex items-center justify-between gap-8 border border-b-0 border-black px-2 py-1">
                <span>TOTAL</span>
                <span>RP {formatNum(invoice.subtotal, totalsDecimals)}</span>
              </div>
            )}
            {showTax && (
              <div className="flex items-center justify-between gap-8 border border-b-0 border-black px-2 py-1">
                <span>TAX</span>
                <span>RP {formatNum(invoice.tax_amount, totalsDecimals)}</span>
              </div>
            )}
            {showDiscount && (
              <div className="flex items-center justify-between gap-8 border border-b-0 border-black px-2 py-1">
                <span>DISC</span>
                <span>RP {formatNum(invoice.discount_amount, totalsDecimals)}</span>
              </div>
            )}
            <div className="flex items-center justify-between gap-8 border border-black px-2 py-1 font-bold">
              <span>Grand Total</span>
              <span>RP {formatNum(invoice.grand_total, totalsDecimals)}</span>
            </div>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-8 pt-10">
          <div className="text-center">
            <p className="font-semibold">{invoice.customer?.customer_name ?? '—'}</p>
            <div className="mt-10 border-t border-black pt-1">({printOptions.signatureLeftLabel ?? 'AUTHORISED SIGNATURE'})</div>
          </div>
          <div className="text-center">
            <p className="font-semibold">{companyName}</p>
            <div className="mt-10 border-t border-black pt-1">({printOptions.signatureRightLabel ?? 'AUTHORISED SIGNATURE'})</div>
          </div>
        </div>
      </div>
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
        showTax
        showDecimalToggle
        showDiscount
        showSignatureLabels
      />
    </div>
  )
}
