import { Fragment, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatCurrency, formatDate } from '@/lib/utils'
import { fetchOpenBillsByCustomer, type OpenBillCustomer } from '../api/accountsReceivableOpenByCustomerApi'

const ALL_CUSTOMERS = 'all'

/**
 * Customer Outstanding Bills (live). Shows every still-owed invoice per customer, read straight from
 * accounts receivable, so a payment recorded in the app drops the invoice from this list the same way
 * Skybiz's own unpaid-bills report behaves. Replaces the static archive snapshot view.
 *
 * Filters run on the loaded response, so typing never triggers a new request. The three filters
 * combine: the customer dropdown and the customer text narrow which customers show, and the document
 * text narrows which rows show inside them. A customer with no matching rows is hidden.
 */
export function CustomerOutstandingBillsLiveView() {
  const [asAt, setAsAt] = useState('')
  const [documentSearch, setDocumentSearch] = useState('')
  const [customerSearch, setCustomerSearch] = useState('')
  const [customerSelect, setCustomerSelect] = useState(ALL_CUSTOMERS)

  const query = useQuery({
    queryKey: ['ar-open-bills-by-customer', asAt],
    queryFn: () => fetchOpenBillsByCustomer(asAt || undefined),
  })

  const data = query.data

  const customerOptions = useMemo(
    () => (data?.customers ?? []).map((c) => ({ key: c.customer_code ?? '', label: `${c.customer_code} — ${c.customer_name}` })),
    [data],
  )

  const visibleCustomers = useMemo(() => {
    if (!data) return [] as { customer: OpenBillCustomer; rows: OpenBillCustomer['rows'] }[]
    const customerNeedle = customerSearch.trim().toLowerCase()
    const documentNeedle = documentSearch.trim().toLowerCase()

    return data.customers
      .filter((c) => customerSelect === ALL_CUSTOMERS || c.customer_code === customerSelect)
      .filter((c) => customerNeedle === '' || `${c.customer_code ?? ''} ${c.customer_name ?? ''}`.toLowerCase().includes(customerNeedle))
      .map((customer) => ({
        customer,
        rows:
          documentNeedle === ''
            ? customer.rows
            : customer.rows.filter((row) => `${row.document_number ?? ''} ${row.reference_1 ?? ''}`.toLowerCase().includes(documentNeedle)),
      }))
      .filter((entry) => entry.rows.length > 0)
  }, [data, customerSearch, customerSelect, documentSearch])

  const isFiltered = customerSelect !== ALL_CUSTOMERS || customerSearch.trim() !== '' || documentSearch.trim() !== ''
  const visibleRowCount = visibleCustomers.reduce((sum, entry) => sum + entry.rows.length, 0)
  const visibleUnpaid = visibleCustomers.reduce((sum, entry) => sum + entry.rows.reduce((s, r) => s + r.unpaid_amount, 0), 0)
  const visibleOverdue = visibleCustomers.reduce((sum, entry) => sum + entry.rows.reduce((s, r) => s + r.overdue_amount, 0), 0)

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
        <div className="flex min-w-56 flex-col gap-1.5">
          <label htmlFor="open-bills-document" className="text-xs text-muted-foreground">
            No. dokumen
          </label>
          <Input
            id="open-bills-document"
            value={documentSearch}
            onChange={(e) => setDocumentSearch(e.target.value)}
            placeholder="Contoh: SI/KE/07133"
          />
        </div>
        <div className="flex min-w-56 flex-col gap-1.5">
          <label htmlFor="open-bills-customer" className="text-xs text-muted-foreground">
            Customer
          </label>
          <Input
            id="open-bills-customer"
            value={customerSearch}
            onChange={(e) => setCustomerSearch(e.target.value)}
            placeholder="Kode atau nama customer"
          />
        </div>
        <div className="flex min-w-72 flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Pilih customer</span>
          <Select value={customerSelect} onValueChange={setCustomerSelect}>
            <SelectTrigger className="w-full">
              <SelectValue placeholder="Semua customer" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL_CUSTOMERS}>Semua customer</SelectItem>
              {customerOptions.map((option) => (
                <SelectItem key={option.key} value={option.key}>
                  {option.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        {data && (
          <p className="pb-2 text-sm text-muted-foreground">
            Per {formatDate(data.as_at)} — {visibleCustomers.length} customer, {visibleRowCount} dokumen
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
          <CardContent className="py-10 text-center text-muted-foreground">Tidak ada data yang cocok dengan filter.</CardContent>
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
              {visibleCustomers.map(({ customer, rows }) => {
                const totalUnpaid = rows.reduce((sum, r) => sum + r.unpaid_amount, 0)
                const totalOverdue = rows.reduce((sum, r) => sum + r.overdue_amount, 0)
                return (
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
                )
              })}
              <TableRow className="font-semibold">
                <TableCell colSpan={5} className="text-right">
                  {isFiltered ? 'Total Tampilan' : 'Grand Total Belum Dibayar'}
                </TableCell>
                <TableCell className="text-right">
                  {formatCurrency(isFiltered ? visibleUnpaid : data?.grand_total_unpaid ?? 0)}
                </TableCell>
                <TableCell colSpan={2} className="text-right text-destructive">
                  Terlewat: {formatCurrency(isFiltered ? visibleOverdue : data?.grand_total_overdue ?? 0)}
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>
      )}
    </div>
  )
}
