import { useEffect, useRef, useState } from 'react'
import JsBarcode from 'jsbarcode'
import type { CompanyPrintHeader } from '@/features/administration/types'
import { DOTMATRIX_HALF_DEFAULTS, type PrintOptions } from '@/shared/lib/printOptions'
import { qtyDecimalPlaces } from '@/shared/lib/qty'
import { useAuth } from '@/app/AuthContext'
import type { Invoice } from '../types'
import { InvoiceLandscapeLayout } from './InvoiceLandscapeLayout'
import { InvoicePortraitLayout } from './InvoicePortraitLayout'
import { DOTMATRIX_AUTO_PAGE_HEIGHT_MM, DOTMATRIX_HALF_PAGE_SIZE_MM, PAPER_SIZES } from './invoicePrintConstants'

/** Roll format's paper width — actual thermal printer width unconfirmed (58mm vs 80mm are both
    common), so this is the one knob to turn if it turns out to be the wrong one. Content width
    leaves ~4mm margin each side, matching Roll_paper.pdf's effective print area. */
const ROLL_PAPER_WIDTH_MM = 80
const ROLL_CONTENT_WIDTH_MM = ROLL_PAPER_WIDTH_MM - 8

/** SI.pdf shows en-US grouping (comma thousands, dot decimal) with no currency symbol in the table — same reasoning as SO/DO print's own formatNum, not the shared id-ID formatMoney/formatQty. */
function formatNum(value: number | string, decimals: number): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

