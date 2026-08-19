import { Fragment, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, Loader2, Printer, RotateCw } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { SectionNav } from '@/components/shared/SectionNav'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table'
import { formatCurrency, formatDate } from '@/lib/utils'
import { exportJournalListXlsx, fetchJournalList } from '../api/journalListApi'
import { JournalListFiltersBar } from '../components/JournalListFiltersBar'
import { emptyJournalListFilters } from '../lib/journalListFilters'
import { buildJournalListCsv, downloadCsv } from '../lib/journalListCsv'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import type { JournalListFilterValues } from '../types'

/** A read model, like every other Accounting Report — no create/edit/delete anywhere on this page. */
export function JournalListListPage() {
  const navigate = useNavigate()
  const [filters, setFilters] = useState<JournalListFilterValues>(emptyJournalListFilters)
  const [isExportingXlsx, setIsExportingXlsx] = useState(false)

  const listQuery = useQuery({
    queryKey: ['journal-list', filters.branchId, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchJournalList({
        ...(filters.branchId ? { branch_id: filters.branchId } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const printParams = new URLSearchParams({
    ...(filters.branchId ? { branch_id: filters.branchId } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
  }).toString()

  const report = listQuery.data

  const exportXlsx = async () => {
    setIsExportingXlsx(true)
    try {
      const blob = await exportJournalListXlsx({
        ...(filters.branchId ? { branch_id: filters.branchId } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      })
      downloadBlob('journal-list.xlsx', blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExportingXlsx(false)
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="accounting" />

      <PageHeader
        title="Journal List"
        description="Cash and bank transactions grouped by type (Petty Cash / Cash Book × Receipt / Payment), with a subtotal per group — a read-only report derived from posted Journal Entries."
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Print', icon: Printer, onClick: () => navigate(`/reports/general-ledger/journal-list/print${printParams ? `?${printParams}` : ''}`) },
              { label: 'Export CSV', icon: Download, disabled: !report, onClick: () => report && downloadCsv('journal-list.csv', buildJournalListCsv(report)) },
              { label: 'Export XLSX', icon: Download, disabled: isExportingXlsx, onClick: exportXlsx },
            ]}
          />
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <JournalListFiltersBar value={filters} onChange={setFilters} />
      </div>

      {listQuery.isLoading ? (
        <div className="flex min-h-64 items-center justify-center">
          <Loader2 className="size-6 animate-spin text-muted-foreground" />
        </div>
      ) : !report || report.groups.length === 0 ? (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">No cash/bank activity in this range.</CardContent>
        </Card>
      ) : (
        <>
          <div className="overflow-x-auto rounded-md border">
            <Table>
              <TableBody>
                {report.groups.map((group) => (
                  <Fragment key={group.key}>
                    <TableRow className="bg-muted/30">
                      <TableCell colSpan={6} className="font-semibold">
                        {group.label}
                      </TableCell>
                    </TableRow>
                    {group.rows.map((row, index) => (
                      <TableRow key={index}>
                        <TableCell>{row.transaction ?? '—'}</TableCell>
                        <TableCell>{row.date ? formatDate(row.date) : '—'}</TableCell>
                        <TableCell>{row.ref_no ?? '—'}</TableCell>
                        <TableCell>{row.particulars}</TableCell>
                        <TableCell className="text-right">{Number(row.debit) > 0 ? formatCurrency(row.debit) : '—'}</TableCell>
                        <TableCell className="text-right">{Number(row.credit) > 0 ? formatCurrency(row.credit) : '—'}</TableCell>
                      </TableRow>
                    ))}
                    <TableRow>
                      <TableCell colSpan={4} className="text-right font-medium">
                        Total For: {group.label}
                      </TableCell>
                      <TableCell className="text-right font-medium">{formatCurrency(group.subtotal.debit)}</TableCell>
                      <TableCell className="text-right font-medium">{formatCurrency(group.subtotal.credit)}</TableCell>
                    </TableRow>
                  </Fragment>
                ))}
              </TableBody>
            </Table>
          </div>

          <Card>
            <CardContent className="flex flex-wrap items-center justify-end gap-6 py-4">
              <div className="flex items-center gap-2 text-base">
                <span className="text-muted-foreground">Grand Total Debit</span>
                <span className="font-semibold">{formatCurrency(report.grand_total.debit)}</span>
              </div>
              <div className="flex items-center gap-2 text-base">
                <span className="text-muted-foreground">Grand Total Credit</span>
                <span className="font-semibold">{formatCurrency(report.grand_total.credit)}</span>
              </div>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  )
}
