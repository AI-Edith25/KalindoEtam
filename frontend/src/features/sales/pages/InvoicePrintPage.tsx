import { useState, type ReactNode } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { type PrintOptions } from '@/shared/lib/printOptions'
import { useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { fetchInvoice } from '../api/invoiceApi'

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
 */
export function InvoicePrintPage() {
  const { id } = useParams<{ id: string }>()
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
  const attn = invoice.sales_order?.attention ?? ''
  const tel = invoice.sales_order?.tel ?? invoice.customer?.phone ?? ''
  const fax = invoice.sales_order?.fax ?? ''
  const location = invoice.delivery?.warehouse?.name ?? ''

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-[12mm]">
      {/* margin: 0 on @page suppresses the browser's own print header/footer chrome (page title
          + date on top, URL + page number on bottom) — that's not part of the document, it's
          browser UI. Document margins come from this wrapper's own print:p-[12mm] instead. */}
      <style>{'@page { size: A4; margin: 0; }'}</style>

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

      <div
        className="flex min-h-[27.3cm] flex-col text-black"
        style={{
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
              <th className="py-1 pr-2 text-right font-normal">Qty</th>
              <th className="py-1 pr-2 font-normal">UOM</th>
              <th className="py-1 pr-2 text-right font-normal">HCUnitCost</th>
              <th className="py-1 text-right font-normal">HCLineAmt</th>
            </tr>
          </thead>
          <tbody>
            {invoice.items.map((item, index) => (
              <tr key={item.id}>
                <td className="py-1 pr-2 align-top">{index + 1}</td>
                <td className="py-1 pr-2 align-top">{item.item_code ?? ''}</td>
                <td className="py-1 pr-2 align-top">{item.item_name}</td>
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

      <PrintOptionsDialog open={optionsOpen} onOpenChange={setOptionsOpen} options={printOptions} onChange={setPrintOptions} fields={['qty', 'price', 'amount']} />
    </div>
  )
}
