import { Fragment, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { formatQty } from '@/shared/lib/printOptions'
import { fetchStockLedgerSummaryPrint } from '@/features/inventory/api/stockLedgerApi'

/** dd/mm/yyyy — matches the legacy report's own date format, not this app's usual "03 Sep 2026" style. */
function formatSlashDate(value: string): string {
  const d = new Date(value)
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`
}

const COLSPAN = 11

/**
 * Classic paper replica of the legacy "STOCK LEDGER" report (see StockLedger.pdf) — Location >
 * Item Group > Item, each item seeded with a Brought-Forward opening balance and a running
 * balance through the period. Same Location/Item/Item-Group header text reused verbatim for both
 * a section's opening banner and its closing subtotal line, matching the legacy report exactly
 * (e.g. "Item Group: KAWAT" appears once with no numbers, then again later with the group's
 * totals). The Ledger tab itself has no row-selection, so Print always reflects the tab's current
 * filters — see StockLedgerPanel's own activeParams.
 */
export function StockLedgerPrintPage() {
  const [searchParams] = useSearchParams()
  const warehouseId = searchParams.get('warehouse_id') ?? undefined
  const itemGroupId = searchParams.get('item_group_id') ?? undefined
  const itemIds = searchParams.getAll('item_id[]')

  const today = new Date().toISOString().slice(0, 10)
  const monthStart = `${today.slice(0, 7)}-01`
  const [dateFrom, setDateFrom] = useState(searchParams.get('date_from') ?? monthStart)
  const [dateTo, setDateTo] = useState(searchParams.get('date_to') ?? today)

  const reportQuery = useQuery({
    queryKey: ['stock-ledger-summary-print', warehouseId, itemGroupId, itemIds, dateFrom, dateTo],
    queryFn: () =>
      fetchStockLedgerSummaryPrint({
        ...(warehouseId ? { warehouse_id: warehouseId } : {}),
        ...(itemGroupId ? { item_group_id: itemGroupId } : {}),
        ...(itemIds.length > 0 ? { item_id: itemIds } : {}),
        date_from: dateFrom,
        date_to: dateTo,
      }),
  })

  const report = reportQuery.data

  return (
    <div className="mx-auto flex max-w-[297mm] flex-col gap-4 bg-white p-6 text-black shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:max-w-none print:p-[10mm] print:shadow-none">
      <style>{'@page { size: A4 landscape; margin: 0; }'}</style>

      <div className="flex items-center justify-between gap-3 print:hidden">
        <h1 className="text-xl font-semibold">Stock Ledger Print Preview</h1>
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

      <div className="flex flex-col text-[10px]" style={{ fontFamily: 'Arial, Helvetica, sans-serif' }}>
        <div className="text-center font-bold" style={{ fontSize: '16px' }}>
          STOCK LEDGER
        </div>
        <div className="text-center">
          {formatSlashDate(dateFrom)} - {formatSlashDate(dateTo)}
        </div>
        <div className="text-center">Location Code : {report?.meta.location_label ?? '—'} — Item : {report?.meta.item_label ?? '—'}</div>

        <div className="mt-2 flex items-start justify-between">
          <span className="font-bold">{report?.meta.company_name ?? 'PT. KALINDO ETAM'}</span>
          {report && <span>{formatSlashDate(report.meta.generated_at)}</span>}
        </div>

        <table className="mt-1 w-full border-collapse text-left">
          <thead>
            <tr className="border-b border-black">
              <th className="py-1 pr-2 font-bold">DATE</th>
              <th className="py-1 pr-2 font-bold">D/O#</th>
              <th className="py-1 pr-2 font-bold">INVOICE #</th>
              <th className="py-1 pr-2 font-bold">TYPE</th>
              <th className="py-1 pr-2 font-bold">NAME</th>
              <th className="py-1 pr-2 font-bold">UOM</th>
              <th className="py-1 pr-2 text-right font-bold">QTY IN</th>
              <th className="py-1 pr-2 text-right font-bold">QTY OUT</th>
              <th className="py-1 pr-2 text-right font-bold">UNIT COST</th>
              <th className="py-1 pr-2 text-right font-bold">LINE AMOUNT</th>
              <th className="py-1 text-right font-bold">BALANCE</th>
            </tr>
          </thead>
          <tbody>
            {(report?.locations ?? []).map((location) => (
              <Fragment key={`loc-${location.name}`}>
                <tr className="bg-neutral-800 font-bold text-white">
                  <td colSpan={COLSPAN} className="py-1 pr-2">
                    Location : {location.code ?? location.name}
                  </td>
                </tr>

                {location.itemGroups.map((itemGroup) => (
                  <Fragment key={`grp-${location.name}-${itemGroup.name}`}>
                    <tr className="bg-neutral-200 font-bold">
                      <td colSpan={COLSPAN} className="py-1 pr-2">
                        Item Group : {itemGroup.name}
                      </td>
                    </tr>

                    {itemGroup.items.map((item) => (
                      <Fragment key={`item-${location.name}-${itemGroup.name}-${item.code}`}>
                        <tr className="font-bold italic">
                          <td colSpan={COLSPAN} className="py-0.5 pr-2">
                            {item.code} - {item.name}
                          </td>
                        </tr>

                        <tr key={`bf-${item.code}`}>
                          <td className="py-0.5 pr-2">{formatSlashDate(dateFrom)}</td>
                          <td className="py-0.5 pr-2">B/F</td>
                          <td className="py-0.5 pr-2" colSpan={4} />
                          <td className="py-0.5 pr-2 text-right" />
                          <td className="py-0.5 pr-2 text-right" />
                          <td className="py-0.5 pr-2 text-right" />
                          <td className="py-0.5 pr-2 text-right" />
                          <td className="py-0.5 text-right">{formatQty(item.openingQty, 3)}</td>
                        </tr>

                        {item.txnRows.map((txn, index) => (
                          <tr key={`txn-${item.code}-${index}`}>
                            <td className="py-0.5 pr-2">{txn.date}</td>
                            <td className="py-0.5 pr-2">{txn.reference_no}</td>
                            <td className="py-0.5 pr-2">{txn.invoice_reference}</td>
                            <td className="py-0.5 pr-2">{txn.voucher_type}</td>
                            <td className="py-0.5 pr-2">{txn.customer_name}</td>
                            <td className="py-0.5 pr-2">{txn.uom}</td>
                            <td className="py-0.5 pr-2 text-right">{txn.qty_in !== null ? formatQty(txn.qty_in, 3) : ''}</td>
                            <td className="py-0.5 pr-2 text-right">{txn.qty_out !== null ? formatQty(txn.qty_out, 3) : ''}</td>
                            <td className="py-0.5 pr-2 text-right">{txn.unit_cost !== null ? formatQty(txn.unit_cost, 2) : ''}</td>
                            <td className="py-0.5 pr-2 text-right">{txn.amount !== null ? formatQty(txn.amount, 2) : ''}</td>
                            <td className="py-0.5 text-right">{formatQty(txn.balance_qty, 3)}</td>
                          </tr>
                        ))}

                        <tr key={`item-total-${item.code}`} className="font-bold">
                          <td className="py-0.5 pr-2" colSpan={6}>
                            {item.code} :
                          </td>
                          <td className="py-0.5 pr-2 text-right">{formatQty(item.qtyInTotal, 3)}</td>
                          <td className="py-0.5 pr-2 text-right">{formatQty(item.qtyOutTotal, 3)}</td>
                          <td className="py-0.5 pr-2 text-right" />
                          <td className="py-0.5 pr-2 text-right" />
                          <td className="py-0.5 text-right">{formatQty(item.closingQty, 3)}</td>
                        </tr>
                      </Fragment>
                    ))}

                    <tr key={`grp-total-${location.name}-${itemGroup.name}`} className="bg-neutral-200 font-bold">
                      <td className="py-1 pr-2" colSpan={6}>
                        Item Group : {itemGroup.name}
                      </td>
                      <td className="py-1 pr-2 text-right">{formatQty(itemGroup.qtyInTotal, 3)}</td>
                      <td className="py-1 pr-2 text-right">{formatQty(itemGroup.qtyOutTotal, 3)}</td>
                      <td className="py-1 pr-2 text-right" />
                      <td className="py-1 pr-2 text-right" />
                      <td className="py-1 text-right">{formatQty(itemGroup.closingQty, 3)}</td>
                    </tr>
                  </Fragment>
                ))}

                <tr key={`loc-total-${location.name}`} className="bg-neutral-800 font-bold text-white">
                  <td className="py-1 pr-2" colSpan={6}>
                    Location : {location.code ?? location.name}
                  </td>
                  <td className="py-1 pr-2 text-right">{formatQty(location.qtyInTotal, 3)}</td>
                  <td className="py-1 pr-2 text-right">{formatQty(location.qtyOutTotal, 3)}</td>
                  <td className="py-1 pr-2 text-right" />
                  <td className="py-1 pr-2 text-right" />
                  <td className="py-1 text-right">{formatQty(location.closingQty, 3)}</td>
                </tr>
              </Fragment>
            ))}
          </tbody>
          {report && (
            <tfoot>
              <tr className="border-t-2 border-black font-bold">
                <td className="py-1 pr-2" colSpan={6}>
                  GRAND TOTAL
                </td>
                <td className="py-1 pr-2 text-right">{formatQty(report.grandTotals.qtyInTotal, 3)}</td>
                <td className="py-1 pr-2 text-right">{formatQty(report.grandTotals.qtyOutTotal, 3)}</td>
                <td className="py-1 pr-2 text-right" />
                <td className="py-1 pr-2 text-right" />
                <td className="py-1 text-right">{formatQty(report.grandTotals.closingQty, 3)}</td>
              </tr>
            </tfoot>
          )}
        </table>
      </div>
    </div>
  )
}
