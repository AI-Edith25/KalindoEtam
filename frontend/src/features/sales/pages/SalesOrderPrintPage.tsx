import { useState, type ReactNode } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { type PrintOptions } from '@/shared/lib/printOptions'
import { terbilangUsd } from '@/shared/lib/numberToWords'
import { useCompanyBranding, useCompanyPrintHeader, useBrandingLogoObjectUrl } from '@/features/administration/hooks/useCompany'
import { fetchSalesOrder } from '../api/salesOrderApi'

/** SO.pdf shows en-US grouping (comma thousands, dot decimal) with no currency symbol in the table — the app's shared formatMoney/formatQty (id-ID/IDR style) don't match, so this page formats plain numbers itself. */
function formatNum(value: number | string, decimals: number): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

/** order_date arrives as a plain YYYY-MM-DD string — split it directly rather than re-parsing through a Date object, which shifts the calendar date in any timezone ahead of UTC (same pitfall dateMath.ts's addDays() already documents). */
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
 * Classic dot-matrix-era layout matching the legacy system's SO print exactly (SO.pdf) —
 * deliberately NOT Invoice/Delivery's modern bordered style. Same plumbing as those pages
 * (print:hidden toolbar, PrintOptionsDialog, window.print()), but its own from-scratch JSX;
 * no shared print "shell" component exists in this codebase to extend (Invoice/Delivery print
 * pages are themselves independent copies of the same skeleton).
 */
