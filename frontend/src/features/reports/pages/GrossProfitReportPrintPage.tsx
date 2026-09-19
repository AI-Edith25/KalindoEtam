import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { formatDate } from '@/lib/utils'
import { defaultPrintOptions, formatMoney, formatQty, PRINT_FONT_SIZE_PX, type PrintOptions } from '@/shared/lib/printOptions'
import { fetchGrossProfit } from '../api/grossProfitApi'
import type { GrossProfitGroupBy } from '../types'

function formatMargin(value: number): string {
  return `${value.toFixed(2)}%`
}

/** Read-only print view of Gross Profit's current filters — extracted from Sales Report's shared print page (2026-09-19) when Gross Profit (formerly the Margin tab) became its own standalone report. */
export function GrossProfitReportPrintPage() {
  const [searchParams] = useSearchParams()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(defaultPrintOptions)
  const [optionsOpen, setOptionsOpen] = useState(false)

  const dateFrom = searchParams.get('date_from') ?? undefined
  const dateTo = searchParams.get('date_to') ?? undefined
  const group = (searchParams.get('group') as GrossProfitGroupBy | null) ?? 'item'
  const params = {
    customer_id: searchParams.get('customer_id') ?? undefined,
    item_id: searchParams.get('item_id') ?? undefined,
    sales_person_id: searchParams.get('sales_person_id') ?? undefined,
    branch_id: searchParams.get('branch_id') ?? undefined,
    date_from: dateFrom,
    date_to: dateTo,
  }

  const grossProfitQuery = useQuery({
    queryKey: ['gross-profit-print', params, group],
    queryFn: () => fetchGrossProfit({ page: 1, per_page: 500, group, ...params }),
  })

  const total = grossProfitQuery.data?.meta.total ?? 0

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4 bg-white p-6 text-foreground shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:max-w-none print:p-0 print:shadow-none">
      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Gross Profit Print Preview</h1>
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

      {total > 500 && (
        <p className="rounded border border-amber-500/50 bg-amber-500/10 p-2 text-sm print:hidden">
          Showing 500 of {total} rows — narrow the filters to print everything.
        </p>
      )}

      <div className="border-2 border-foreground/80" style={{ fontSize: PRINT_FONT_SIZE_PX[printOptions.fontSize] }}>
        <div className="border-b-2 border-foreground/80 p-3">
          <h2 className="text-lg font-bold">GROSS PROFIT REPORT</h2>
          {(dateFrom || dateTo) && (
            <p>
              {dateFrom ? formatDate(dateFrom) : '—'} to {dateTo ? formatDate(dateTo) : '—'}
            </p>
          )}
          {grossProfitQuery.data?.meta.kpis && (
            <p>
              Total Penjualan: {formatMoney(grossProfitQuery.data.meta.kpis.total_sales, printOptions.amountDecimals)} — Total Profit:{' '}
              {formatMoney(grossProfitQuery.data.meta.kpis.total_profit, printOptions.amountDecimals)} — Margin Rata-rata:{' '}
              {formatMargin(grossProfitQuery.data.meta.kpis.avg_margin_pct)}
            </p>
          )}
        </div>

        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b-2 border-foreground/80 text-left">
              {group === 'item' && (
                <>
                  <th className="border-r-2 border-foreground/80 p-2">Item Code</th>
                  <th className="border-r-2 border-foreground/80 p-2">Item Name</th>
                  <th className="border-r-2 border-foreground/80 p-2 text-right">Qty</th>
                </>
              )}
              {group === 'customer' && (
                <>
                  <th className="border-r-2 border-foreground/80 p-2">Customer Code</th>
                  <th className="border-r-2 border-foreground/80 p-2">Customer Name</th>
                  <th className="border-r-2 border-foreground/80 p-2 text-right">Jml Invoice</th>
                </>
              )}
              {group === 'invoice' && (
                <>
                  <th className="border-r-2 border-foreground/80 p-2">Date</th>
                  <th className="border-r-2 border-foreground/80 p-2">No Invoice</th>
                  <th className="border-r-2 border-foreground/80 p-2">Customer</th>
                  <th className="border-r-2 border-foreground/80 p-2">Sales Person</th>
                </>
              )}
              <th className="border-r-2 border-foreground/80 p-2 text-right">Penjualan</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">HPP</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">Profit</th>
              <th className="p-2 text-right">Margin %</th>
            </tr>
          </thead>
          <tbody>
            {(grossProfitQuery.data?.data ?? []).map((row) => (
              <tr key={row.id} className="border-b border-foreground/30">
                {group === 'item' && (
                  <>
                    <td className="border-r-2 border-foreground/80 p-2">{row.item_code ?? '—'}</td>
                    <td className="border-r-2 border-foreground/80 p-2">{row.item_name}</td>
                    <td className="border-r-2 border-foreground/80 p-2 text-right">{formatQty(row.qty ?? 0, printOptions.qtyDecimals)}</td>
                  </>
                )}
                {group === 'customer' && (
                  <>
                    <td className="border-r-2 border-foreground/80 p-2">{row.customer_code ?? '—'}</td>
                    <td className="border-r-2 border-foreground/80 p-2">{row.customer_name}</td>
                    <td className="border-r-2 border-foreground/80 p-2 text-right">{row.invoice_count ?? 0}</td>
                  </>
                )}
                {group === 'invoice' && (
                  <>
                    <td className="border-r-2 border-foreground/80 p-2">{formatDate(row.date)}</td>
                    <td className="border-r-2 border-foreground/80 p-2">{row.document_number ?? '—'}</td>
                    <td className="border-r-2 border-foreground/80 p-2">{row.customer_name}</td>
                    <td className="border-r-2 border-foreground/80 p-2">{row.sales_person_name ?? 'Unassigned'}</td>
                  </>
                )}
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(row.amount, printOptions.amountDecimals)}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(row.cost_amount, printOptions.amountDecimals)}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(row.profit, printOptions.amountDecimals)}</td>
                <td className="p-2 text-right">{formatMargin(row.margin_pct)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <PrintOptionsDialog open={optionsOpen} onOpenChange={setOptionsOpen} options={printOptions} onChange={setPrintOptions} />
    </div>
  )
}
