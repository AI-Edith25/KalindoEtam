import { Fragment, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { formatDate } from '@/lib/utils'
import { defaultPrintOptions, formatMoney, PRINT_FONT_SIZE_PX, type PrintOptions } from '@/shared/lib/printOptions'
import { useCompaniesLookup } from '@/features/master/hooks/useLookups'
import { fetchJournalList } from '../api/journalListApi'

/** Read-only print view of the Journal List's current filters — same @media print + window.print() pattern as AccountsReceivableDetailReportPrintPage. */
export function JournalListPrintPage() {
  const [searchParams] = useSearchParams()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(defaultPrintOptions)
  const [optionsOpen, setOptionsOpen] = useState(false)
  const [printedAt] = useState(() => new Date().toLocaleString())

  const branchId = searchParams.get('branch_id') ?? undefined
  const dateFrom = searchParams.get('date_from') ?? undefined
  const dateTo = searchParams.get('date_to') ?? undefined

  const companies = useCompaniesLookup()
  const companyName = companies.data?.[0]?.name ?? '—'

  const listQuery = useQuery({
    queryKey: ['journal-list-print', branchId, dateFrom, dateTo],
    queryFn: () => fetchJournalList({ ...(branchId ? { branch_id: branchId } : {}), ...(dateFrom ? { date_from: dateFrom } : {}), ...(dateTo ? { date_to: dateTo } : {}) }),
  })

  const groups = listQuery.data?.groups ?? []

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0">
      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Journal List Print Preview</h1>
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

      <div className="border-2 border-foreground/80" style={{ fontSize: PRINT_FONT_SIZE_PX[printOptions.fontSize] }}>
        <div className="border-b-2 border-foreground/80 p-3">
          <h2 className="text-lg font-bold">{companyName}</h2>
          <p>
            Journal List — {dateFrom ? formatDate(dateFrom) : '—'} to {dateTo ? formatDate(dateTo) : '—'}
          </p>
          <p className="text-sm text-muted-foreground">Printed: {printedAt}</p>
        </div>

        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b-2 border-foreground/80 text-left">
              <th className="border-r-2 border-foreground/80 p-2">Transaction</th>
              <th className="border-r-2 border-foreground/80 p-2">Date</th>
              <th className="border-r-2 border-foreground/80 p-2">Ref #</th>
              <th className="border-r-2 border-foreground/80 p-2">Particulars</th>
              <th className="border-r-2 border-foreground/80 p-2 text-right">Debit</th>
              <th className="p-2 text-right">Credit</th>
            </tr>
          </thead>
          <tbody>
            {groups.map((group) => (
              <Fragment key={group.key}>
                <tr className="border-b border-foreground/30 bg-muted/30 font-semibold">
                  <td colSpan={6} className="p-2">
                    {group.label}
                  </td>
                </tr>
                {group.rows.map((row, index) => (
                  <tr key={index} className="border-b border-foreground/30">
                    <td className="border-r-2 border-foreground/80 p-2">{row.transaction ?? '—'}</td>
                    <td className="border-r-2 border-foreground/80 p-2">{row.date ? formatDate(row.date) : '—'}</td>
                    <td className="border-r-2 border-foreground/80 p-2">{row.ref_no ?? '—'}</td>
                    <td className="border-r-2 border-foreground/80 p-2">{row.particulars}</td>
                    <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(row.debit, printOptions.amountDecimals)}</td>
                    <td className="p-2 text-right">{formatMoney(row.credit, printOptions.amountDecimals)}</td>
                  </tr>
                ))}
                <tr className="border-b-2 border-foreground/80 font-semibold">
                  <td colSpan={4} className="border-r-2 border-foreground/80 p-2 text-right">
                    Total For: {group.label}
                  </td>
                  <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(group.subtotal.debit, printOptions.amountDecimals)}</td>
                  <td className="p-2 text-right">{formatMoney(group.subtotal.credit, printOptions.amountDecimals)}</td>
                </tr>
              </Fragment>
            ))}
          </tbody>
          {listQuery.data && (
            <tfoot>
              <tr className="border-t-2 border-foreground/80 font-bold">
                <td colSpan={4} className="border-r-2 border-foreground/80 p-2 text-right">
                  Grand Total
                </td>
                <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(listQuery.data.grand_total.debit, printOptions.amountDecimals)}</td>
                <td className="p-2 text-right">{formatMoney(listQuery.data.grand_total.credit, printOptions.amountDecimals)}</td>
              </tr>
            </tfoot>
          )}
        </table>
      </div>

      <PrintOptionsDialog open={optionsOpen} onOpenChange={setOptionsOpen} options={printOptions} onChange={setPrintOptions} fields={['amount']} />
    </div>
  )
}
