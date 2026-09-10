import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { formatDate } from '@/lib/utils'
import { defaultPrintOptions, formatMoney, PRINT_FONT_SIZE_PX, type PrintOptions } from '@/shared/lib/printOptions'
import { fetchInputTax, fetchOutputTax } from '../api/taxReportApi'

/** Read-only print view of the Tax report's current filters — same @media print + window.print() pattern as the other Detail print pages, capped at the existing 100/page server limit. */
export function TaxReportPrintPage() {
  const [searchParams] = useSearchParams()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(defaultPrintOptions)
  const [optionsOpen, setOptionsOpen] = useState(false)

  const subTab = (searchParams.get('sub_tab') ?? 'output') as 'output' | 'input'
  const dateFrom = searchParams.get('date_from') ?? undefined
  const dateTo = searchParams.get('date_to') ?? undefined
  const taxId = searchParams.get('tax_id') ?? undefined
  const customerId = searchParams.get('customer_id') ?? undefined
  const branchId = searchParams.get('branch_id') ?? undefined
  const supplierId = searchParams.get('supplier_id') ?? undefined
  const warehouseId = searchParams.get('warehouse_id') ?? undefined

  const listQuery = useQuery({
    queryKey: ['tax-report-print', subTab, dateFrom, dateTo, taxId, customerId, branchId, supplierId, warehouseId],
    queryFn: () => {
      const params = {
        page: 1,
        per_page: 100,
        ...(dateFrom ? { date_from: dateFrom } : {}),
        ...(dateTo ? { date_to: dateTo } : {}),
        ...(taxId ? { tax_id: taxId } : {}),
        ...(customerId ? { customer_id: customerId } : {}),
        ...(branchId ? { branch_id: branchId } : {}),
        ...(supplierId ? { supplier_id: supplierId } : {}),
        ...(warehouseId ? { warehouse_id: warehouseId } : {}),
      }
      return subTab === 'output' ? fetchOutputTax(params) : fetchInputTax(params)
    },
  })

  const rows = listQuery.data?.data ?? []
  const total = listQuery.data?.meta.total ?? 0
  const totalDpp = rows.reduce((sum, r) => sum + r.dpp, 0)
  const totalPpn = rows.reduce((sum, r) => sum + r.ppn, 0)
  const partyLabel = subTab === 'output' ? 'Customer' : 'Supplier'

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4 bg-white p-6 text-foreground shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:max-w-none print:p-0 print:shadow-none">
      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Tax Report Print Preview — {subTab === 'output' ? 'PPN Keluaran' : 'PPN Masukan'}</h1>
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

      {total > 100 && (
        <p className="rounded border border-amber-500/50 bg-amber-500/10 p-2 text-sm print:hidden">
          Showing 100 of {total} rows — narrow the filters to print everything.
        </p>
      )}

      <div className="border-2 border-foreground/80" style={{ fontSize: PRINT_FONT_SIZE_PX[printOptions.fontSize] }}>
        <div className="border-b-2 border-foreground/80 p-3">
          <h2 className="text-lg font-bold">{subTab === 'output' ? 'PPN KELUARAN' : 'PPN MASUKAN'}</h2>
          {(dateFrom || dateTo) && (
            <p>
              Periode {dateFrom ? formatDate(dateFrom) : '—'} s/d {dateTo ? formatDate(dateTo) : '—'}
            </p>
          )}
        </div>

        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b-2 border-foreground/80 text-left">
              <th className="border-r-2 border-foreground/80 p-2">Tanggal</th>
              <th className="border-r-2 border-foreground/80 p-2">No Invoice</th>
              <th className="border-r-2 border-foreground/80 p-2">{partyLabel}</th>
              <th className="border-r-2 border-foreground/80 p-2">NPWP</th>
              <th className="border-r-2 border-foreground/80 p-2">Kode Pajak</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">Tarif</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">DPP</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">PPN</th>
              <th className="p-2 text-right">Total</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={`${row.document_type}-${row.document_id}`} className="border-b border-foreground/30">
                <td className="border-r-2 border-foreground/80 p-2">{formatDate(row.document_date)}</td>
                <td className="border-r-2 border-foreground/80 p-2">{row.document_number ?? '—'}</td>
                <td className="border-r-2 border-foreground/80 p-2">{row.party_name}</td>
                <td className="border-r-2 border-foreground/80 p-2">—</td>
                <td className="border-r-2 border-foreground/80 p-2">{row.tax_code ?? '—'}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{row.tax_rate !== null ? `${row.tax_rate.toFixed(2)}%` : '—'}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(row.dpp, printOptions.amountDecimals)}</td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(row.ppn, printOptions.amountDecimals)}</td>
                <td className="p-2 text-right">{formatMoney(row.total, printOptions.amountDecimals)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t-2 border-foreground/80 font-semibold">
              <td colSpan={6} className="border-r-2 border-foreground/80 p-2 text-right">
                TOTAL
              </td>
              <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(totalDpp, printOptions.amountDecimals)}</td>
              <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(totalPpn, printOptions.amountDecimals)}</td>
              <td className="p-2 text-right">{formatMoney(totalDpp + totalPpn, printOptions.amountDecimals)}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <PrintOptionsDialog open={optionsOpen} onOpenChange={setOptionsOpen} options={printOptions} onChange={setPrintOptions} />
    </div>
  )
}
