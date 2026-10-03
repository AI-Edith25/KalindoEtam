import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { formatQty } from '@/shared/lib/printOptions'
import { fetchStockBalancePrintReport } from '@/features/inventory/api/stockBalanceApi'

/** dd/mm/yyyy — matches the legacy report's own date format, not this app's usual "03 Sep 2026" style. */
function formatSlashDate(value: string): string {
  const d = new Date(value)
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`
}

/**
 * Classic paper replica of the legacy "STOCK BALANCE" report (see StockBalace.pdf) — one row per
 * (item, warehouse) with a period Brought-Forward/In/Out/Balance, sorted by item code. The Balance
 * tab's own on-screen filters have no date range (it shows a live snapshot), but the paper report
 * is inherently period-based, so this print view carries its own From/Date inputs (month-to-date
 * by default) instead of inheriting one from the tab.
 */
export function StockBalancePrintPage() {
  const [searchParams] = useSearchParams()
  const warehouseId = searchParams.get('warehouse_id') ?? undefined
  const itemGroupId = searchParams.get('item_group_id') ?? undefined
  const itemIds = searchParams.getAll('item_id[]')
  const search = searchParams.get('search') ?? undefined

  const today = new Date().toISOString().slice(0, 10)
  const monthStart = `${today.slice(0, 7)}-01`
  const [dateFrom, setDateFrom] = useState(searchParams.get('date_from') ?? monthStart)
  const [dateTo, setDateTo] = useState(searchParams.get('date_to') ?? today)

  const reportQuery = useQuery({
    queryKey: ['stock-balance-print', warehouseId, itemGroupId, itemIds, search, dateFrom, dateTo],
    queryFn: () =>
      fetchStockBalancePrintReport({
        ...(warehouseId ? { warehouse_id: warehouseId } : {}),
        ...(itemGroupId ? { item_group_id: itemGroupId } : {}),
        ...(itemIds.length > 0 ? { item_id: itemIds } : {}),
        ...(search ? { search } : {}),
        date_from: dateFrom,
        date_to: dateTo,
      }),
  })

  const report = reportQuery.data

  return (
    <div className="mx-auto flex max-w-[297mm] flex-col gap-4 bg-white p-6 text-black shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:max-w-none print:p-[10mm] print:shadow-none">
      <style>{'@page { size: A4 landscape; margin: 0; }'}</style>

      <div className="flex items-center justify-between gap-3 print:hidden">
        <h1 className="text-xl font-semibold">Stock Balance Print Preview</h1>
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
          STOCK BALANCE
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
              <th className="py-1 font-bold">ALTERNATE ITEM</th>
            </tr>
          </thead>
          <tbody>
            {(report?.rows ?? []).map((row, index) => (
              <tr key={`${row.item_code}-${row.location_code}-${index}`} className="align-top">
                <td className="py-0.5 pr-2">{row.item_code}</td>
                <td className="py-0.5 pr-2">{row.item_name}</td>
                <td className="py-0.5 pr-2">{row.location_code}</td>
                <td className="py-0.5 pr-2">{row.item_group_name}</td>
                <td className="py-0.5 pr-2 text-right">{formatQty(row.bf, 3)}</td>
                <td className="py-0.5 pr-2 text-right">{formatQty(row.in, 3)}</td>
                <td className="py-0.5 pr-2 text-right">{formatQty(row.out, 3)}</td>
                <td className="py-0.5 pr-2 text-right">{formatQty(row.balance, 3)}</td>
                <td className="py-0.5 pr-2 text-right">{formatQty(row.unit_cost, 2)}</td>
                {/* ponytail: no alternate-item field exists on Item — column kept for template parity, always blank */}
                <td className="py-0.5" />
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
                <td className="py-1 pr-2 text-right">{formatQty(report.totals.bf, 3)}</td>
                <td className="py-1 pr-2 text-right">{formatQty(report.totals.in, 3)}</td>
                <td className="py-1 pr-2 text-right">{formatQty(report.totals.out, 3)}</td>
                <td className="py-1 pr-2 text-right">{formatQty(report.totals.balance, 3)}</td>
                <td className="py-1 pr-2" />
                <td />
              </tr>
            </tfoot>
          )}
        </table>
      </div>
    </div>
  )
}
