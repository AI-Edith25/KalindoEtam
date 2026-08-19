import { useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { fetchAccountsReceivablesAll } from '@/features/reports/api/accountsReceivableCustomerReportsApi'

/** Laporan_penagihan.pdf shows en-US grouping (comma thousands, dot decimal) with no currency symbol — same reasoning as Tanda Terima Invoice print's own formatNum. */
function formatNum(value: number | string, decimals: number): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

/** invoice_date/due_date arrive as plain YYYY-MM-DD strings — split directly, same reasoning as Tanda Terima Invoice print. */
function formatDdMmYyyy(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${day}/${month}/${year}`
}

/**
 * Reached from Sales > Invoices' checkbox selection (?ids=uuid,uuid,...), replacing the old
 * /reports/penagihan-harian — same AccountsReceivable.outstanding_amount data source (via the
 * shared list-all endpoint, now filtered by invoice_ids instead of due-date/branch/salesman),
 * deliberately rendered as a flat list (no per-customer grouping/subtotals like the old page —
 * CUSTOMER # / CUSTOMER NAME repeat on every row instead), matching Laporan_penagihan.pdf.
 */
export function LaporanPenagihanHarianPrintPage() {
  const [searchParams] = useSearchParams()
  const ids = useMemo(() => searchParams.get('ids')?.split(',').filter(Boolean) ?? [], [searchParams])

  const listQuery = useQuery({
    queryKey: ['accounts-receivables', 'by-invoice-ids', ids],
    queryFn: () => fetchAccountsReceivablesAll({ invoice_ids: ids }),
    enabled: ids.length > 0,
  })

  const rows = listQuery.data ?? []
  const total = rows.reduce((sum, row) => sum + Number(row.outstanding_amount), 0)
  const today = new Date().toISOString().slice(0, 10)

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-[12mm]">
      <style>{'@page { size: A4; margin: 0; }'}</style>

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Laporan Penagihan Harian Print Preview</h1>
        <Button onClick={() => window.print()}>
          <Printer className="size-4" />
          Print
        </Button>
      </div>

      <div className="flex flex-col text-black text-[13px]" style={{ fontFamily: 'Arial, Helvetica, sans-serif' }}>
        <p className="text-center text-lg font-bold">LAPORAN PENAGIHAN HARIAN</p>
        <p className="text-right">Tanggal : {formatDdMmYyyy(today)}</p>
        <hr className="mt-2 border-black" />

        <table className="mt-2 w-full border-collapse text-left">
          <thead>
            <tr className="border-b border-black">
              <th className="py-1 pr-2 font-normal">CUSTOMER #</th>
              <th className="py-1 pr-2 font-normal">CUSTOMER NAME</th>
              <th className="py-1 pr-2 font-normal">INV DATE</th>
              <th className="py-1 pr-2 font-normal">DOCUMENT</th>
              <th className="py-1 pr-2 font-normal">REFERENCE</th>
              <th className="py-1 pr-2 font-normal">DUE DATE</th>
              <th className="py-1 text-right font-normal">OUTSTANDING AMOUNT</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id}>
                <td className="py-1 pr-2 align-top">{row.customer?.customer_code ?? ''}</td>
                <td className="py-1 pr-2 align-top">{row.customer?.customer_name ?? ''}</td>
                <td className="py-1 pr-2 align-top">{formatDdMmYyyy(row.invoice?.invoice_date)}</td>
                <td className="py-1 pr-2 align-top">{row.invoice?.document_number ?? ''}</td>
                <td className="py-1 pr-2 align-top">{row.invoice?.reference_1 ?? ''}</td>
                <td className="py-1 pr-2 align-top">{formatDdMmYyyy(row.due_date)}</td>
                <td className="py-1 text-right align-top">{formatNum(row.outstanding_amount, 2)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t border-black font-bold">
              <td colSpan={6} className="py-1 pr-2 text-right">
                TOTAL
              </td>
              <td className="py-1 text-right">{formatNum(total, 2)}</td>
            </tr>
          </tfoot>
        </table>

        <p className="mt-8 text-center text-xs font-medium">PEMBAYARAN DENGAN CHEQUE / GIRO DIANGGAP LUNAS SETELAH DICAIRKAN</p>

        <div className="mt-10 grid grid-cols-2 gap-8 text-center">
          <p>Dibuat Oleh,</p>
          <p>Diterima Oleh,</p>
        </div>
        <div className="mt-16 grid grid-cols-2 gap-8 text-center">
          <p>( &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; )</p>
          <p>( &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; )</p>
        </div>
      </div>
    </div>
  )
}
