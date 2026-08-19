import { useEffect, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Printer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useBrandingLogoObjectUrl, useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { fetchAccountsReceivablesAll } from '@/features/reports/api/accountsReceivableCustomerReportsApi'

/** Tanda_terima.pdf shows en-US grouping (comma thousands, dot decimal) with no currency symbol — same reasoning as the SO/DO/SI print family's own formatNum, not the shared id-ID formatMoney the old Reports page used. */
function formatNum(value: number | string, decimals: number): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

/** invoice_date/due_date arrive as plain YYYY-MM-DD strings — split directly rather than re-parsing through a Date object, which shifts the calendar date in any timezone ahead of UTC (same pitfall the SO/DO/SI print family already documents). */
function formatDdMmYyyy(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${day}/${month}/${year}`
}

/**
 * Reached from Sales > Invoices' checkbox selection (?ids=uuid,uuid,...), replacing the old
 * /reports/tanda-terima-invoice — same AccountsReceivable.outstanding_amount data source (via
 * the shared list-all endpoint, now filtered by invoice_ids instead of customer_id+date range),
 * but a from-scratch classic layout matching Tanda_terima.pdf exactly (that reference is visually
 * a plain/thin-rule layout, not the old page's bordered "card" style) — same print plumbing as
 * SO/DO/SI (print:hidden toolbar, @page margin:0 + print:p-[12mm] wrapper), which the old page
 * never had (it relied on the browser's own default A4 margins).
 */
export function TandaTerimaInvoicePrintPage() {
  const [searchParams] = useSearchParams()
  const ids = useMemo(() => searchParams.get('ids')?.split(',').filter(Boolean) ?? [], [searchParams])

  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()
  const logoObjectUrl = useBrandingLogoObjectUrl(brandingQuery.data?.logo_url)

  const listQuery = useQuery({
    queryKey: ['accounts-receivables', 'by-invoice-ids', ids],
    queryFn: () => fetchAccountsReceivablesAll({ invoice_ids: ids }),
    enabled: ids.length > 0,
  })

  const rows = listQuery.data ?? []
  const total = rows.reduce((sum, row) => sum + Number(row.outstanding_amount), 0)

  // First-checked invoice's customer, not the AR list's own row order (server-ordered by
  // due_date, which can differ from click order) and not a merged/joined list of every customer.
  const firstRow = rows.find((row) => row.invoice_id === ids[0])
  const customerName = firstRow?.customer?.customer_name ?? rows[0]?.customer?.customer_name ?? '—'

  useEffect(() => {
    if (rows.length === 0) return
    const distinctCustomers = new Set(rows.map((row) => row.customer_id))
    if (distinctCustomers.size > 1) {
      toast.warning('Selected invoices belong to more than one customer — "To." shows only the first one checked.')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rows.length])

  const companyName = brandingQuery.data?.name ?? 'PT. KALINDO ETAM'
  const today = new Date().toISOString().slice(0, 10)

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-[12mm]">
      {/* margin: 0 on @page suppresses the browser's own print header/footer chrome (page title
          + date on top, URL + page number on bottom) — that's not part of the document, it's
          browser UI. Document margins come from this wrapper's own print:p-[12mm] instead. */}
      <style>{'@page { size: A4; margin: 0; }'}</style>

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Tanda Terima Invoice Print Preview</h1>
        <Button onClick={() => window.print()}>
          <Printer className="size-4" />
          Print
        </Button>
      </div>

      <div className="flex min-h-[27.3cm] flex-col text-black text-[13px]">
        <div className="flex items-start justify-between">
          <div className="flex items-start gap-3">
            {logoObjectUrl && <img src={logoObjectUrl} alt="Logo" className="h-12 w-auto object-contain" />}
            <div>
              <p className="text-lg font-bold">{companyName}</p>
              {printHeaderQuery.data?.address && <p>{printHeaderQuery.data.address}</p>}
              <p>
                TEL : {printHeaderQuery.data?.phone ?? ''}&nbsp;&nbsp;&nbsp;&nbsp;FAX : ,
              </p>
              {printHeaderQuery.data?.email && <p>EMAIL : {printHeaderQuery.data.email}</p>}
            </div>
          </div>
          <div className="text-right">
            <p>{formatDdMmYyyy(today)}</p>
            <p>To. {customerName.toUpperCase()}</p>
          </div>
        </div>

        <p className="mt-2 text-center text-lg font-bold">TANDA TERIMA INVOICE</p>
        <hr className="mt-2 border-black" />

        <table className="mt-2 w-full border-collapse text-left">
          <thead>
            <tr className="border-b border-black">
              <th className="py-1 pr-2 font-normal">INV DATE</th>
              <th className="py-1 pr-2 font-normal">DOCUMENT #</th>
              <th className="py-1 pr-2 font-normal">REFERENCE 1 #</th>
              <th className="py-1 pr-2 font-normal">REFERENCE 2 #</th>
              <th className="py-1 pr-2 font-normal">DUE DATE</th>
              <th className="py-1 text-right font-normal">AMOUNT</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id}>
                <td className="py-1 pr-2 align-top">{formatDdMmYyyy(row.invoice?.invoice_date)}</td>
                <td className="py-1 pr-2 align-top">{row.invoice?.document_number ?? ''}</td>
                <td className="py-1 pr-2 align-top">{row.invoice?.reference_1 ?? ''}</td>
                <td className="py-1 pr-2 align-top">{row.invoice?.reference_2 ?? ''}</td>
                <td className="py-1 pr-2 align-top">{formatDdMmYyyy(row.due_date)}</td>
                <td className="py-1 text-right align-top">{formatNum(row.outstanding_amount, 2)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t border-black font-bold">
              <td colSpan={5} className="py-1 pr-2 text-right">
                TOTAL
              </td>
              <td className="py-1 text-right">{formatNum(total, 2)}</td>
            </tr>
          </tfoot>
        </table>

        <div className="flex-1" />

        <p className="mt-4 text-xs font-medium">PEMBAYARAN DENGAN CHEQUE / GIRO DIANGGAP LUNAS SETELAH DICAIRKAN</p>

        <div className="mt-10 grid grid-cols-2 gap-8">
          <p>Dibuat Oleh,</p>
          <p>Diterima Oleh,</p>
        </div>
        <div className="mt-16 grid grid-cols-2 gap-8">
          <p>( &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; )</p>
          <p>( &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; )</p>
        </div>
      </div>
    </div>
  )
}
