import { useSearchParams } from 'react-router-dom'
import { useQueries, useQuery } from '@tanstack/react-query'
import { Loader2, Printer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { ErrorState } from '@/components/shared/ErrorState'
import { useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { formatDate } from '@/lib/utils'
import { fetchDelivery, fetchDeliveries } from '../api/deliveryApi'
import type { Delivery } from '../types'

function formatNum(value: number | string): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value))
}

/**
 * "Cetak ringkasan tabel" for Deliveries — same recipe as
 * SalesOrderListPrintPage: `?ids=uuid,uuid,...` prints exactly those rows,
 * otherwise every querystring param forwards to fetchDeliveries as the
 * active filter, same list this was reached from.
 */
export function DeliveryListPrintPage() {
  const [searchParams] = useSearchParams()
  const ids = (searchParams.get('ids') ?? '').split(',').filter(Boolean)

  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()

  const byIdsQueries = useQueries({
    queries: ids.map((id) => ({ queryKey: ['deliveries', id], queryFn: () => fetchDelivery(id), enabled: ids.length > 0 })),
  })

  const filterParams = Object.fromEntries(searchParams.entries())
  delete filterParams.ids
  const byFilterQuery = useQuery({
    queryKey: ['deliveries', 'list-print', filterParams],
    queryFn: () => fetchDeliveries({ page: 1, per_page: 100, ...filterParams }),
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
    return <ErrorState message="Failed to load Deliveries for print." />
  }

  const rows: Delivery[] = ids.length > 0 ? byIdsQueries.map((query) => query.data!).filter(Boolean) : (byFilterQuery.data?.data ?? [])

  if (rows.length === 0) {
    return <ErrorState message="No Deliveries match this selection." />
  }

  const companyName = brandingQuery.data?.name ?? 'PT. KALINDO ETAM'
  const total = rows.reduce((sum, row) => sum + Number(row.amount), 0)

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-[12mm]">
      <style>{'@page { size: A4 landscape; margin: 0; }'}</style>

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Deliveries — List Print Preview ({rows.length})</h1>
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
        <p className="mt-2 text-center text-base font-bold">DELIVERIES LIST</p>

        <table className="mt-2 w-full border-collapse text-left">
          <thead>
            <tr className="border-b border-black">
              <th className="py-1 pr-2 font-bold">DATE</th>
              <th className="py-1 pr-2 font-bold">DOCUMENT</th>
              <th className="py-1 pr-2 font-bold">REFERENCE</th>
              <th className="py-1 pr-2 font-bold">CUSTOMER</th>
              <th className="py-1 pr-2 font-bold">STATUS</th>
              <th className="py-1 text-right font-bold">AMOUNT</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id}>
                <td className="py-1 pr-2 align-top">{formatDate(row.delivery_date)}</td>
                <td className="py-1 pr-2 align-top">{row.document_number ?? '—'}</td>
                <td className="py-1 pr-2 align-top">{row.sales_order?.document_number ?? '—'}</td>
                <td className="py-1 pr-2 align-top">{row.customer?.customer_name ?? '—'}</td>
                <td className="py-1 pr-2 align-top capitalize">{row.status}</td>
                <td className="py-1 text-right align-top">{formatNum(row.amount)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t border-black font-bold">
              <td colSpan={5} className="py-1 pr-2 text-right">
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
