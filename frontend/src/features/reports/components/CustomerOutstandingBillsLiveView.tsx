import { Fragment, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatCurrency, formatDate } from '@/lib/utils'
import { fetchOpenBillsByCustomer } from '../api/accountsReceivableOpenByCustomerApi'

/**
 * Customer Outstanding Bills (live). Shows every still-owed invoice per customer, read straight from
 * accounts receivable, so a payment recorded in the app drops the invoice from this list the same way
 * Skybiz's own unpaid-bills report behaves. Replaces the static archive snapshot view.
 */
export function CustomerOutstandingBillsLiveView() {
  const [asAt, setAsAt] = useState('')

  const query = useQuery({
    queryKey: ['ar-open-bills-by-customer', asAt],
    queryFn: () => fetchOpenBillsByCustomer(asAt || undefined),
  })

  const data = query.data

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end gap-3">
        <div className="flex flex-col gap-1.5">
          <label htmlFor="open-bills-as-at" className="text-xs text-muted-foreground">
            Per tanggal
          </label>
          <input
            id="open-bills-as-at"
            type="date"
            value={asAt}
            onChange={(e) => setAsAt(e.target.value)}
            className="h-9 rounded-md border bg-background px-2 text-sm"
          />
        </div>
        {data && (
          <p className="pb-2 text-sm text-muted-foreground">
            Menampilkan sisa tagihan per {formatDate(data.as_at)} — {data.customers.length} customer
          </p>
        )}
      </div>

      {query.isLoading && (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">Memuat…</CardContent>
        </Card>
      )}

      {data && data.customers.length === 0 && (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">Tidak ada tagihan yang belum dibayar.</CardContent>
        </Card>
      )}

      {data && data.customers.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Tanggal</TableHead>
                <TableHead>No. Dokumen</TableHead>
                <TableHead>Referensi</TableHead>
                <TableHead className="text-right">Jumlah Invoice</TableHead>
                <TableHead className="text-right">Dibayar</TableHead>
                <TableHead className="text-right">Sisa</TableHead>
                <TableHead>Jatuh Tempo</TableHead>
                <TableHead className="text-right">Hari Terlewat</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.customers.map((customer) => (
                <Fragment key={customer.customer_code ?? customer.customer_name ?? ''}>
                  <TableRow className="bg-muted/20 font-semibold">
                    <TableCell colSpan={5}>
                      {customer.customer_code} — {customer.customer_name}
                    </TableCell>
                    <TableCell className="text-right">{formatCurrency(customer.total_unpaid)}</TableCell>
                    <TableCell colSpan={2} className="text-right text-destructive">
                      Terlewat: {formatCurrency(customer.total_overdue)}
                    </TableCell>
                  </TableRow>
                  {customer.rows.map((row) => (
                    <TableRow key={row.document_number ?? `${customer.customer_code}-${row.invoice_date}`}>
                      <TableCell>{row.invoice_date ? formatDate(row.invoice_date) : '—'}</TableCell>
                      <TableCell>{row.document_number}</TableCell>
                      <TableCell>{row.reference_1}</TableCell>
                      <TableCell className="text-right">{formatCurrency(row.amount)}</TableCell>
                      <TableCell className="text-right">{formatCurrency(row.paid_amount)}</TableCell>
                      <TableCell className="text-right font-medium">{formatCurrency(row.unpaid_amount)}</TableCell>
                      <TableCell>{row.due_date ? formatDate(row.due_date) : '—'}</TableCell>
                      <TableCell className={`text-right ${row.overdue_days > 0 ? 'text-destructive' : ''}`}>
                        {row.overdue_days > 0 ? row.overdue_days : '—'}
                      </TableCell>
                    </TableRow>
                  ))}
                </Fragment>
              ))}
              <TableRow className="font-semibold">
                <TableCell colSpan={5} className="text-right">
                  Grand Total Belum Dibayar
                </TableCell>
                <TableCell className="text-right">{formatCurrency(data.grand_total_unpaid)}</TableCell>
                <TableCell colSpan={2} className="text-right text-destructive">
                  Terlewat: {formatCurrency(data.grand_total_overdue)}
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>
      )}
    </div>
  )
}
