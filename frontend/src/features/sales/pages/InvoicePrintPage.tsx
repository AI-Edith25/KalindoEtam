import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import JsBarcode from 'jsbarcode'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { PRINT_PAPER_PAGE_CSS, type PrintOptions } from '@/shared/lib/printOptions'
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
  const { user } = useAuth()
  const [printOptions, setPrintOptions] = useState<PrintOptions>({
    fontSize: 'medium',
    paperType: 'a4',
    // SI.pdf shows plain "200" for Qty (no decimals) but "21,000.00" / "4,200,000.00" for
    // price/amount — unlike SO.pdf's uniform 2-decimal default.
    qtyDecimals: 0,
    priceDecimals: 2,
    amountDecimals: 2,
  })
  const [optionsOpen, setOptionsOpen] = useState(false)
  // Continuous is a paper-size variant of the A4 layout (same classic tabular content, just a
  // different @page size/margin) — orthogonal to the Roll format above, which is a completely
  // different 80mm thermal-receipt layout. Only meaningful when format === 'a4'.
  const isContinuous = format === 'a4' && printOptions.paperType === 'continuous'

  const invoiceQuery = useQuery({
    queryKey: ['invoices', id],
    queryFn: () => fetchInvoice(id!),
  })
  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()

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
          : isContinuous
            // @page's own margin (PRINT_PAPER_PAGE_CSS.continuous) does the inset here — no
            // extra wrapper padding on top of it, unlike A4's margin:0-on-@page + p-[12mm].
            ? 'mx-auto flex max-w-3xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0'
            : 'mx-auto flex max-w-3xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-[12mm]'
      }
      style={format === 'roll' ? { width: `${ROLL_CONTENT_WIDTH_MM}mm` } : undefined}
    >
      {/* margin: 0 on @page suppresses the browser's own print header/footer chrome (page title
          + date on top, URL + page number on bottom) — that's not part of the document, it's
          browser UI. Document margins come from this wrapper's own padding instead (A4/Roll) or
          @page's own margin (Continuous, see PRINT_PAPER_PAGE_CSS — kept as a single source of
          truth rather than a second hardcoded copy here). */}
      <style>
        {format === 'roll'
          ? `@page { size: ${ROLL_PAPER_WIDTH_MM}mm ${rollHeightMm}mm; margin: 0; }`
          : (isContinuous ? PRINT_PAPER_PAGE_CSS.continuous : '@page { size: A4; margin: 0; }')}
      </style>

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Invoice Print Preview</h1>
        <div className="flex items-center gap-2">
          <div className="flex items-center gap-1 rounded-md border p-1">
            <Button size="sm" variant={format === 'a4' ? 'default' : 'ghost'} onClick={() => setSearchParams({})}>
              A4
            </Button>
            <Button size="sm" variant={format === 'roll' ? 'default' : 'ghost'} onClick={() => setSearchParams({ format: 'roll' })}>
              Roll
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
        className="flex flex-col text-black"
        style={{
          minHeight: isContinuous ? `${CONTINUOUS_CONTENT_HEIGHT_CM}cm` : '27.3cm',
          fontFamily: '"Times New Roman", "Tinos", "Liberation Serif", serif',
          fontSize: printOptions.fontSize === 'small' ? '11px' : printOptions.fontSize === 'large' ? '15px' : '13px',
        }}
      >
        <div className="flex flex-col gap-0.5">
          <p className="text-xl font-bold">{companyName}</p>
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
            <MetaRow label="Page No" value="1 of 1" />
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
              <th className="py-1 text-right font-normal">HCLineAmt</th>
            </tr>
          </thead>
          <tbody>
            {invoice.items.map((item, index) => (
              // break-inside-avoid only for Continuous — a genuinely multi-page invoice on
              // continuous stock must not split a row across the perforation; A4 is left
              // exactly as it already behaved (no page-break rule at all).
              <tr key={item.id} className={isContinuous ? 'break-inside-avoid' : undefined}>
                <td className="py-1 pr-2 align-top">{index + 1}</td>
                <td className="py-1 pr-2 align-top">{item.item_code ?? ''}</td>
                <td className="py-1 pr-2 align-top">{item.item_name}</td>
                <td className="py-1 pr-2 align-top">{item.sales_person?.name ?? invoice.sales_person?.name ?? ''}</td>
                <td className="py-1 pr-2 text-right align-top">{formatNum(item.qty, printOptions.qtyDecimals)}</td>
                <td className="py-1 pr-2 align-top">{item.uom ?? ''}</td>
                <td className="py-1 pr-2 text-right align-top">{formatNum(item.rate, printOptions.priceDecimals)}</td>
                <td className="py-1 text-right align-top">{formatNum(item.amount, printOptions.amountDecimals)}</td>
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
          </div>

          <div className="self-start border border-black">
            <div className="flex items-center justify-between gap-8 px-2 py-1 font-bold">
              <span>Grand Total</span>
              <span>RP {formatNum(invoice.grand_total, printOptions.amountDecimals)}</span>
            </div>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-8 pt-10">
          <div className="text-center">
            <p className="font-semibold">{invoice.customer?.customer_name ?? '—'}</p>
            <div className="mt-10 border-t border-black pt-1">(AUTHORISED SIGNATURE)</div>
          </div>
          <div className="text-center">
            <p className="font-semibold">{companyName}</p>
            <div className="mt-10 border-t border-black pt-1">(AUTHORISED SIGNATURE)</div>
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
              <th className="py-0.5 text-right font-normal">AMOUNT</th>
            </tr>
          </thead>
          <tbody>
            {invoice.items.flatMap((item) => [
              <tr key={item.id}>
                <td className="pt-1 pr-1 align-top font-bold">{item.item_code ?? ''}</td>
                <td className="pt-1 pr-1 align-top"></td>
                <td className="pt-1 pr-1 text-right align-top">{formatNum(item.qty, printOptions.qtyDecimals)}</td>
                <td className="pt-1 pr-1 align-top">{item.uom ?? ''}</td>
                <td className="pt-1 pr-1 text-right align-top">{formatNum(item.rate, printOptions.priceDecimals)}</td>
                <td className="pt-1 text-right align-top">{formatNum(item.amount, printOptions.amountDecimals)}</td>
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

        <div className="flex items-center justify-between border-t border-black pt-1 font-bold">
          <span>TOTAL</span>
          <span>{formatNum(invoice.grand_total, printOptions.amountDecimals)}</span>
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
      />
    </div>
  )
}
