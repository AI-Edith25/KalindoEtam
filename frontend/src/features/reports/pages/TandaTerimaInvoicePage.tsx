import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer, RotateCw } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SectionNav } from '@/components/shared/SectionNav'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { formatCurrency, formatDate } from '@/lib/utils'
import { fetchCustomersLookup } from '@/features/master/api/lookupsApi'
import type { AccountsReceivable } from '@/features/payment/types'
import { fetchAccountsReceivablesAll } from '../api/accountsReceivableCustomerReportsApi'

/** F1 (UAT review 2026-08-12) — pick a customer/store + date range, review the list, then print the handover receipt. */
export function TandaTerimaInvoicePage() {
  const navigate = useNavigate()
  const [customerId, setCustomerId] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')

  const customersQuery = useQuery({ queryKey: ['customers-lookup'], queryFn: fetchCustomersLookup })

  const listQuery = useQuery({
    queryKey: ['tanda-terima-invoice', customerId, dateFrom, dateTo],
    queryFn: () =>
      fetchAccountsReceivablesAll({
        customer_id: customerId,
        ...(dateFrom ? { invoice_date_from: dateFrom } : {}),
        ...(dateTo ? { invoice_date_to: dateTo } : {}),
      }),
    enabled: !!customerId,
  })

  const rows = listQuery.data ?? []

  const columns: DataTableColumn<AccountsReceivable>[] = [
    { header: 'Inv Date', accessor: (row) => (row.invoice?.invoice_date ? formatDate(row.invoice.invoice_date) : '—') },
    { header: 'Document #', accessor: (row) => row.invoice?.document_number ?? '—' },
    { header: 'Reference 1 #', accessor: (row) => row.invoice?.reference_1 ?? '—' },
    { header: 'Reference 2 #', accessor: (row) => row.invoice?.reference_2 ?? '—' },
    { header: 'Due Date', accessor: (row) => formatDate(row.due_date) },
    { header: 'Amount', accessor: (row) => formatCurrency(row.outstanding_amount), className: 'text-right' },
  ]

  const printParams = new URLSearchParams({
    customer_id: customerId,
    ...(dateFrom ? { date_from: dateFrom } : {}),
    ...(dateTo ? { date_to: dateTo } : {}),
  }).toString()

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="Tanda Terima Invoice"
        description="Invoice receipt handover to a customer/store — pick who and a date range, then print."
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              {
                label: 'Print',
                icon: Printer,
                disabled: !customerId,
                onClick: () => navigate(`/reports/tanda-terima-invoice/print?${printParams}`),
              },
            ]}
          />
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Customer / Store</span>
          <Select value={customerId} onValueChange={setCustomerId}>
            <SelectTrigger className="w-64">
              <SelectValue placeholder={customersQuery.isLoading ? 'Loading…' : 'Select customer'} />
            </SelectTrigger>
            <SelectContent>
              {customersQuery.data?.map((customer) => (
                <SelectItem key={customer.id} value={customer.id}>
                  {customer.customer_name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Invoice Date From</span>
          <Input type="date" className="w-40" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} />
        </div>
        <div className="flex flex-col gap-1.5">
          <span className="text-xs text-muted-foreground">Invoice Date To</span>
          <Input type="date" className="w-40" value={dateTo} onChange={(event) => setDateTo(event.target.value)} />
        </div>
      </div>

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={customerId ? 'No invoices for this customer in range.' : 'Select a customer to see their invoices.'}
      />
    </div>
  )
}