/** Roll_paper.pdf's own date format — same split-string approach as the two layouts' own ddmmyyyy helper, same reasoning. */
function formatYyyyDotMmDotDd(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${year}.${month}.${day}`
}

export interface InvoicePaperProps {
  invoice: Invoice
  printOptions: PrintOptions
  companyName: string
  printHeader: CompanyPrintHeader | undefined
  /** Bulk print passes `print:break-before-page` on every instance but the first — see InvoiceBulkPrintPage. */
  className?: string
}

/**
 * Two layouts, split by physical orientation, matching the legacy clouderp system's own two
 * templates: LANDSCAPE (InvoiceLandscapeLayout — Half, A5 landscape, a precise SkyBiz replica
 * using absolute mm positioning) and PORTRAIT (InvoicePortraitLayout — A4 and Continuous, built
 * with normal document flow so a long invoice paginates naturally). A4/Continuous do NOT reuse
 * Landscape's own layout scaled to a different paper size — that was tried and reverted; the two
 * physical orientations need genuinely different structures (Half has no Attn/Fax/Reference 2/Page
 * No, no "RP" prefix on the amount-in-words line; Portrait has all of those). Every mm/pt constant
 * either layout uses lives in the one shared invoicePrintConstants.ts file.
 *
 * Goods and Transportation invoices share both layouts identically. Two Transportation-only gaps
 * are deliberate, not bugs: ItemCode renders blank (createTransportation() never stores one — a
 * manual line has no Item master to source a code from), and "Location" renders blank
 * (Transportation invoices carry no sales_order_id/delivery_id, so there is no warehouse/branch
 * to source it from). UOM is no longer one of these gaps — a Transportation line optionally
 * carries the picked MiscellaneousItem's own UOM straight through.
 *
 * A separate "Roll" format renders alongside these from the same query/data — an 80mm
 * thermal-receipt style with its own sans-serif font, a Code128 barcode of the document number,
 * and one more schema gap of its own: "BIN" has no backing column anywhere (Item/InvoiceItem/
 * Warehouse all lack it), so it renders blank for every invoice, not just Transportation. Paper
 * Type is a single field inside "Print Options" (A4 / Half / Continuous / Roll) — there is no
 * separate toolbar toggle for it. Roll is entirely untouched by any of the above.
 *
 * Tax/Decimal/Discount are three independent checkboxes layered on top of every paper type:
 * Tax adds an HCTax column (A4/Continuous/Half) or TAX column (Roll) plus a TAX line in the
 * totals block; Discount adds a DISC line; Decimal switches the totals block between 0 and 2
 * decimals (table columns always show their own fixed decimals — Qty 0, money columns 2 —
 * regardless of this toggle). The totals block itself only expands into TOTAL/TAX/DISC/Grand
 * Total when Tax or Discount is on; with both off it collapses back to a bare Grand Total.
 *
 * The invoice "paper" itself — everything InvoicePrintPage used to render inline, extracted
 * unchanged so InvoiceBulkPrintPage can stack N of these (one per selected invoice, each on its
 * own printed page) without duplicating this ~250-line template. InvoicePrintPage keeps its own
 * toolbar/PrintOptionsDialog/state and just renders one of these; behavior and output are
 * byte-for-byte identical to before this extraction.
 *
 * Deliberately excludes the "Invoice Print Preview" toolbar and <PrintOptionsDialog> — those are
 * page-level (rendered once, outside any per-invoice loop), not per-paper.
 *
 * Each instance owns its own barcode/roll-height-measurement refs and effects, so every invoice in
 * a batch gets its own correctly measured Roll content height — however, the computed `@page` size
 * is a document-wide CSS rule, not scoped per element: when multiple instances in one page are all
 * Roll format with different content heights, only the last one rendered actually controls the
 * printed page size for all of them. Not a new issue introduced here (the whole point of this
 * component is byte-identical behavior to the single-invoice page it was extracted from) — just
 * something that only bites once more than one of these exists on one page at once (bulk print).
 */
export function InvoicePaper({ invoice, printOptions, companyName, printHeader, className }: InvoicePaperProps) {
  const barcodeRef = useRef<SVGSVGElement>(null)
  const rollContentRef = useRef<HTMLDivElement>(null)
  const { user } = useAuth()

  const format = printOptions.paperType === 'roll' ? 'roll' : 'a4'
  const isDotMatrix = printOptions.paperType === 'dotmatrix_half'
  // See PrintPaperType's own 'dotmatrix_auto' doc comment (printOptions.ts) for why this is a
  // separate mode from 'half'/'dotmatrix_half' rather than a tweak to either.
  const isDotMatrixAuto = printOptions.paperType === 'dotmatrix_auto'
  const isLandscape = format === 'a4' && (printOptions.paperType === 'half' || isDotMatrix)
  const showDiscount = printOptions.showDiscount ?? false
  const showTax = printOptions.showTax ?? false
  const showBreakdown = showTax || showDiscount
  const totalsDecimals = printOptions.showDecimalTotals ? 2 : 0

  const documentNumber = invoice.document_number
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

  const attn = invoice.sales_order?.attention ?? ''
  const tel = invoice.sales_order?.tel ?? invoice.customer?.phone ?? ''
  const fax = invoice.sales_order?.fax ?? ''
  // location_warehouse_id is the editable, printed Location field (every Goods invoice has one —
  // see Invoice::locationWarehouse()); the old derived chain stays as a fallback only for a
  // pre-backfill invoice where it's somehow still null. Transportation still renders blank, unaffected.
  const location = invoice.location_warehouse?.name ?? invoice.delivery?.warehouse?.name ?? invoice.warehouse?.name ?? ''
  // dotmatrix_auto borrows 'half' only for its 210mm WIDTH (the physical stationery this mode's
  // one real-world stakeholder actually loads into their dot-matrix printer) — NOT its 148.5mm
  // height, which would otherwise double as InvoicePortraitLayout's pagination budget and is far
  // too short for that layout's own header+footer overhead (see DOTMATRIX_AUTO_PAGE_HEIGHT_MM's
  // own comment). The wrapper's own minHeight is separately skipped below for this mode too — it
  // never forces the rendered page to 148.5mm either.
  const paperKey =
    printOptions.paperType === 'half' || isDotMatrix || isDotMatrixAuto
      ? 'half'
      : printOptions.paperType === 'continuous'
        ? 'continuous'
        : 'a4'
  const paperSize = PAPER_SIZES[paperKey]
  const dotMatrixHeightMm = printOptions.dotMatrixHeightMm ?? DOTMATRIX_HALF_DEFAULTS.heightMm
  const dotMatrixOffsetLeftMm = printOptions.dotMatrixOffsetLeftMm ?? DOTMATRIX_HALF_DEFAULTS.offsetLeftMm
  const dotMatrixOffsetTopMm = printOptions.dotMatrixOffsetTopMm ?? DOTMATRIX_HALF_DEFAULTS.offsetTopMm

  return (
    <div
      className={
        (format === 'roll'
          ? 'mx-auto flex flex-col gap-4 bg-white p-6 text-foreground shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:p-[2mm] print:shadow-none'
          // Landscape/Portrait both render at the paper's own PHYSICAL size — @page margin is
          // always 0 (invoicePrintConstants.ts), with the 10mm content margin coming from
          // Portrait's own padding or Landscape's baked-in coordinates, never a browser @page
          // margin — so this wrapper's size is identical on screen and in print, with no
          // clipping risk to reconcile and no print-only size override needed.
          : 'mx-auto bg-white p-6 text-foreground shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:p-0 print:shadow-none') +
        (className ? ` ${className}` : '')
      }
      style={
        format === 'roll'
          ? { width: `${ROLL_CONTENT_WIDTH_MM}mm` }
          : {
              width: `${paperSize.widthMm}mm`,
              // Landscape's own div already sets an explicit height (148.5mm, or dotMatrixHeightMm)
              // — duplicating that exact number here as this wrapper's own min-height stacks a
              // second zero-tolerance box on top of the first for no benefit (this wrapper has no
              // other content once isLandscape, so its rendered height already equals the child's).
              // Portrait (A4/Continuous) has no such child-imposed height — it's normal document
              // flow — so it still needs this to visually fill the page for a short invoice.
              minHeight: isLandscape || isDotMatrixAuto ? undefined : `${paperSize.heightMm}mm`,
              breakAfter: 'avoid',
              pageBreakAfter: 'avoid',
            }
      }
    >
      {/* margin: 0 on @page suppresses the browser's own print header/footer chrome (page title
          + date on top, URL + page number on bottom) — that's not part of the document, it's
          browser UI. Document margins come from this wrapper's own padding instead (Roll) or
          each layout's own 10mm content inset (Landscape/Portrait — see invoicePrintConstants.ts's
          MARGIN_MM), never a real @page margin, so screen and print always agree on paper size.

          Dot-matrix mode locks `size` to DOTMATRIX_HALF_PAGE_SIZE_MM (9.5in x 5.5in) so page count
          no longer depends on whatever custom paper form is registered in a given device's printer
          driver -- this used to omit `size` entirely for one stakeholder's printer whose driver
          disagreed with any size we asked for, but that traded away predictable page count for
          every other device, which is the worse failure mode.

          Dot Matrix (Auto) is the one deliberate exception to that lesson: it's for a driver whose
          registered paper form matches NEITHER fixed size above, so Chrome substitutes the
          driver's own size regardless of what we ask for — omitting `size` here just makes that
          explicit instead of fighting it, and InvoicePortraitLayout's own auto-height rendering
          (not a fixed-height absolutely-positioned canvas) is what actually keeps this safe. */}
      <style>
        {(isDotMatrix
          ? `@page { size: ${DOTMATRIX_HALF_PAGE_SIZE_MM.widthMm}mm ${DOTMATRIX_HALF_PAGE_SIZE_MM.heightMm}mm; margin: 0; }`
          : isDotMatrixAuto
            ? '@page { margin: 0; }'
            : format === 'roll'
              ? `@page { size: ${ROLL_PAPER_WIDTH_MM}mm ${rollHeightMm}mm; margin: 0; }`
              : `@page { size: ${paperSize.widthMm}mm ${paperSize.heightMm}mm; margin: 0; }`) +
          /* Without this, Chrome drops background/border colors that rely on print-color-adjust
             defaults, thinning out table borders and the totals box on some printers/PDF drivers. */
          ' @media print { * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }'}
      </style>

      {format === 'a4' && isLandscape && (
        <InvoiceLandscapeLayout
          invoice={invoice}
          companyName={companyName}
          printHeader={printHeader}
          customerTel={tel}
          location={location}
          signatureLeftLabel={printOptions.signatureLeftLabel ?? 'AUTHORISED SIGNATURE'}
          signatureRightLabel={printOptions.signatureRightLabel ?? 'AUTHORISED SIGNATURE'}
          fontFamily={printOptions.fontFamily}
          showTax={showTax}
          showDiscount={showDiscount}
          showDecimalTotals={printOptions.showDecimalTotals}
          heightMm={isDotMatrix ? dotMatrixHeightMm : undefined}
          offsetLeftMm={isDotMatrix ? dotMatrixOffsetLeftMm : undefined}
          offsetTopMm={isDotMatrix ? dotMatrixOffsetTopMm : undefined}
        />
      )}

      {format === 'a4' && !isLandscape && (
        <InvoicePortraitLayout
          invoice={invoice}
          companyName={companyName}
          printHeader={printHeader}
          attn={attn}
          customerTel={tel}
          fax={fax}
          location={location}
          signatureLeftLabel={printOptions.signatureLeftLabel ?? 'AUTHORISED SIGNATURE'}
          signatureRightLabel={printOptions.signatureRightLabel ?? 'AUTHORISED SIGNATURE'}
          fontFamily={printOptions.fontFamily}
          showTax={showTax}
          showDiscount={showDiscount}
          showDecimalTotals={printOptions.showDecimalTotals}
          pageHeightMm={isDotMatrixAuto ? DOTMATRIX_AUTO_PAGE_HEIGHT_MM : paperSize.heightMm}
          autoHeight={isDotMatrixAuto}
        />
      )}

      {format === 'roll' && (
      <div ref={rollContentRef} className="flex flex-col gap-2 text-black" style={{ fontFamily: 'Arial, Helvetica, sans-serif', fontSize: '9px' }}>
        <div className="flex flex-col items-center gap-0.5 text-center">
          <p className="text-[11px] font-bold">{companyName}</p>
          {printHeader?.npwp && <p>Co Reg. No. : {printHeader.npwp}</p>}
          {printHeader?.address && <p>{printHeader.address}</p>}
          {printHeader?.phone && <p>Tel : {printHeader.phone}</p>}
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
                <td className="pt-1 pr-1 text-right align-top">{formatNum(item.qty, qtyDecimalPlaces(item.qty_category ?? 'unit'))}</td>
                <td className="pt-1 pr-1 align-top">{item.uom ?? ''}</td>
                <td className="pt-1 pr-1 text-right align-top">{formatNum(item.rate, 2)}</td>
                {showTax && <td className="pt-1 pr-1 text-right align-top">{formatNum(item.tax_amount, 2)}</td>}
                {/* Net of this line's own discount — reconciles with the TAX column (already net-based). */}
                <td className="pt-1 text-right align-top">{formatNum(item.net_amount, 2)}</td>
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

        {invoice.remarks && <p className="whitespace-pre-line [overflow-wrap:anywhere]">{invoice.remarks}</p>}

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
    </div>
  )
}
