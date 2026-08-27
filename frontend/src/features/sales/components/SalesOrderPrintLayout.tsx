import type { ReactNode } from 'react'
import { type PrintOptions } from '@/shared/lib/printOptions'
import { terbilangUsd } from '@/shared/lib/numberToWords'
import type { CompanyPrintHeader } from '@/features/administration/types'
import type { SalesOrder } from '../types'

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

interface SalesOrderPrintLayoutProps {
  salesOrder: SalesOrder
  printOptions: PrintOptions
  companyName: string
  printHeader: CompanyPrintHeader | undefined
  /** Extra classes on the outer page div — the bulk-print page adds `print:break-before-page` to every copy after the first. */
  className?: string
}

/**
 * The classic dot-matrix-era SO.pdf body — extracted from SalesOrderPrintPage
 * (single-document print) so SalesOrderBulkPrintPage can stack N copies of
 * the exact same layout, one per page, for the bulk-print feature. No
 * behavior change to the single-document page; this is a pure extraction.
 */
export function SalesOrderPrintLayout({ salesOrder, printOptions, companyName, printHeader, className }: SalesOrderPrintLayoutProps) {
  return (
    <div
      className={`flex min-h-[27.3cm] flex-col text-black ${className ?? ''}`}
      style={{
        fontFamily: '"Times New Roman", "Tinos", "Liberation Serif", serif',
        fontSize: printOptions.fontSize === 'small' ? '11px' : printOptions.fontSize === 'large' ? '15px' : '13px',
      }}
    >
      <div className="relative">
        <img src="/kalindo-etam-logo.png" alt="PT Kalindo Etam" className="absolute left-0 top-0 h-14 w-auto" />
        <div className="text-center">
          <p className="text-xl font-bold">{companyName}</p>
          {printHeader?.npwp && (
            <p>
              <span className="font-bold">Co. Reg. No.</span> : {printHeader.npwp}
            </p>
          )}
          {printHeader?.address && <p>{printHeader.address}</p>}
          {printHeader?.phone && <p>TEL : {printHeader.phone}</p>}
          {printHeader?.email && <p>EMAIL : {printHeader.email}</p>}
        </div>
      </div>

      <p className="mt-3 text-center text-lg font-bold">SALES ORDER</p>
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
          <MetaRow label="Reference" value={salesOrder.reference ?? ''} />
          <MetaRow label="Payment Terms" value={salesOrder.terms_of_payment?.name ?? ''} />
          <MetaRow label="Customer" value={salesOrder.customer?.customer_code ?? ''} />
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
            // Per-line now — each line's own tax_amount (already resolved server-side), not a
            // document-wide rate, since different lines can carry different taxes.
            const inclusiveAmount = Number(item.amount) + Number(item.tax_amount)
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
            <li>All cash payment must be made directly to Account Department.</li>
            <li>
              The property of the goods in this bill shall remain with the seller until full payment has been received and the seller shall
              have a right of entry of seizure to retake possession in the event that full payment is not made on its due date.
            </li>
          </ol>
        </div>

        <div className="self-start border border-black">
          <div className="flex items-center justify-between gap-8 px-2 py-1 italic font-bold">
            <span>Amount Excluding Tax</span>
            <span>RP {formatNum(salesOrder.total_amount, 2)}</span>
          </div>
          <div className="flex items-center justify-between gap-8 px-2 py-1 italic font-bold">
            <span>Add Total Tax Amount</span>
            <span>RP {formatNum(salesOrder.tax_amount, 2)}</span>
          </div>
          <div className="flex items-center justify-between gap-8 border-t border-black px-2 py-1 font-bold">
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
  )
}
