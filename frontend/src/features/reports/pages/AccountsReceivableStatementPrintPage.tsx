import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { formatCurrency, formatDate } from '@/lib/utils'
import { useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { fetchAccountsReceivableLedgerFull } from '../api/accountsReceivableLedgerApi'

/**
 * Kartu Piutang's "rekening koran" — a customer statement, not a screen-table printout. Company
 * identity comes from /company/print-header like every other print page in this app (see
 * InvoicePrintPage), but deliberately WITHOUT the hardcoded 'PT. KALINDO ETAM' fallback every
 * sibling print page uses — the ticket explicitly forbids fabricating company identity, so an
 * empty print-header renders blank here instead.
 */
export function AccountsReceivableStatementPrintPage() {
  const [searchParams] = useSearchParams()
  const customerId = searchParams.get('customer_id') ?? ''
  const invoiceDateFrom = searchParams.get('invoice_date_from') ?? undefined
  const invoiceDateTo = searchParams.get('invoice_date_to') ?? undefined

  const printHeaderQuery = useCompanyPrintHeader()
  const ledgerQuery = useQuery({
    queryKey: ['ar-ledger-print', customerId, invoiceDateFrom, invoiceDateTo],
    queryFn: () => fetchAccountsReceivableLedgerFull({ customer_id: customerId, invoice_date_from: invoiceDateFrom, invoice_date_to: invoiceDateTo }),
    enabled: !!customerId,
  })

  const ledger = ledgerQuery.data
  const company = printHeaderQuery.data
  const companyMissing = printHeaderQuery.isSuccess && !company?.name && !company?.address

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-[12mm]">
      <style>{'@page { size: A4 portrait; margin: 0; }'}</style>

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Rekening Koran Piutang — Print Preview</h1>
        <Button onClick={() => window.print()} disabled={!ledger}>
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
      ) : ledgerQuery.isLoading ? (
        <div className="flex min-h-64 items-center justify-center">
          <Loader2 className="size-6 animate-spin text-muted-foreground" />
        </div>
      ) : !ledger ? (
        <p className="text-muted-foreground">Gagal memuat data.</p>
      ) : (
        <div className="flex flex-col gap-4 text-black" style={{ fontFamily: '"Times New Roman", "Tinos", "Liberation Serif", serif', fontSize: '10pt' }}>
          <div className="flex flex-col gap-0.5">
            {company?.name && <p className="text-lg font-bold">{company.name}</p>}
            {company?.address && <p>{company.address}</p>}
            {company?.phone && <p>TEL : {company.phone}</p>}
            {company?.email && <p>EMAIL : {company.email}</p>}
          </div>

          <hr className="border-black" />

          <div className="flex items-start justify-between">
            <div>
              <p className="text-xs uppercase text-muted-foreground print:text-black">Ditujukan kepada</p>
              <p className="font-semibold">{ledger.header.customer_name}</p>
              <p>{ledger.header.customer_address ?? '—'}</p>
            </div>
            <div className="text-right">
              <p className="text-lg font-bold">REKENING KORAN PIUTANG</p>
              <p>
                Periode {invoiceDateFrom ? formatDate(invoiceDateFrom) : 'Awal'} s/d {invoiceDateTo ? formatDate(invoiceDateTo) : formatDate(new Date().toISOString())}
              </p>
            </div>
          </div>

          <table className="w-full border-collapse text-sm">
            <thead>
              <tr className="border-y-2 border-black text-left">
                <th className="border-r border-black p-1.5">Tanggal</th>
                <th className="border-r border-black p-1.5">Jenis Dokumen</th>
                <th className="border-r border-black p-1.5">Nomor Dokumen</th>
                <th className="border-r border-black p-1.5">Keterangan</th>
                <th className="border-r border-black p-1.5">Jatuh Tempo</th>
                <th className="border-r border-black p-1.5 text-right">Debit</th>
                <th className="border-r border-black p-1.5 text-right">Kredit</th>
                <th className="p-1.5 text-right">Saldo Berjalan</th>
              </tr>
            </thead>
            <tbody>
              <tr className="border-b border-black/30">
                <td colSpan={7} className="border-r border-black p-1.5 font-medium">
                  Saldo Awal
                </td>
                <td className="p-1.5 text-right font-medium">{formatCurrency(ledger.opening_balance)}</td>
              </tr>
              {ledger.rows.map((row, index) => (
                <tr key={index} className="border-b border-black/30">
                  <td className="border-r border-black p-1.5">{formatDate(row.date)}</td>
                  <td className="border-r border-black p-1.5">{row.document_type}</td>
                  <td className="border-r border-black p-1.5">{row.document_number ?? '—'}</td>
                  <td className="border-r border-black p-1.5">{row.description ?? '—'}</td>
                  <td className="border-r border-black p-1.5">{row.due_date ? formatDate(row.due_date) : '—'}</td>
                  <td className="border-r border-black p-1.5 text-right">{row.debit ? formatCurrency(row.debit) : '—'}</td>
                  <td className="border-r border-black p-1.5 text-right">{row.credit ? formatCurrency(row.credit) : '—'}</td>
                  <td className="p-1.5 text-right">{formatCurrency(row.running_balance)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="border-t-2 border-black font-bold">
                <td colSpan={7} className="border-r border-black p-1.5 text-right">
                  Saldo Akhir
                </td>
                <td className="p-1.5 text-right">{formatCurrency(ledger.closing_balance)}</td>
              </tr>
            </tfoot>
          </table>

          <div className="flex gap-8 text-sm">
            <div>
              <p className="text-xs uppercase">Belum Jatuh Tempo</p>
              <p>{formatCurrency(ledger.aging.not_due)}</p>
            </div>
            <div>
              <p className="text-xs uppercase">1-30 Hari</p>
              <p>{formatCurrency(ledger.aging.due_1_30)}</p>
            </div>
            <div>
              <p className="text-xs uppercase">31-60 Hari</p>
              <p>{formatCurrency(ledger.aging.due_31_60)}</p>
            </div>
            <div>
              <p className="text-xs uppercase">61-90 Hari</p>
              <p>{formatCurrency(ledger.aging.due_61_90)}</p>
            </div>
            <div>
              <p className="text-xs uppercase">&gt; 90 Hari</p>
              <p>{formatCurrency(ledger.aging.due_over_90)}</p>
            </div>
          </div>

          <p className="mt-4 text-xs text-muted-foreground print:text-black">Mohon konfirmasi bila terdapat perbedaan pencatatan.</p>
        </div>
      )}
    </div>
  )
}
