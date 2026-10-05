import { Fragment, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatCurrency, formatDate } from '@/lib/utils'
import { fetchOpenBillsByCustomer, type OpenBillCustomer, type OpenBillLine } from '../api/accountsReceivableOpenByCustomerApi'

type CustomerSort = 'code' | 'unpaid_desc' | 'overdue_desc'
type DocumentSort = 'date_asc' | 'document_asc' | 'unpaid_desc' | 'overdue_desc'

const CUSTOMER_SORT_LABEL: Record<CustomerSort, string> = {
  code: 'Kode customer (A–Z)',
  unpaid_desc: 'Sisa terbesar',
  overdue_desc: 'Terlewat terbesar',
}

const DOCUMENT_SORT_LABEL: Record<DocumentSort, string> = {
  date_asc: 'Tanggal (terlama dulu)',
  document_asc: 'No. dokumen (A–Z)',
  unpaid_desc: 'Sisa terbesar',
  overdue_desc: 'Hari terlewat terbesar',
}

function compareCustomers(sort: CustomerSort) {
  return (a: OpenBillCustomer, b: OpenBillCustomer) => {
    if (sort === 'unpaid_desc') return b.total_unpaid - a.total_unpaid
    if (sort === 'overdue_desc') return b.total_overdue - a.total_overdue
    return (a.customer_code ?? '').localeCompare(b.customer_code ?? '')
  }
}

function compareDocuments(sort: DocumentSort) {
  return (a: OpenBillLine, b: OpenBillLine) => {
    if (sort === 'unpaid_desc') return b.unpaid_amount - a.unpaid_amount
    if (sort === 'overdue_desc') return b.overdue_days - a.overdue_days
    if (sort === 'document_asc') return (a.document_number ?? '').localeCompare(b.document_number ?? '')
    return (a.invoice_date ?? '').localeCompare(b.invoice_date ?? '')
  }
}

/**
 * Customer Outstanding Bills (live). Shows every still-owed invoice per customer, read straight from
 * accounts receivable, so a payment recorded in the app drops the invoice from this list the same way
 * Skybiz's own unpaid-bills report behaves. Replaces the static archive snapshot view.
 */
export function CustomerOutstandingBillsLiveView() {
  const [asAt, setAsAt] = useState('')
  const [search, setSearch] = useState('')
  const [customerSort, setCustomerSort] = useState<CustomerSort>('code')
  const [documentSort, setDocumentSort] = useState<DocumentSort>('date_asc')

  const query = useQuery({
    queryKey: ['ar-open-bills-by-customer', asAt],
    queryFn: () => fetchOpenBillsByCustomer(asAt || undefined),
  })

  const data = query.data

  // Filtering and sorting run on the already-loaded response, so typing never triggers a new request.
  // A customer matches on code or name and then keeps all its rows; otherwise only rows whose document
  // or reference matches are kept, so the total of a customer always reflects what is on screen.
  const visibleCustomers = useMemo(() => {
    if (!data) return []
    const needle = search.trim().toLowerCase()
    const sortRows = compareDocuments(documentSort)

    return data.customers
      .map((customer) => {
        if (needle === '') return { customer, rows: customer.rows }
        const customerMatches = `${customer.customer_code ?? ''} ${customer.customer_name ?? ''}`.toLowerCase().includes(needle)
        const rows = customerMatches
          ? customer.rows
          : customer.rows.filter((row) => `${row.document_number ?? ''} ${row.reference_1 ?? ''}`.toLowerCase().includes(needle))
        return { customer, rows }
      })
      .filter((entry) => entry.rows.length > 0)
      .map(({ customer, rows }) => ({
        customer,
        rows: [...rows].sort(sortRows),
        totalUnpaid: rows.reduce((sum, row) => sum + row.unpaid_amount, 0),
        totalOverdue: rows.reduce((sum, row) => sum + row.overdue_amount, 0),
      }))
      .sort((a, b) => compareCustomers(customerSort)(
        { ...a.customer, total_unpaid: a.totalUnpaid, total_overdue: a.totalOverdue },
        { ...b.customer, total_unpaid: b.totalUnpaid, total_overdue: b.totalOverdue },
      ))
  }, [data, search, customerSort, documentSort])

  const visibleRowCount = visibleCustomers.reduce((sum, entry) => sum + entry.rows.length, 0)
  const visibleUnpaid = visibleCustomers.reduce((sum, entry) => sum + entry.totalUnpaid, 0)
  const visibleOverdue = visibleCustomers.reduce((sum, entry) => sum + entry.totalOverdue, 0)

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
        <div className="flex min-w-64 flex-col gap-1.5">
          <label htmlFor="open-bills-search" className="text-xs text-muted-foreground">
            Cari customer / no. dokumen
          </label>
          <Input
            id="open-bills-search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Kode, nama customer, no. dokumen, atau referensi…"
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Urutkan customer</span>
          <Select value={customerSort} onValueChange={(value) => setCustomerSort(value as CustomerSort)}>
            <SelectTrigger className="w-56">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {(Object.keys(CUSTOMER_SORT_LABEL) as CustomerSort[]).map((key) => (
                <SelectItem key={key} value={key}>
                  {CUSTOMER_SORT_LABEL[key]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Urutkan dokumen</span>
          <Select value={documentSort} onValueChange={(value) => setDocumentSort(value as DocumentSort)}>
            <SelectTrigger className="w-56">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {(Object.keys(DOCUMENT_SORT_LABEL) as DocumentSort[]).map((key) => (
                <SelectItem key={key} value={key}>
                  {DOCUMENT_SORT_LABEL[key]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        {data && (
          <p className="pb-2 text-sm text-muted-foreground">
            Per {formatDate(data.as_at)} — {visibleCustomers.length} customer, {visibleRowCount} dokumen
            {search.trim() !== '' && ` (dari ${data.customers.length} customer)`}
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

      {data && data.customers.length > 0 && visibleCustomers.length === 0 && (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">Tidak ada data yang cocok dengan pencarian.</CardContent>
        </Card>
      )}

      {visibleCustomers.length > 0 && (
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
              {visibleCustomers.map(({ customer, rows, totalUnpaid, totalOverdue }) => (
                <Fragment key={customer.customer_code ?? customer.customer_name ?? ''}>
                  <TableRow className="bg-muted/20 font-semibold">
                    <TableCell colSpan={5}>
                      {customer.customer_code} — {customer.customer_name}
                    </TableCell>
                    <TableCell className="text-right">{formatCurrency(totalUnpaid)}</TableCell>
                    <TableCell colSpan={2} className="text-right text-destructive">
                      Terlewat: {formatCurrency(totalOverdue)}
                    </TableCell>
                  </TableRow>
                  {rows.map((row) => (
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
                  {search.trim() === '' ? 'Grand Total Belum Dibayar' : 'Total Tampilan'}
                </TableCell>
                <TableCell className="text-right">
                  {formatCurrency(search.trim() === '' ? data?.grand_total_unpaid ?? 0 : visibleUnpaid)}
                </TableCell>
                <TableCell colSpan={2} className="text-right text-destructive">
                  Terlewat: {formatCurrency(search.trim() === '' ? data?.grand_total_overdue ?? 0 : visibleOverdue)}
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>
      )}
    </div>
  )
}
