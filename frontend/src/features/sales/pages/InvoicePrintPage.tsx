import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import JsBarcode from 'jsbarcode'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import {
  formatPrintNumber,
  getPrintMargins,
  INVOICE_ADVANCED_DEFAULTS,
  PRINT_COLUMN_LABELS,
  PRINT_FONT_SIZE_PX_HALF,
  type PrintColumnKey,
  type PrintOptions,
  type PrintPaperType,
} from '@/shared/lib/printOptions'
import { fetchInvoicePrintSetting, invoicePrintSettingToOptions, FALLBACK_INVOICE_PRINT_OPTIONS } from '@/features/administration/api/invoicePrintSettingApi'
import { useBrandingLogoObjectUrl, useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { useAuth } from '@/app/AuthContext'
import { fetchInvoice } from '../api/invoiceApi'
import type { InvoiceItem } from '../types'
import { discountLabel } from '../lib/discount'

/** Roll format's paper width — actual thermal printer width unconfirmed (58mm vs 80mm are both
    common), so this is the one knob to turn if it turns out to be the wrong one. Content width
    leaves ~4mm margin each side, matching Roll_paper.pdf's effective print area. */
const ROLL_PAPER_WIDTH_MM = 80
const ROLL_CONTENT_WIDTH_MM = ROLL_PAPER_WIDTH_MM - 8

/**
 * Physical page size per paper type, portrait orientation (swapped for landscape) — the single
 * source of truth for both the @page CSS and the on-screen preview's own width/min-height, now
 * that margin is a user-configurable inset (printOptions.margins, keyed by paper type — see
 * getPrintMargins) applied as wrapper padding, rather than baked into per-paper-type constants.
 * Continuous is 9.5in x 11in converted to mm; unconfirmed against the real printer, same flag as
 * before — ops team to verify against actual continuous stock once this ships.
 */
const PAGE_SIZE_MM: Record<PrintPaperType, [width: number, height: number]> = {
  a4: [210, 297],
  continuous: [241.3, 279.4],
  half: [148, 210],
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
 * A second "Roll" format (?format=roll, matching Roll_paper.pdf) renders alongside this A4
 * layout from the same query/data — an 80mm thermal-receipt style with its own sans-serif font,
 * a Code128 barcode of the document number, and one more schema gap of its own: "BIN" has no
 * backing column anywhere (Item/InvoiceItem/Warehouse all lack it), so it renders blank for
 * every invoice, not just Transportation.
 */
export function InvoicePrintPage() {
  const { id } = useParams<{ id: string }>()
  const [searchParams, setSearchParams] = useSearchParams()
  const format = searchParams.get('format') === 'roll' ? 'roll' : 'a4'
  const barcodeRef = useRef<SVGSVGElement>(null)
  const rollContentRef = useRef<HTMLDivElement>(null)
  // CSS @page's `size` descriptor has no valid syntax for "fixed width, auto height" (`80mm
  // auto` is not a legal value per the Paged Media spec — browsers drop the whole declaration
  // and fall back to the previous/default paper, which is how this shipped broken: Chrome kept
  // defaulting to A4 with the receipt rendered small in the corner). Two explicit lengths *is*
  // valid and Chrome does select it as the print paper size without the user touching the paper
  // picker — so the actual content height is measured after render and fed in as the second
  // length, faking "auto" instead of using the unsupported keyword.
  const [rollHeightMm, setRollHeightMm] = useState(150)
  const halfContentRef = useRef<HTMLDivElement>(null)
  const [halfPageCount, setHalfPageCount] = useState(1)
  const { user } = useAuth()
  const queryClient = useQueryClient()
  // Fallback shape (matches the pre-existing hardcoded layout) shown until the company default
  // loads — see the initFromSettings effect below, which applies the real default exactly once.
  const [printOptions, setPrintOptions] = useState<PrintOptions>(FALLBACK_INVOICE_PRINT_OPTIONS)
  const [optionsOpen, setOptionsOpen] = useState(false)

  const settingQuery = useQuery({ queryKey: ['invoice-print-settings'], queryFn: fetchInvoicePrintSetting })
  // "Override sementara untuk sekali cetak" — the company default only ever seeds this session's
  // state ONCE, on first load. Every later refetch of settingQuery (e.g. a background refetch)
  // must not clobber whatever the user has since changed in the dialog.
  const initializedFromSettingsRef = useRef(false)
  useEffect(() => {
    if (settingQuery.data && !initializedFromSettingsRef.current) {
      initializedFromSettingsRef.current = true
      setPrintOptions(invoicePrintSettingToOptions(settingQuery.data))
    }
  }, [settingQuery.data])

  async function handleResetToDefault() {
    const fresh = await queryClient.fetchQuery({ queryKey: ['invoice-print-settings'], queryFn: fetchInvoicePrintSetting })
    setPrintOptions(invoicePrintSettingToOptions(fresh))
  }

  // Continuous/Half are both paper-size variants of the A4 layout (same classic tabular content,
  // just a different @page size/margin) — orthogonal to the Roll format above, which is a
  // completely different 80mm thermal-receipt layout. Only meaningful when format === 'a4'.
  const isContinuous = format === 'a4' && printOptions.paperType === 'continuous'
  const isHalf = format === 'a4' && printOptions.paperType === 'half'
  const showDiscount = printOptions.showDiscount ?? false
  const numberFormat = printOptions.numberFormat ?? INVOICE_ADVANCED_DEFAULTS.numberFormat
  const showCurrencySymbol = printOptions.showCurrencySymbol ?? INVOICE_ADVANCED_DEFAULTS.showCurrencySymbol
  const visibleColumns = printOptions.visibleColumns ?? INVOICE_ADVANCED_DEFAULTS.visibleColumns
  const orientation = printOptions.orientation ?? INVOICE_ADVANCED_DEFAULTS.orientation
  const margins = getPrintMargins(printOptions, printOptions.paperType)
  const scale = (printOptions.scalePercent ?? INVOICE_ADVANCED_DEFAULTS.scalePercent) / 100
  const fontFamily = printOptions.fontFamily ?? INVOICE_ADVANCED_DEFAULTS.fontFamily
  const showLogo = printOptions.showLogo ?? INVOICE_ADVANCED_DEFAULTS.showLogo
  const showAddress = printOptions.showAddress ?? INVOICE_ADVANCED_DEFAULTS.showAddress
  const showPhone = printOptions.showPhone ?? INVOICE_ADVANCED_DEFAULTS.showPhone
  const showEmail = printOptions.showEmail ?? INVOICE_ADVANCED_DEFAULTS.showEmail
  const footerNotes = printOptions.footerNotes ?? INVOICE_ADVANCED_DEFAULTS.footerNotes
  const showSignatureBlock = printOptions.showSignatureBlock ?? INVOICE_ADVANCED_DEFAULTS.showSignatureBlock
  const signatureLeftLabel = printOptions.signatureLeftLabel ?? INVOICE_ADVANCED_DEFAULTS.signatureLeftLabel
  const signatureRightLabel = printOptions.signatureRightLabel ?? INVOICE_ADVANCED_DEFAULTS.signatureRightLabel
  const showPageNumber = printOptions.showPageNumber ?? INVOICE_ADVANCED_DEFAULTS.showPageNumber

  // Orientation swaps width/height on top of the paper type's own base size (PAGE_SIZE_MM) —
  // margin is applied separately as wrapper padding below, not baked into this.
  const [baseWidthMm, baseHeightMm] = PAGE_SIZE_MM[printOptions.paperType]
  const pageWidthMm = orientation === 'landscape' ? baseHeightMm : baseWidthMm
  const pageHeightMm = orientation === 'landscape' ? baseWidthMm : baseHeightMm
  const contentHeightMm = pageHeightMm - margins.top - margins.bottom

  const invoiceQuery = useQuery({
    queryKey: ['invoices', id],
    queryFn: () => fetchInvoice(id!),
  })
  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()
  const logoObjectUrl = useBrandingLogoObjectUrl(showLogo ? brandingQuery.data?.logo_url : null)

  // Above the loading/null early returns so this hook always runs — the barcode <svg> only
  // exists once format is 'roll' and the invoice has loaded, hence the ref-null guard inside.
  const documentNumber = invoiceQuery.data?.document_number
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
  }, [format, documentNumber, printOptions.qtyDecimals, printOptions.priceDecimals, printOptions.amountDecimals])

  // Half's own "Page No: 1 of N" — this file has no repeating per-page header/footer (that's a
  // materially bigger feature than "the number is right"), so N is estimated by measuring the
  // whole flowing content's rendered height and dividing by one page's available content height
  // (contentHeightMm, now margin-derived rather than a hardcoded Half-only constant) — same
  // "measure the DOM, feed the number back in" technique as rollHeightMm above, just producing a
  // page count instead of a page size.
  useEffect(() => {
    if (isHalf && halfContentRef.current) {
      const heightMm = (halfContentRef.current.scrollHeight * 25.4) / 96
      setHalfPageCount(Math.max(1, Math.ceil(heightMm / contentHeightMm)))
    }
  }, [isHalf, documentNumber, contentHeightMm, printOptions.qtyDecimals, printOptions.priceDecimals, printOptions.amountDecimals, showDiscount])

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

  const columnAlign = (key: PrintColumnKey): 'left' | 'right' => (key === 'qty' || key === 'unitCost' || key === 'lineAmt' ? 'right' : 'left')
  // Read once here rather than inside renderColumnCell below — TS's control-flow narrowing of
  // `invoice` (non-null since the guard above) doesn't carry into a nested function declaration.
  const invoiceSalesPersonName = invoice.sales_person?.name ?? ''

  function renderColumnCell(key: PrintColumnKey, item: InvoiceItem): ReactNode {
    switch (key) {
      case 'itemCode':
        return item.item_code ?? ''
      case 'description':
        return item.item_name
      case 'sales':
        return item.sales_person?.name ?? invoiceSalesPersonName
      case 'qty':
        return formatPrintNumber(item.qty, printOptions.qtyDecimals, numberFormat)
      case 'uom':
        return item.uom ?? ''
      case 'unitCost':
        return formatPrintNumber(item.rate, printOptions.priceDecimals, numberFormat, showCurrencySymbol)
      case 'lineAmt':
        return formatPrintNumber(item.amount, printOptions.amountDecimals, numberFormat, showCurrencySymbol)
    }
  }

  return (
    <div
      className={
        format === 'roll'
          ? 'mx-auto flex flex-col gap-4 bg-background p-6 text-foreground print:p-[2mm]'
          : 'mx-auto flex flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0'
      }
      style={format === 'roll' ? { width: `${ROLL_CONTENT_WIDTH_MM}mm` } : { width: `${pageWidthMm}mm`, minHeight: `${pageHeightMm}mm` }}
    >
      {/* margin: 0 on @page suppresses the browser's own print header/footer chrome (page title
          + date on top, URL + page number on bottom) — that's not part of the document, it's
          browser UI. Document margins come from the content div's own padding instead (`margins`
          below, per paper type — see getPrintMargins), configurable per paper type instead of
          three separate hardcoded @page margins. */}
      <style>
        {format === 'roll'
          ? `@page { size: ${ROLL_PAPER_WIDTH_MM}mm ${rollHeightMm}mm; margin: 0; }`
          : `@page { size: ${pageWidthMm}mm ${pageHeightMm}mm; margin: 0; }`}
      </style>

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Invoice Print Preview</h1>
        <div className="flex items-center gap-2">
          <div className="flex items-center gap-1 rounded-md border p-1">
            {/* format and paperType are two separate pieces of state (see isContinuous/isHalf
                above) — the A4/Half buttons here set both together so this 3-way group reads as
                one coherent "paper type" choice, even though only Half needs to touch paperType. */}
            <Button
              size="sm"
              variant={format === 'a4' && !isHalf ? 'default' : 'ghost'}
              onClick={() => {
                setSearchParams({})
                setPrintOptions({ ...printOptions, paperType: 'a4' })
              }}
            >
              A4
            </Button>
            <Button size="sm" variant={format === 'roll' ? 'default' : 'ghost'} onClick={() => setSearchParams({ format: 'roll' })}>
              Roll
            </Button>
            <Button
              size="sm"
              variant={isHalf ? 'default' : 'ghost'}
              onClick={() => {
                setSearchParams({})
                setPrintOptions({ ...printOptions, paperType: 'half' })
              }}
            >
              Half
            </Button>
          </div>
          {/* Roll already picks its own fixed 80mm size — Paper Type only makes sense against
              the A4-style tabular layout, so it's hidden while format === 'roll'. */}
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
          minHeight: `${contentHeightMm}mm`,
          padding: `${margins.top}mm ${margins.right}mm ${margins.bottom}mm ${margins.left}mm`,
          zoom: scale,
          fontFamily,
          fontSize: isHalf
            ? PRINT_FONT_SIZE_PX_HALF[printOptions.fontSize]
            : printOptions.fontSize === 'small'
              ? '11px'
              : printOptions.fontSize === 'large'
                ? '15px'
                : '13px',
        }}
      >
        <div className={isHalf ? 'flex flex-col' : 'flex flex-col gap-0.5'}>
          {showLogo && logoObjectUrl && <img src={logoObjectUrl} alt="" className={isHalf ? 'h-8 w-auto' : 'h-12 w-auto'} />}
          <p className={isHalf ? 'text-base font-bold' : 'text-xl font-bold'}>{companyName}</p>
          {/* "header perusahaan lebih ringkas" on Half — tighter line spacing via the smaller
              base font + no gap-0.5, not fewer fields; every line below still renders as normal. */}
          {showAddress && printHeaderQuery.data?.address && <p>{printHeaderQuery.data.address}</p>}
          {showPhone && printHeaderQuery.data?.phone && <p>TEL : {printHeaderQuery.data.phone}</p>}
          {showEmail && printHeaderQuery.data?.email && <p>EMAIL : {printHeaderQuery.data.email}</p>}
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
            {showPageNumber && <MetaRow label="Page No" value={isHalf ? `1 of ${halfPageCount}` : '1 of 1'} />}
            <MetaRow label="Location" value={location} />
          </div>
        </div>

        <table className="w-full border-collapse text-left">
          <thead>
            <tr className="border-b border-black">
              <th className="py-1 pr-2 font-normal">No</th>
              {visibleColumns.map((key) => (
                <th key={key} className={`py-1 pr-2 font-normal ${columnAlign(key) === 'right' ? 'text-right' : ''}`}>
                  {PRINT_COLUMN_LABELS[key]}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {invoice.items.map((item, index) => (
              // break-inside-avoid for Continuous/Half — a genuinely multi-page invoice on
              // continuous stock or a Half page must not split a row across the page break; A4 is
              // left exactly as it already behaved (no page-break rule at all).
              <tr key={item.id} className={isContinuous || isHalf ? 'break-inside-avoid' : undefined}>
                <td className="py-1 pr-2 align-top">{index + 1}</td>
                {visibleColumns.map((key) => (
                  <td key={key} className={`py-1 pr-2 align-top ${columnAlign(key) === 'right' ? 'text-right' : ''}`}>
                    {renderColumnCell(key, item)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>

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
            {footerNotes.trim() !== '' && <p className="mt-2 whitespace-pre-wrap">{footerNotes}</p>}
          </div>

          <div className="self-start">
            {/* Real header-level data only — Invoice's discount is a single figure on the
                Invoice itself (discount_amount/discount_type/discount_percentage), never
                per line item (InvoiceItem has no discount column at all), so this is one row,
                not a per-item breakdown. Same discountLabel() util InvoiceDetailPage/
                InvoiceEditorPage already use for this exact line. */}
            {/* The "RP" prefix here and on Grand Total below is a literal, same as before this
                setting existed — unaffected by showCurrencySymbol, which only governs the
                Unit Price/Line Amount table columns (see renderColumnCell). Totals always showed
                the symbol; table cells never did — two different pre-existing defaults, so one
                toggle can't drive both without changing one of them. */}
            {showDiscount && (
              <div className="flex items-center justify-between gap-8 border border-b-0 border-black px-2 py-1">
                <span>{discountLabel(invoice.discount_type, invoice.discount_percentage)}</span>
                <span>-RP {formatPrintNumber(invoice.discount_amount, printOptions.amountDecimals, numberFormat)}</span>
              </div>
            )}
            <div className="flex items-center justify-between gap-8 border border-black px-2 py-1 font-bold">
              <span>Grand Total</span>
              <span>RP {formatPrintNumber(invoice.grand_total, printOptions.amountDecimals, numberFormat)}</span>
            </div>
          </div>
        </div>

        {showSignatureBlock && (
          <div className="grid grid-cols-2 gap-8 pt-10">
            <div className="text-center">
              <p className="font-semibold">{invoice.customer?.customer_name ?? '—'}</p>
              <div className="mt-10 border-t border-black pt-1">({signatureLeftLabel})</div>
            </div>
            <div className="text-center">
              <p className="font-semibold">{companyName}</p>
              <div className="mt-10 border-t border-black pt-1">({signatureRightLabel})</div>
            </div>
          </div>
        )}
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
              <th className="py-0.5 text-right font-normal">AMOUNT</th>
            </tr>
          </thead>
          <tbody>
            {invoice.items.flatMap((item) => [
              <tr key={item.id}>
                <td className="pt-1 pr-1 align-top font-bold">{item.item_code ?? ''}</td>
                <td className="pt-1 pr-1 align-top"></td>
                <td className="pt-1 pr-1 text-right align-top">{formatPrintNumber(item.qty, printOptions.qtyDecimals, numberFormat)}</td>
                <td className="pt-1 pr-1 align-top">{item.uom ?? ''}</td>
                <td className="pt-1 pr-1 text-right align-top">{formatPrintNumber(item.rate, printOptions.priceDecimals, numberFormat)}</td>
                <td className="pt-1 text-right align-top">{formatPrintNumber(item.amount, printOptions.amountDecimals, numberFormat)}</td>
              </tr>,
              <tr key={`${item.id}-desc`}>
                <td className="pb-1" colSpan={6}>
                  <div>{item.item_name}</div>
                  {/* Appended below the description rather than as its own column — a 7th
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

        {showDiscount && (
          <div className="flex items-center justify-between border-t border-black pt-1">
            <span>{discountLabel(invoice.discount_type, invoice.discount_percentage)}</span>
            <span>-{formatPrintNumber(invoice.discount_amount, printOptions.amountDecimals, numberFormat)}</span>
          </div>
        )}
        <div className={`flex items-center justify-between pt-1 font-bold ${showDiscount ? '' : 'border-t border-black'}`}>
          <span>TOTAL</span>
          <span>{formatPrintNumber(invoice.grand_total, printOptions.amountDecimals, numberFormat)}</span>
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
        onChange={setPrintOptions}
        fields={['qty', 'price', 'amount']}
        showPaperType={format === 'a4'}
        paperTypeOptions={['a4', 'continuous', 'half']}
        showDiscount
        advanced={format === 'a4'}
        onResetToDefault={handleResetToDefault}
      />
    </div>
  )
}
