import { useSearchParams } from 'react-router-dom'
import { useQueries, useQuery } from '@tanstack/react-query'
import { Loader2, Printer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { ErrorState } from '@/components/shared/ErrorState'
import { useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { formatDate } from '@/lib/utils'
import { fetchCreditNote, fetchCreditNotes } from '../api/creditNoteApi'
import { CREDIT_NOTE_REASON_LABELS } from '../lib/creditNoteReasonLabels'
import type { CreditNote } from '../types'

function formatNum(value: number | string): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value))
}

/**
 * "Cetak ringkasan tabel" for Credit Notes — same recipe as
 * SalesOrderListPrintPage: `?ids=uuid,uuid,...` prints exactly those rows,
 * otherwise every querystring param forwards to fetchCreditNotes as the
 * active filter, same list this was reached from.
 */
export function CreditNoteListPrintPage() {
  const [searchParams] = useSearchParams()
  const ids = (searchParams.get('ids') ?? '').split(',').filter(Boolean)

  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()

  const byIdsQueries = useQueries({
    queries: ids.map((id) => ({ queryKey: ['credit-notes', id], queryFn: () => fetchCreditNote(id), enabled: ids.length > 0 })),
  })

  const filterParams = Object.fromEntries(searchParams.entries())
  delete filterParams.ids
  const byFilterQuery = useQuery({
    queryKey: ['credit-notes', 'list-print', filterParams],
    queryFn: () => fetchCreditNotes({ page: 1, per_page: 100, ...filterParams }),
    enabled: ids.length === 0,
  })

  const isLoading = ids.length > 0 ? byIdsQueries.some((query) => query.isLoading) : byFilterQuery.isLoading
  const isError = ids.length > 0 ? byIdsQueries.some((query) => query.isError) : byFilterQuery.isError

  if (isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (isError) {
    return <ErrorState message="Failed to load Credit Notes for print." />
  }

  const rows: CreditNote[] = ids.length > 0 ? byIdsQueries.map((query) => query.data!).filter(Boolean) : (byFilterQuery.data?.data ?? [])

  if (rows.length === 0) {
    return <ErrorState message="No Credit Notes match this selection." />
  }

  const companyName = brandingQuery.data?.name ?? 'PT. KALINDO ETAM'
  const total = rows.reduce((sum, row) => sum + Number(row.total_amount), 0)

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-[12mm]">
      <style>{'@page { size: A4 landscape; margin: 0; }'}</style>

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Credit Notes — List Print Preview ({rows.length})</h1>
        <Button onClick={() => window.print()}>
          <Printer className="size-4" />
          Print
        </Button>
      </div>

      <div className="flex flex-col text-black text-[12px]" style={{ fontFamily: 'Arial, Helvetica, sans-serif' }}>
        <div className="text-center">
          <p className="text-lg font-bold">{companyName}</p>
          {printHeaderQuery.data?.address && <p>{printHeaderQuery.data.address}</p>}
        </div>
        <hr className="mt-2 border-black" />
        <p className="mt-2 text-center text-base font-bold">CREDIT NOTES LIST</p>

        <table className="mt-2 w-full border-collapse text-left">
          <thead>
            <tr className="border-b border-black">
              <th className="py-1 pr-2 font-bold">DATE</th>
              <th className="py-1 pr-2 font-bold">CREDIT NOTE NO</th>
              <th className="py-1 pr-2 font-bold">INVOICE</th>
              <th className="py-1 pr-2 font-bold">CUSTOMER</th>
              <th className="py-1 pr-2 font-bold">REASON</th>
              <th className="py-1 pr-2 font-bold">STATUS</th>
              <th className="py-1 text-right font-bold">AMOUNT</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id}>
                <td className="py-1 pr-2 align-top">{formatDate(row.credit_note_date)}</td>
                <td className="py-1 pr-2 align-top">{row.document_number ?? '—'}</td>
                <td className="py-1 pr-2 align-top">{row.invoice?.document_number ?? '—'}</td>
                <td className="py-1 pr-2 align-top">{row.customer?.customer_name ?? '—'}</td>
                <td className="py-1 pr-2 align-top">{CREDIT_NOTE_REASON_LABELS[row.reason]}</td>
                <td className="py-1 pr-2 align-top capitalize">{row.status}</td>
                <td className="py-1 text-right align-top">{formatNum(row.total_amount)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t border-black font-bold">
              <td colSpan={6} className="py-1 pr-2 text-right">
                TOTAL
              </td>
              <td className="py-1 text-right">{formatNum(total)}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  )
}
