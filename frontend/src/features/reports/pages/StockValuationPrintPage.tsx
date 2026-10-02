import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { formatQty } from '@/shared/lib/printOptions'
import { fetchStockValuationPrintReport } from '@/features/inventory/api/stockValuationApi'

/** dd/mm/yyyy — matches the legacy report's own date format, not this app's usual "03 Sep 2026" style. */
function formatSlashDate(value: string): string {
  const d = new Date(value)
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`
}

/**
 * Classic paper replica of the legacy "STOCK VALUATION REPORT" (see StockValuation.pdf) — one flat
 * row per (item, warehouse) with Opening/In/Out/Closing qty and Closing Value, sorted by item
 * code. The Valuation tab's own date range is required on screen already, so (unlike Balance) this
 * print view just inherits it from the tab's filters instead of carrying its own default.
 */
export function StockValuationPrintPage() {
  const [searchParams] = useSearchParams()
  const warehouseId = searchParams.get('warehouse_id') ?? undefined
  const itemGroupId = searchParams.get('item_group_id') ?? undefined
  const itemId = searchParams.get('item_id') ?? undefined
  const search = searchParams.get('search') ?? undefined

  const today = new Date().toISOString().slice(0, 10)
  const monthStart = `${today.slice(0, 7)}-01`
  const [dateFrom, setDateFrom] = useState(searchParams.get('date_from') ?? monthStart)
  const [dateTo, setDateTo] = useState(searchParams.get('date_to') ?? today)

  const reportQuery = useQuery({
    queryKey: ['stock-valuation-print', warehouseId, itemGroupId, itemId, search, dateFrom, dateTo],
    queryFn: () =>
      fetchStockValuationPrintReport({
        date_from: dateFrom,
        date_to: dateTo,
        ...(warehouseId ? { warehouse_id: warehouseId } : {}),
        ...(itemGroupId ? { item_group_id: itemGroupId } : {}),
        ...(itemId ? { item_id: itemId } : {}),
        ...(search ? { search } : {}),
      }),
  })

  const report = reportQuery.data
  const rows = report?.rows ?? []
  const totals = rows.reduce(
    (acc, row) => ({
      opening_qty: acc.opening_qty + row.opening_qty,
      qty_in: acc.qty_in + row.qty_in,
      qty_out: acc.qty_out + row.qty_out,
      closing_qty: acc.closing_qty + row.closing_qty,
      closing_value: acc.closing_value + row.closing_value,
    }),
    { opening_qty: 0, qty_in: 0, qty_out: 0, closing_qty: 0, closing_value: 0 },
  )

  return (
    <div className="mx-auto flex max-w-[297mm] flex-col gap-4 bg-white p-6 text-black shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:max-w-none print:p-[10mm] print:shadow-none">
      <style>{'@page { size: A4 landscape; margin: 0; }'}</style>

      <div className="flex items-center justify-between gap-3 print:hidden">
        <h1 className="text-xl font-semibold">Stock Valuation Print Preview</h1>
        <div className="flex items-center gap-2">
          <label className="flex items-center gap-1 text-sm">
            From <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="w-40" />
          </label>
          <label className="flex items-center gap-1 text-sm">
            To <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="w-40" />
          </label>
          <Button onClick={() => window.print()}>
            <Printer className="size-4" />
            Print
          </Button>
        </div>
      </div>

      <div className="flex flex-col text-[11px]" style={{ fontFamily: 'Arial, Helvetica, sans-serif' }}>
        <div className="text-center font-bold" style={{ fontSize: '16px' }}>
          STOCK VALUATION REPORT
        </div>
        <div className="text-center">
          {formatSlashDate(dateFrom)} - {formatSlashDate(dateTo)}
        </div>
        <div className="text-center">Location Code : {report?.meta.location_label ?? '—'}</div>

        <div className="mt-2 flex items-start justify-between">
          <span className="font-bold">{report?.meta.company_name ?? 'PT. KALINDO ETAM'}</span>
          {report && <span>{formatSlashDate(report.meta.generated_at)}</span>}
        </div>

        <table className="mt-1 w-full border-collapse text-left">
          <thead>
            <tr className="border-b border-black">
              <th className="py-1 pr-2 font-bold">ITEM #</th>
              <th className="py-1 pr-2 font-bold">DESCRIPTION</th>
              <th className="py-1 pr-2 font-bold">LOCATION CODE</th>
              <th className="py-1 pr-2 font-bold">ITEM GROUP</th>
              <th className="py-1 pr-2 text-right font-bold">B/F</th>
              <th className="py-1 pr-2 text-right font-bold">IN</th>
              <th className="py-1 pr-2 text-right font-bold">OUT</th>
              <th className="py-1 pr-2 text-right font-bold">BALANCE</th>
              <th className="py-1 pr-2 text-right font-bold">UNIT COST</th>
              <th className="py-1 text-right font-bold">VALUE</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row, index) => (
              <tr key={`${row.item_id}-${row.warehouse_id}-${index}`} className="align-top">
                <td className="py-0.5 pr-2">{row.item_code}</td>
                <td className="py-0.5 pr-2">{row.item_name}</td>
                <td className="py-0.5 pr-2">{row.warehouse_code}</td>
                <td className="py-0.5 pr-2">{row.item_group_name}</td>
                <td className="py-0.5 pr-2 text-right">{formatQty(row.opening_qty, 3)}</td>
                <td className="py-0.5 pr-2 text-right">{formatQty(row.qty_in, 3)}</td>
                <td className="py-0.5 pr-2 text-right">{formatQty(row.qty_out, 3)}</td>
                <td className="py-0.5 pr-2 text-right">{formatQty(row.closing_qty, 3)}</td>
                <td className="py-0.5 pr-2 text-right">{formatQty(row.unit_cost, 2)}</td>
                <td className="py-0.5 text-right">{formatQty(row.closing_value, 2)}</td>
              </tr>
            ))}
          </tbody>
          {report && (
            <tfoot>
              <tr className="border-t border-black font-bold">
                <td colSpan={4} className="py-1 pr-2">
                  Printed By : {report.meta.printed_by}
                  <span className="float-right">GRAND TOTAL</span>
                </td>
                <td className="py-1 pr-2 text-right">{formatQty(totals.opening_qty, 3)}</td>
                <td className="py-1 pr-2 text-right">{formatQty(totals.qty_in, 3)}</td>
                <td className="py-1 pr-2 text-right">{formatQty(totals.qty_out, 3)}</td>
                <td className="py-1 pr-2 text-right">{formatQty(totals.closing_qty, 3)}</td>
                <td className="py-1 pr-2" />
                <td className="py-1 text-right">{formatQty(totals.closing_value, 2)}</td>
              </tr>
            </tfoot>
          )}
        </table>
      </div>
    </div>
  )
}
