import { Fragment, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatCurrency, formatDate } from '@/lib/utils'
import { fetchOpenBillsByCustomer, type OpenBillCustomer } from '../api/accountsReceivableOpenByCustomerApi'

const SUGGESTION_LIMIT = 10

/** Prefix match on code or name (case-insensitive), so "mar" finds "MARZAN" but not "PT MARZAN". */
function matchesPrefix(customer: OpenBillCustomer, needle: string): boolean {
  return (customer.customer_code ?? '').toLowerCase().startsWith(needle) || (customer.customer_name ?? '').toLowerCase().startsWith(needle)
}

function customerKey(customer: OpenBillCustomer): string {
  return customer.customer_code ?? customer.customer_name ?? ''
}

/**
 * Customer Outstanding Bills (live). One row per customer with its total still owed and overdue;
 * the invoices behind it stay hidden until that customer's chevron is pressed. Read straight from
 * accounts receivable, so a payment recorded in the app drops the invoice from this list.
 *
 * Filters run on the loaded response, so typing never triggers a new request. The customer box
 * matches the START of the customer code or name ("mar" finds MARZAN), and lists matching customers
 * underneath to pick from. Picking one narrows the list to that customer. The document box narrows
 * the invoices inside each customer, and opens every customer that has a matching invoice so the
 * hit is visible without extra clicks. A customer with no matching invoice is hidden.
 */
export function CustomerOutstandingBillsLiveView() {
  const [asAt, setAsAt] = useState('')
  const [documentSearch, setDocumentSearch] = useState('')
  const [customerText, setCustomerText] = useState('')
  const [selectedCustomerCode, setSelectedCustomerCode] = useState<string | null>(null)
  const [suggestionsOpen, setSuggestionsOpen] = useState(false)
  const [expandedKeys, setExpandedKeys] = useState<Set<string>>(() => new Set())

  const toggleExpanded = (key: string) =>
    setExpandedKeys((prev) => {
      const next = new Set(prev)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })

  const query = useQuery({
    queryKey: ['ar-open-bills-by-customer', asAt],
    queryFn: () => fetchOpenBillsByCustomer(asAt || undefined),
  })

  const data = query.data

  const customerNeedle = customerText.trim().toLowerCase()

  const suggestions = useMemo(() => {
    if (!data || customerNeedle === '' || selectedCustomerCode) return [] as OpenBillCustomer[]
    return data.customers.filter((c) => matchesPrefix(c, customerNeedle)).slice(0, SUGGESTION_LIMIT)
  }, [data, customerNeedle, selectedCustomerCode])

  const visibleCustomers = useMemo(() => {
    if (!data) return [] as { customer: OpenBillCustomer; rows: OpenBillCustomer['rows'] }[]
    const documentNeedle = documentSearch.trim().toLowerCase()

    return data.customers
      .filter((c) => {
        if (selectedCustomerCode) return c.customer_code === selectedCustomerCode
        return customerNeedle === '' || matchesPrefix(c, customerNeedle)
      })
      .map((customer) => ({
        customer,
        rows:
          documentNeedle === ''
            ? customer.rows
            : customer.rows.filter((row) => `${row.document_number ?? ''} ${row.reference_1 ?? ''}`.toLowerCase().includes(documentNeedle)),
      }))
      .filter((entry) => entry.rows.length > 0)
  }, [data, customerNeedle, selectedCustomerCode, documentSearch])

  const isFiltered = customerText.trim() !== '' || documentSearch.trim() !== ''
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
        <div className="relative flex min-w-72 flex-col gap-1.5">
          <label htmlFor="open-bills-customer" className="text-xs text-muted-foreground">
            Customer
          </label>
          <Input
            id="open-bills-customer"
            value={customerText}
            onChange={(e) => {
              setCustomerText(e.target.value)
              setSelectedCustomerCode(null)
              setSuggestionsOpen(true)
            }}
            onFocus={() => setSuggestionsOpen(true)}
            onBlur={() => setSuggestionsOpen(false)}
            placeholder="Ketik awal kode atau nama, misal: mar"
            autoComplete="off"
          />
          {suggestionsOpen && suggestions.length > 0 && (
            <ul className="absolute top-full z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border bg-popover text-sm shadow-md">
              {suggestions.map((c) => (
                <li key={c.customer_code ?? c.customer_name ?? ''}>
                  <button
                    type="button"
                    // onMouseDown runs before the input's onBlur, so the pick lands before the list closes
                    onMouseDown={(e) => {
                      e.preventDefault()
                      setSelectedCustomerCode(c.customer_code)
                      setCustomerText(`${c.customer_code} — ${c.customer_name}`)
                      setSuggestionsOpen(false)
                    }}
                    className="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-accent"
                  >
                    <span className="font-medium">{c.customer_code}</span>
                    <span className="truncate">{c.customer_name}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
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
                <TableHead className="w-10" />
                <TableHead>Kode</TableHead>
                <TableHead>Customer</TableHead>
                <TableHead className="text-right">Jumlah Dokumen</TableHead>
                <TableHead className="text-right">Sisa</TableHead>
                <TableHead className="text-right">Terlewat</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {visibleCustomers.map(({ customer, rows }) => {
                const key = customerKey(customer)
                const isOpen = documentSearch.trim() !== '' || expandedKeys.has(key)
                const totalUnpaid = rows.reduce((sum, r) => sum + r.unpaid_amount, 0)
                const totalOverdue = rows.reduce((sum, r) => sum + r.overdue_amount, 0)
                return (
                  <Fragment key={key}>
                    <TableRow className="font-medium">
                      <TableCell>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          className="size-7"
                          aria-expanded={isOpen}
                          aria-label={isOpen ? 'Tutup daftar tagihan' : 'Lihat daftar tagihan'}
                          onClick={() => toggleExpanded(key)}
                        >
                          {isOpen ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                        </Button>
                      </TableCell>
                      <TableCell>{customer.customer_code}</TableCell>
                      <TableCell>{customer.customer_name}</TableCell>
                      <TableCell className="text-right">{rows.length}</TableCell>
                      <TableCell className="text-right">{formatCurrency(totalUnpaid)}</TableCell>
                      <TableCell className={`text-right ${totalOverdue > 0 ? 'text-destructive' : ''}`}>
                        {formatCurrency(totalOverdue)}
                      </TableCell>
                    </TableRow>
                    {isOpen && (
                      <TableRow className="hover:bg-transparent">
                        <TableCell colSpan={6} className="bg-muted/20 p-0">
                          <div className="px-4 py-2">
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
                                {rows.map((row) => (
                                  <TableRow key={row.document_number ?? `${key}-${row.invoice_date}`}>
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
                              </TableBody>
                            </Table>
                          </div>
                        </TableCell>
                      </TableRow>
                    )}
                  </Fragment>
                )
              })}
              <TableRow className="font-semibold">
                <TableCell colSpan={4} className="text-right">
                  {isFiltered ? 'Total Tampilan' : 'Grand Total Belum Dibayar'}
                </TableCell>
                <TableCell className="text-right">
                  {formatCurrency(isFiltered ? visibleUnpaid : data?.grand_total_unpaid ?? 0)}
                </TableCell>
                <TableCell className="text-right text-destructive">
                  {formatCurrency(isFiltered ? visibleOverdue : data?.grand_total_overdue ?? 0)}
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>
      )}
    </div>
  )
}
