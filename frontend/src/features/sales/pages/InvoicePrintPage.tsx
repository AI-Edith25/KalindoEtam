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
 * Half is A5 LANDSCAPE — 210 x 148mm (595.276 x 420.945pt in the reference PDF's own page box),
 * not a portrait 148 x 210mm sheet. Tighter 6mm margin than A4's 12mm wrapper padding, same
 * margin-via-@page + zero wrapper padding convention as Continuous (PRINT_PAPER_PAGE_CSS.half),
 * so this is the single source of truth for the page box (on-screen preview included — see the
 * wrapper's own width/minHeight style below, which reads these same two constants).
 */
const HALF_PAGE_WIDTH_MM = 210
const HALF_PAGE_HEIGHT_MM = 148
const HALF_PAGE_MARGIN_MM = 6
/**
 * Safety margin subtracted from the page's theoretical usable height before it's used to stretch
 * content (flex-1 below) or estimate page count. The raw 136mm (148 - 2*6) is an exact printed-area
 * number with zero slack for browser rounding (mm->px conversion, line-height, border widths) —
 * filling flex-1 all the way to 136mm reliably tipped even a single-item invoice onto a phantom
 * second page, throwing the footer/signature block with it. 4mm buffer fixes that without a
 * visible gap.
 */
const HALF_PAGE_SAFETY_MARGIN_MM = 4
/** Available content height per printed page, after the @page margin on both edges and the safety margin above — the divisor for estimating how many physical pages the table will span (see the halfPageCount effect below). */
const HALF_CONTENT_HEIGHT_MM = HALF_PAGE_HEIGHT_MM - HALF_PAGE_MARGIN_MM * 2 - HALF_PAGE_SAFETY_MARGIN_MM

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

function MetaRow({
  label,
  value,
  bold,
  tight,
  alwaysShow,
}: {
  label: string
  value: ReactNode
  bold?: boolean
  tight?: boolean
  /** weblama.pdf (the legacy Half reference) always prints "Tel :" even blank — unlike every
      other tight field, which it drops from the template entirely rather than blank-hiding. */
  alwaysShow?: boolean
}) {
  // A blank ": " line is pure wasted height on a page this small — Transportation invoices in
  // particular leave Attn/Tel/Fax/Location empty on every single document (no sales_order_id to
  // source them from), so skipping empty rows on Half/Continuous recovers real space instead of
  // printing rows nobody reads. A4 keeps rendering them (blank row costs nothing there).
  if (tight && !alwaysShow && (value === '' || value == null)) return null
  return (
    <div className="flex">
      <span className={tight ? 'w-[29.6mm] shrink-0' : 'w-28 shrink-0'}>{label}</span>
      <span className="shrink-0">:</span>
      <span className={`${tight ? 'pl-[2.1mm]' : 'pl-2'} ${bold ? 'font-bold' : ''}`}>{value}</span>
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
  // Half/Continuous are dot-matrix-era continuous stationery where the physical page size is
  // fixed (@page above) — spacing expressed in rem/px drifts against that fixed mm page depending
  // on root font-size and DPI rounding, which is exactly what threw the Half footer onto a phantom
  // page 2. A4 has no such fixed-size @page (browser/printer default) and already renders
  // correctly, so it deliberately keeps its original rem-based Tailwind classes below — only
  // Half/Continuous switch to the mm/pt arbitrary-value classes via this flag.
  const tight = isHalf || isContinuous
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

  // Table cell padding as mm on Half/Continuous (exact px-equivalent of Tailwind's py-1/pr-2/py-1
  // scale, just expressed against a fixed physical unit instead of rem) — A4 keeps its original
  // Tailwind classes untouched below.
  const cellPad = tight ? 'py-[1.1mm] pr-[2.1mm]' : 'py-1 pr-2'
  const cellPadLast = tight ? 'py-[1.1mm]' : 'py-1'
  const totalsRow = tight
    ? 'flex items-center justify-between gap-[8.5mm] border border-b-0 border-black px-[2.1mm] py-[1.1mm]'
    : 'flex items-center justify-between gap-8 border border-b-0 border-black px-2 py-1'
  const totalsRowFinal = tight
    ? 'flex items-center justify-between gap-[8.5mm] border border-black px-[2.1mm] py-[1.1mm] font-bold'
    : 'flex items-center justify-between gap-8 border border-black px-2 py-1 font-bold'

  return (
    <div
      className={
        format === 'roll'
          ? 'mx-auto flex flex-col gap-4 bg-background p-6 text-foreground print:p-[2mm]'
          : isHalf
            // Same margin-via-@page, zero-wrapper-padding convention as Continuous just below —
            // explicit width/minHeight (not max-w-3xl) so the on-screen preview is proportioned
            // like a 148x210mm sheet too, not just the print output. See PRINT_PAPER_PAGE_CSS.half.
            // min-h-[148mm] is screen-only (print:min-h-0 overrides it): the 148mm sheet height
            // can't fit inside the @page's own 136mm usable area (148mm minus 2x6mm margin), so
            // forcing it during print guaranteed a blank overflow page 2 no matter how little
            // content there was. A Tailwind class can be overridden per-media-query; the inline
            // style this replaced could not, since inline styles beat print: variants regardless.
            ? 'mx-auto flex flex-col gap-4 bg-background p-6 text-foreground min-h-[148mm] print:max-w-none print:p-0 print:min-h-0'
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
            ? { width: `${HALF_PAGE_WIDTH_MM}mm` }
            : undefined
      }
    >
      {/* margin: 0 on @page suppresses the browser's own print header/footer chrome (page title
          + date on top, URL + page number on bottom) — that's not part of the document, it's
          browser UI. Document margins come from this wrapper's own padding instead (A4/Roll) or
          @page's own margin (Continuous/Half, see PRINT_PAPER_PAGE_CSS — kept as a single source
          of truth rather than a second hardcoded copy here). */}
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
      <div
        ref={halfContentRef}
        className="flex flex-col text-black"
        style={{
          // Half/Continuous no longer force-stretch to a full page's height before the footer —
          // that stretch (plus the flex-1 spacer below) was the actual cause of the footer
          // spilling to page 2: it filled ~132mm before the footer even started, leaving no room
          // for the ~50mm footer/signature block on invoices with very little else on the page.
          // Content now just flows: header/meta/table/footer back to back, sized by what's
          // actually there. A4 keeps its own full-page stretch — it has 297mm of headroom, so
          // pushing the footer toward the bottom of the sheet was never the problem there.
          minHeight: tight ? undefined : '27.3cm',
          fontFamily: printOptions.fontFamily ?? '"Times New Roman", "Tinos", "Liberation Serif", serif',
          fontSize: `${printOptions.fontSizePt ?? 10}pt`,
          // The real gap turned out to be line-height, not spacing: meta rows were measured at
          // ~5.5mm apart at a 10pt font, which only happens at a ~1.56 ratio — the fallback serif
          // font (Tinos/Liberation Serif) uses far more built-in leading than assumed, inflating
          // every text block on Half/Continuous, not just one spot. 1.15 fixed the page count but
          // read as too cramped; with the fix confirmed, content only needs ~116mm of the 136mm
          // available, so 1.3 buys back readability while leaving real margin to spare.
          lineHeight: tight ? 1.3 : undefined,
        }}
      >
        {isHalf ? (
          // weblama.pdf (the legacy system's own Half-format export — its page box is the exact
          // 595.276x420.945pt this format's own mm constants were derived from) puts the NO/Date/
          // etc. meta block beside the header, not stacked below the INVOICE title: same vertical
          // space as the header block instead of extra space on top of it. That's most of the
          // remaining "too cramped" gap — stacking three sections (header, title, meta grid) was
          // pure wasted height a two-column top row never needed. Customer name + Tel sit under
          // the header on the left, exactly like weblama.pdf; Attn/Fax/Reference 2/Page No never
          // appear in that file's Half template at all (not blank-hidden — structurally absent),
          // so they're only kept here when they actually have something to say.
          <div className="grid grid-cols-2 gap-[4.2mm]">
            <div className="flex flex-col">
              <p className="text-[12pt] font-bold">{companyName}</p>
              {printHeaderQuery.data?.address && <p>{printHeaderQuery.data.address}</p>}
              {printHeaderQuery.data?.phone && <p>TEL : {printHeaderQuery.data.phone}</p>}
              {printHeaderQuery.data?.email && <p>EMAIL : {printHeaderQuery.data.email}</p>}
              <div className="mt-[2.1mm] flex flex-col">
                <p className="font-bold">{invoice.customer?.customer_name ?? '—'}</p>
                {invoice.customer?.address && <p>{invoice.customer.address}</p>}
                <MetaRow label="Attn" value={attn} tight />
                <MetaRow label="Tel" value={tel} tight alwaysShow />
                <MetaRow label="Fax" value={fax} tight />
              </div>
            </div>
            <div className="flex flex-col">
              <MetaRow label="NO" value={invoice.document_number ?? '—'} bold tight />
              <MetaRow label="Date" value={formatDdMmYyyy(invoice.invoice_date)} tight />
              <MetaRow label="Reference 1" value={invoice.reference_1 ?? ''} tight />
              <MetaRow label="Reference 2" value={invoice.reference_2 ?? ''} tight />
              <MetaRow label="Payment Term" value={invoice.terms_of_payment?.name ?? ''} tight />
              <MetaRow label="Jatuh Tempo" value={formatDdMmYyyy(invoice.due_date)} tight />
              <MetaRow label="Sales Person" value={invoice.sales_person?.name ?? ''} tight />
              {/* Only once the invoice genuinely spans more than one physical page — weblama.pdf's
                  own Half template has no such field at all for the common single-page case. */}
              {halfPageCount > 1 && <MetaRow label="Page No" value={`1 of ${halfPageCount}`} tight />}
              <MetaRow label="Location" value={location} tight />
            </div>
          </div>
        ) : (
          <div className={isContinuous ? 'flex flex-col gap-[0.5mm]' : 'flex flex-col gap-0.5'}>
            <p className={isContinuous ? 'text-[15pt] font-bold' : 'text-xl font-bold'}>{companyName}</p>
            {printHeaderQuery.data?.address && <p>{printHeaderQuery.data.address}</p>}
            {printHeaderQuery.data?.phone && <p>TEL : {printHeaderQuery.data.phone}</p>}
            {printHeaderQuery.data?.email && <p>EMAIL : {printHeaderQuery.data.email}</p>}
          </div>
        )}

        <p className={tight ? 'mt-[3.2mm] text-center text-[13.5pt] font-bold' : 'mt-3 text-center text-lg font-bold'}>INVOICE</p>
        <hr className={tight ? 'mt-[2.1mm] border-black' : 'mt-2 border-black'} />

        {!isHalf && (
        <div className={tight ? 'mt-[2.1mm] grid grid-cols-2 gap-[4.2mm] border-b border-black pb-[2.1mm]' : 'mt-2 grid grid-cols-2 gap-4 border-b border-black pb-2'}>
          <div className={tight ? 'flex flex-col gap-[0.5mm]' : 'flex flex-col gap-0.5'}>
            <p className="font-bold">{invoice.customer?.customer_name ?? '—'}</p>
            {invoice.customer?.address && <p>{invoice.customer.address}</p>}
            <div className={tight ? 'mt-[2.1mm] flex flex-col gap-[0.5mm]' : 'mt-2 flex flex-col gap-0.5'}>
              <MetaRow label="Attn" value={attn} tight={tight} />
              <MetaRow label="Tel" value={tel} tight={tight} />
              <MetaRow label="Fax" value={fax} tight={tight} />
            </div>
          </div>
          <div className={tight ? 'flex flex-col gap-[0.5mm]' : 'flex flex-col gap-0.5'}>
            <MetaRow label="NO" value={invoice.document_number ?? '—'} bold tight={tight} />
            <MetaRow label="Date" value={formatDdMmYyyy(invoice.invoice_date)} tight={tight} />
            <MetaRow label="Reference 1" value={invoice.reference_1 ?? ''} tight={tight} />
            <MetaRow label="Reference 2" value={invoice.reference_2 ?? ''} tight={tight} />
            <MetaRow label="Payment Term" value={invoice.terms_of_payment?.name ?? ''} tight={tight} />
            <MetaRow label="Jatuh Tempo" value={formatDdMmYyyy(invoice.due_date)} tight={tight} />
            <MetaRow label="Sales Person" value={invoice.sales_person?.name ?? ''} tight={tight} />
            <MetaRow label="Page No" value="1 of 1" tight={tight} />
            <MetaRow label="Location" value={location} tight={tight} />
          </div>
        </div>
        )}

        <table className="w-full border-collapse text-left">
          <thead style={{ display: 'table-header-group' }}>
            <tr className="border-b border-black">
              <th className={`${cellPad} font-normal`}>No</th>
              <th className={`${cellPad} font-normal`}>ItemCode</th>
              <th className={`${cellPad} font-normal`}>Description</th>
              <th className={`${cellPad} font-normal`}>Sales</th>
              <th className={`${cellPad} text-right font-normal`}>Qty</th>
              <th className={`${cellPad} font-normal`}>UOM</th>
              <th className={`${cellPad} text-right font-normal`}>HCUnitCost</th>
              {showTax && <th className={`${cellPad} text-right font-normal`}>HCTax</th>}
              <th className={`${cellPadLast} text-right font-normal`}>HCLineAmt</th>
            </tr>
          </thead>
          <tbody>
            {invoice.items.map((item, index) => (
              // break-inside-avoid for Continuous/Half — a genuinely multi-page invoice on
              // continuous stock or a Half page must not split a row across the page break; A4 is
              // left exactly as it already behaved (no page-break rule at all).
              <tr key={item.id} className={isContinuous || isHalf ? 'break-inside-avoid' : undefined}>
                <td className={`${cellPad} align-top`}>{index + 1}</td>
                <td className={`${cellPad} align-top`}>{item.item_code ?? ''}</td>
                <td className={`${cellPad} align-top`}>{item.item_name}</td>
                <td className={`${cellPad} align-top`}>{item.sales_person?.name ?? invoice.sales_person?.name ?? ''}</td>
                <td className={`${cellPad} text-right align-top`}>{formatNum(item.qty, 0)}</td>
                <td className={`${cellPad} align-top`}>{item.uom ?? ''}</td>
                <td className={`${cellPad} text-right align-top`}>{formatNum(item.rate, 2)}</td>
                {showTax && <td className={`${cellPad} text-right align-top`}>{formatNum(item.tax_amount, 2)}</td>}
                <td className={`${cellPadLast} text-right align-top`}>{formatNum(item.amount, 2)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        {isHalf && <p className={tight ? 'mt-[2.1mm]' : 'mt-2'}>{terbilangIdr(invoice.grand_total)}</p>}

        {/* A4 only — pushes the footer toward the bottom of the full A4 sheet. Half/Continuous
            dropped this: it's what stretched the box to near-full-page height before the footer
            even started, leaving no room for it. There the footer just follows in normal flow. */}
        {!tight && <div className="flex-1" />}

        {/* One break-inside-avoid unit — E.&O.E/BCA account, totals box, and signature lines must
            land on the same physical page together, never split across a page break. */}
        <div className={isContinuous || isHalf ? 'break-inside-avoid' : undefined}>
        <p>RP</p>
        <hr className={tight ? 'mt-[2.1mm] border-black' : 'mt-2 border-black'} />

        <div className={tight ? 'mt-[2.1mm] grid grid-cols-2 gap-[4.2mm]' : 'mt-2 grid grid-cols-2 gap-4'}>
          <div>
            <p className="font-bold italic">E. &amp; O.E</p>
            <ol className={tight ? 'mt-[1.1mm] list-decimal pl-[4.2mm]' : 'mt-1 list-decimal pl-4'}>
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
              <div className={totalsRow}>
                <span>TOTAL</span>
                <span>RP {formatNum(invoice.subtotal, totalsDecimals)}</span>
              </div>
            )}
            {showTax && (
              <div className={totalsRow}>
                <span>TAX</span>
                <span>RP {formatNum(invoice.tax_amount, totalsDecimals)}</span>
              </div>
            )}
            {showDiscount && (
              <div className={totalsRow}>
                <span>DISC</span>
                <span>RP {formatNum(invoice.discount_amount, totalsDecimals)}</span>
              </div>
            )}
            <div className={totalsRowFinal}>
              <span>Grand Total</span>
              <span>RP {formatNum(invoice.grand_total, totalsDecimals)}</span>
            </div>
          </div>
        </div>

        {/* 10.6mm (A4's pt-10/mt-10) was sized for a 297mm page with room to spare — on a 148mm
            Half sheet that alone was ~22mm of the ~53mm the footer needed, most of the reason it
            never fit. 5mm still leaves a real gap to sign in, just not a full A4-sized one. */}
        <div className={tight ? 'grid grid-cols-2 gap-[8.5mm] pt-[5mm]' : 'grid grid-cols-2 gap-8 pt-10'}>
          <div className="text-center">
            <p className="font-semibold">{invoice.customer?.customer_name ?? '—'}</p>
            <div className={tight ? 'mt-[5mm] border-t border-black pt-[1.1mm]' : 'mt-10 border-t border-black pt-1'}>({printOptions.signatureLeftLabel ?? 'AUTHORISED SIGNATURE'})</div>
          </div>
          <div className="text-center">
            <p className="font-semibold">{companyName}</p>
            <div className={tight ? 'mt-[5mm] border-t border-black pt-[1.1mm]' : 'mt-10 border-t border-black pt-1'}>({printOptions.signatureRightLabel ?? 'AUTHORISED SIGNATURE'})</div>
          </div>
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