export function SalesOrderPrintPage() {
  const { id } = useParams<{ id: string }>()
  const [printOptions, setPrintOptions] = useState<PrintOptions>({
    fontSize: 'medium',
    paperType: 'a4',
    // SO.pdf shows 2 decimals throughout (150.00, 33,333.34) — this page's own default,
    // unlike Invoice/Delivery's 0-decimal legacy-compat default.
    qtyDecimals: 2,
    priceDecimals: 2,
    amountDecimals: 2,
  })
  const [optionsOpen, setOptionsOpen] = useState(false)

  const salesOrderQuery = useQuery({
    queryKey: ['sales-orders', id],
    queryFn: () => fetchSalesOrder(id!),
  })
  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()
  const logoObjectUrl = useBrandingLogoObjectUrl(brandingQuery.data?.logo_url)

  if (salesOrderQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const salesOrder = salesOrderQuery.data
  if (!salesOrder) return null

  const taxRatePercent = salesOrder.tax ? Number(salesOrder.tax.rate) : 0
  const companyName = brandingQuery.data?.name ?? 'PT. KALINDO ETAM'

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0">
      <style>{'@page { size: A4; margin: 1cm; }'}</style>

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Sales Order Print Preview</h1>
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
        className="flex min-h-[27.7cm] flex-col text-black"
        style={{ fontFamily: "'Times New Roman', Times, serif", fontSize: printOptions.fontSize === 'small' ? '11px' : printOptions.fontSize === 'large' ? '15px' : '13px' }}
      >
        <div className="flex items-start gap-4">
          {logoObjectUrl && <img src={logoObjectUrl} alt="" className="h-16 w-auto shrink-0" />}
          <div className="flex-1 text-center">
            <p className="text-xl font-bold">{companyName}</p>
            {printHeaderQuery.data?.npwp && (
              <p>
                <span className="font-bold">Co. Reg. No.</span> : {printHeaderQuery.data.npwp}
              </p>
            )}
            {printHeaderQuery.data?.address && <p>{printHeaderQuery.data.address}</p>}
            {printHeaderQuery.data?.phone && <p>TEL : {printHeaderQuery.data.phone}</p>}
            {printHeaderQuery.data?.email && <p>EMAIL : {printHeaderQuery.data.email}</p>}
          </div>
          {logoObjectUrl && <div className="w-16 shrink-0" />}
        </div>

        <p className="mt-2 text-center text-lg font-bold">SALES ORDER</p>
        <hr className="mt-2 border-black" />

        <div className="mt-2 grid grid-cols-2 gap-4 border-b border-black pb-2">
          <div>
            <p className="font-bold">{salesOrder.customer?.customer_name ?? '—'}</p>
            <div className="mt-2 flex flex-col gap-0.5">
              <MetaRow label="Attn" value={salesOrder.attention ?? ''} />
              <MetaRow label="Tel" value={salesOrder.tel ?? ''} />
              <MetaRow label="Fax" value={salesOrder.fax ?? ''} />
            </div>
          </div>
          <div className="flex flex-col gap-0.5">
            <MetaRow label="NO" value={salesOrder.document_number ?? '—'} bold />
            <MetaRow label="Date" value={formatDdMmYyyy(salesOrder.order_date)} />
            <MetaRow label="Reference 1 #" value={salesOrder.reference ?? ''} />
            <MetaRow label="Reference 2 #" value="" />
            <MetaRow label="Payment Terms" value={salesOrder.terms_of_payment?.name ?? ''} />
            <MetaRow label="Customer #" value={salesOrder.customer?.customer_code ?? ''} />
            <MetaRow label="Sales Person" value={salesOrder.sales_person?.name ?? ''} />
            <MetaRow label="Page" value="1 of 1" />
          </div>
        </div>

        <table className="w-full border-collapse text-left">
          <thead>
            <tr className="border-b border-black">
              <th className="py-1 pr-2 font-normal">NO</th>
              <th className="py-1 pr-2 font-normal">ITEM NO.</th>
              <th className="py-1 pr-2 font-normal">DESCRIPTION</th>
              <th className="py-1 pr-2 text-right font-normal">QTY</th>
              <th className="py-1 pr-2 font-normal">UOM</th>
              <th className="py-1 pr-2 text-right font-normal">U.PRICE</th>
              <th className="py-1 text-right font-normal">AMOUNT</th>
            </tr>
          </thead>
          <tbody>
            {salesOrder.items.map((item, index) => {
              const inclusiveAmount = Number(item.amount) * (1 + taxRatePercent / 100)
              return (
                <tr key={item.id}>
                  <td className="py-1 pr-2 align-top">{index + 1}</td>
                  <td className="py-1 pr-2 align-top">{item.item_code}</td>
                  <td className="py-1 pr-2 align-top">{item.item_name}</td>
                  <td className="py-1 pr-2 text-right align-top">{formatNum(item.qty, printOptions.qtyDecimals)}</td>
                  <td className="py-1 pr-2 align-top">{item.uom}</td>
                  <td className="py-1 pr-2 text-right align-top">{formatNum(item.rate, printOptions.priceDecimals)}</td>
                  <td className="py-1 text-right align-top">{formatNum(inclusiveAmount, printOptions.amountDecimals)}</td>
                </tr>
              )
            })}
          </tbody>
        </table>

        <div className="flex-1" />

        <p>RP : {terbilangUsd(salesOrder.grand_total)}</p>

        <div className="mt-2 grid grid-cols-2 gap-4">
          <div className="border border-black p-2">
            <p className="font-bold italic">E. &amp; O.E</p>
            <ol className="mt-1 list-decimal pl-4">
              <li>
                All cheque and payment should be crossed and made payable to
                <br />
                <span className="font-bold">PT. KALINDO ETAM</span>
                <br />
                BCA NO A/C. 0271461312
              </li>
              <li>All cash payment must be made directly to Account Department.</li>
              <li>
                The property of the goods in this bill shall remain with the seller until full payment has been received and the seller shall
                have a right of entry of seizure to retake possession in the event that full payment is not made on its due date.
              </li>
            </ol>
          </div>

          <div className="self-start border border-black">
            <div className="flex items-center justify-between px-2 py-1 italic font-bold">
              <span>Amount Excluding Tax</span>
              <span>RP {formatNum(salesOrder.total_amount, 2)}</span>
            </div>
            <div className="flex items-center justify-between px-2 py-1 italic font-bold">
              <span>Add Total Tax Amount</span>
              <span>RP {formatNum(salesOrder.tax_amount, 2)}</span>
            </div>
            <div className="flex items-center justify-between border-t border-black px-2 py-1 font-bold">
              <span>Total Amount Due</span>
              <span>RP {formatNum(salesOrder.grand_total, 2)}</span>
            </div>
          </div>
        </div>

        <p className="mt-4 font-bold">For {companyName}</p>

        <div className="mt-16 grid grid-cols-2 gap-8">
          <div className="border-t border-black pt-1">(AUTHORISED SIGNATURE)</div>
          <div className="border-t border-black pt-1">APPROVED BY</div>
        </div>
      </div>

      <PrintOptionsDialog open={optionsOpen} onOpenChange={setOptionsOpen} options={printOptions} onChange={setPrintOptions} fields={['qty', 'price', 'amount']} />
    </div>
  )
}
