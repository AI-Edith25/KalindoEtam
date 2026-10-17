import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { formatCurrency } from '@/lib/utils'
import { useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { fetchAccountsReceivableStatement } from '../api/accountsReceivableLedgerApi'
import type { ArStatementRow } from '../types'

const LOGO_URL = '/kalindo-etam-logo.png'

/** DD/MM/YYYY — this print page's own date format, distinct from lib/utils.ts's formatDate() ("17 Okt 2026"), to match the legacy STATEMENT OF ACCOUNT report exactly. */
function formatDateSlash(value: string | null | undefined): string {
  if (!value) return ''
  const date = new Date(value)
  const day = String(date.getDate()).padStart(2, '0')
  const month = String(date.getMonth() + 1).padStart(2, '0')
  return `${day}/${month}/${date.getFullYear()}`
}

const AGING_BUCKET_LABELS = ['30 Days', '60 Days', '90 Days', '120 Days', '150 Days', '180 Days'] as const
const AGING_BUCKET_KEYS = ['bucket_30', 'bucket_60', 'bucket_90', 'bucket_120', 'bucket_150', 'bucket_180'] as const

/**
 * Kartu Piutang's "rekening koran" — replicates the legacy "STATEMENT OF ACCOUNT" report
 * (rptCustomerStatement.pdf) exactly: debit-row-grouped table (each invoice's own payments/credit
 * notes nested in the same row), ACCOUNT NO./TERM/SALES PERSON metadata panel, and a 6-bucket
 * (30/60/90/120/150/180 days overdue) aging analysis + standalone 180+ figure. See
 * AccountsReceivableService::statement() for why row balances walk in debit-document order, not
 * real chronological order. Company identity comes from /company/print-header like every other
 * print page in this app (see InvoicePrintPage), deliberately WITHOUT a hardcoded company-name
 * fallback — an empty print-header renders blank here instead of fabricating identity.
 */
export function AccountsReceivableStatementPrintPage() {
  const [searchParams] = useSearchParams()
  const customerId = searchParams.get('customer_id') ?? ''
  const invoiceDateFrom = searchParams.get('invoice_date_from') ?? undefined
  const invoiceDateTo = searchParams.get('invoice_date_to') ?? undefined

  const printHeaderQuery = useCompanyPrintHeader()
  const statementQuery = useQuery({
    queryKey: ['ar-statement-print', customerId, invoiceDateFrom, invoiceDateTo],
    queryFn: () => fetchAccountsReceivableStatement({ customer_id: customerId, invoice_date_from: invoiceDateFrom, invoice_date_to: invoiceDateTo }),
    enabled: !!customerId,
  })

  const statement = statementQuery.data
  const company = printHeaderQuery.data
  const companyMissing = printHeaderQuery.isSuccess && !company?.name && !company?.address

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4 bg-white p-6 pr-10 text-foreground shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:max-w-none print:py-[10mm] print:pl-[10mm] print:pr-[18mm] print:shadow-none">
      <style>{'@page { size: A4 portrait; margin: 0; } @media print { body { font-size: 9pt; } }'}</style>

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Statement of Account — Print Preview</h1>
        <Button onClick={() => window.print()} disabled={!statement}>
          <Printer className="size-4" />
          Print
        </Button>
      </div>

      {companyMissing && (
        <p className="rounded border border-amber-500/50 bg-amber-500/10 p-2 text-sm print:hidden">
          Data perusahaan (nama/alamat) belum diisi di Administration &gt; Company — kop surat akan tercetak kosong.
        </p>
      )}

      {!customerId ? (
        <p className="text-muted-foreground">Pilih pelanggan terlebih dahulu.</p>
      ) : statementQuery.isLoading ? (
        <div className="flex min-h-64 items-center justify-center">
          <Loader2 className="size-6 animate-spin text-muted-foreground" />
        </div>
      ) : !statement ? (
        <p className="text-muted-foreground">Gagal memuat data.</p>
      ) : (
        <div
          className="flex flex-col gap-3 text-black"
          style={{ fontFamily: '"Times New Roman", "Tinos", "Liberation Serif", serif', fontSize: '9.5pt', minHeight: '277mm' }}
        >
          <div className="flex items-start">
            <div className="w-28 shrink-0">
              <img src={LOGO_URL} alt="" className="h-14 w-auto" />
            </div>
            <div className="flex-1 text-center">
              {company?.name && <p className="text-xl font-bold">{company.name}</p>}
              {company?.npwp && (
                <p>
                  <span className="font-semibold">Co. Reg. No.</span> : {company.npwp}
                </p>
              )}
              {company?.address && <p>{company.address}</p>}
            </div>
            <div className="w-28 shrink-0" aria-hidden="true" />
          </div>

          <hr className="border-t-2 border-black" />

          <p className="text-center text-lg font-bold">STATEMENT OF ACCOUNT</p>

          <div className="flex items-start justify-between gap-6">
            <div className="flex gap-2">
              <span className="shrink-0">TO :</span>
              <div>
                <p className="font-bold">{statement.header.customer_name}</p>
                <p>{statement.header.customer_address ?? '—'}</p>
                <p>Contact Person : {statement.header.customer_phone ?? ''}</p>
                <p>TEL : FAX :</p>
              </div>
            </div>
            <table className="shrink-0">
              <tbody>
                <tr>
                  <td className="pr-2 font-semibold">ACCOUNT NO.</td>
                  <td>: {statement.header.account_no}</td>
                </tr>
                <tr>
                  <td className="pr-2 font-semibold">STATEMENT DATE</td>
                  <td>: {formatDateSlash(invoiceDateTo ?? new Date().toISOString())}</td>
                </tr>
                <tr>
                  <td className="pr-2 font-semibold">PAGE</td>
                  <td>: Page 1 of 1</td>
                </tr>
                <tr>
                  <td className="pr-2 font-semibold">SALES PERSON</td>
                  <td>: {statement.header.sales_person_name ?? '—'}</td>
                </tr>
                <tr>
                  <td className="pr-2 font-semibold">TERM</td>
                  <td>: {statement.header.terms_of_payment_days} days</td>
                </tr>
              </tbody>
            </table>
          </div>

          <table className="w-full border-collapse text-[9pt]">
            <thead>
              <tr>
                <th className="border border-black p-1 text-center" colSpan={4}>
                  DEBIT
                </th>
                <th className="border border-black p-1 text-center" colSpan={4}>
                  CREDIT
                </th>
                <th className="border border-black p-1 text-center" rowSpan={2}>
                  Balance
                </th>
              </tr>
              <tr>
                <th className="border border-black p-1 text-center">No.</th>
                <th className="border border-black p-1 text-center">Date</th>
                <th className="border border-black p-1 text-center">REF. No</th>
                <th className="border border-black p-1 text-center">Amount</th>
                <th className="border border-black p-1 text-center">Date</th>
                <th className="border border-black p-1 text-center">REF. No</th>
                <th className="border border-black p-1 text-center">CHQ. No</th>
                <th className="border border-black p-1 text-center">Amount</th>
              </tr>
            </thead>
            <tbody>
              {statement.rows.map((row: ArStatementRow) => (
                <tr key={row.no} style={{ breakInside: 'avoid' }}>
                  <td className="border border-black p-1 text-center align-top">{row.no}</td>
                  <td className="border border-black p-1 align-top">{formatDateSlash(row.date)}</td>
                  <td className="border border-black p-1 align-top">{row.document_number ?? '—'}</td>
                  <td className="border border-black p-1 text-right align-top">{row.debit_amount ? formatCurrency(row.debit_amount) : ''}</td>
                  <td className="border border-black p-1 align-top">
                    {row.credits.map((credit, i) => (
                      <div key={i}>{formatDateSlash(credit.date)}</div>
                    ))}
                  </td>
                  <td className="border border-black p-1 align-top">
                    {row.credits.map((credit, i) => (
                      <div key={i}>{credit.document_number ?? '—'}</div>
                    ))}
                  </td>
                  <td className="border border-black p-1 align-top">
                    {row.credits.map((credit, i) => (
                      <div key={i}>{credit.cheque_number ?? ''}</div>
                    ))}
                  </td>
                  <td className="border border-black p-1 text-right align-top">
                    {row.credits.map((credit, i) => (
                      <div key={i}>{formatCurrency(credit.amount)}</div>
                    ))}
                  </td>
                  <td className="border border-black p-1 text-right align-top">{formatCurrency(row.balance)}</td>
                </tr>
              ))}
            </tbody>
          </table>

          <div className="flex flex-col gap-3" style={{ marginTop: 'auto' }}>
            <table className="w-full border-collapse text-[9pt]">
              <tbody>
                <tr>
                  <td className="border-2 border-black p-1.5 font-bold" colSpan={3}>
                    Total Balance
                  </td>
                  <td className="border-2 border-black p-1.5 text-right font-bold">{formatCurrency(statement.total_balance)}</td>
                </tr>
                <tr>
                  <td className="p-1 font-semibold underline" colSpan={2}>
                    Outstanding Aging Analysis
                  </td>
                  <td className="p-1 text-center font-bold" colSpan={1}>
                    Total Overdue Amount
                  </td>
                  <td className="p-1 text-right font-bold">{formatCurrency(statement.total_overdue)}</td>
                </tr>
                <tr>
                  <td className="p-1" colSpan={4}>
                    Outstanding 180 Days + : {formatCurrency(statement.overdue_180_plus)}
                  </td>
                </tr>
              </tbody>
            </table>

            <table className="w-full border-collapse text-center text-[9pt]">
              <thead>
                <tr>
                  {AGING_BUCKET_LABELS.map((label) => (
                    <th key={label} className="border border-black p-1">
                      {label}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                <tr>
                  {AGING_BUCKET_KEYS.map((key) => (
                    <td key={key} className="border border-black p-1">
                      {formatCurrency(statement.aging[key])}
                    </td>
                  ))}
                </tr>
              </tbody>
            </table>

            <div className="text-[8.5pt]">
              <p>1. Please inform us any discrepancies within 10 days from this statement date.</p>
              <p>2. Payment received after the date of this statement will appear in the next statement.</p>
              <p>3. Interest of 2% per month will be added to the overdue accounts.</p>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
