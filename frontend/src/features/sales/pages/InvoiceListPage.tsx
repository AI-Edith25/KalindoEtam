import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, Download, Eye, Pencil, Plus, Printer, RotateCw, Send, Trash2, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn, type DataTableSort } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { RowActionsMenu, type RowAction } from '@/components/shared/RowActionsMenu'
import { Pagination } from '@/components/shared/Pagination'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SectionNav } from '@/components/shared/SectionNav'
import { getErrorMessage } from '@/shared/services/errorHandler'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { cancelInvoice, deleteInvoice, fetchInvoices, submitInvoice } from '../api/invoiceApi'
import { SalesOrderFiltersBar } from '../components/SalesOrderFiltersBar'
import { emptyInvoiceFilters } from '../lib/invoiceFilters'
import { salesSectionNav } from '../navigation'
import type { Invoice, InvoiceFilterValues } from '../types'

const SORTERS: Record<string, (invoice: Invoice) => string | number> = {
  document_number: (invoice) => invoice.document_number ?? '',
  invoice_date: (invoice) => invoice.invoice_date,
  grand_total: (invoice) => Number(invoice.grand_total),
  outstanding_amount: (invoice) => Number(invoice.outstanding_amount),
}

export function InvoiceListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('invoice.create')
  const canUpdate = useHasPermission('invoice.update')
  const canDelete = useHasPermission('invoice.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<InvoiceFilterValues>(emptyInvoiceFilters)
  const [sort, setSort] = useState<DataTableSort | undefined>(undefined)
  const [deletingInvoice, setDeletingInvoice] = useState<Invoice | null>(null)

  const listQuery = useQuery({
    queryKey: ['invoices', page, search, filters.status, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchInvoices({
        page,
        ...(search ? { search } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['invoices'] })

  const submitMutation = useMutation({
    mutationFn: submitInvoice,
    onSuccess: () => {
      invalidate()
      queryClient.invalidateQueries({ queryKey: ['accounts-receivables'] })
      toast.success('Invoice submitted — Accounts Receivable created.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const cancelMutation = useMutation({
    mutationFn: cancelInvoice,
    onSuccess: () => {
      invalidate()
      toast.success('Invoice cancelled.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteInvoice,
    onSuccess: () => {
      invalidate()
      toast.success('Invoice deleted.')
      setDeletingInvoice(null)
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const rows = useMemo(() => {
    const data = listQuery.data?.data ?? []
    if (!sort) return data

    const getter = SORTERS[sort.key]
    if (!getter) return data

    return [...data].sort((a, b) => {
      const av = getter(a)
      const bv = getter(b)
      const cmp = typeof av === 'number' && typeof bv === 'number' ? av - bv : String(av).localeCompare(String(bv))
      return sort.direction === 'asc' ? cmp : -cmp
    })
  }, [listQuery.data, sort])

  const handleSortChange = (key: string) => {
    setSort((prev) => (prev?.key === key ? { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: 'asc' }))
  }

  const actionsFor = (invoice: Invoice): RowAction[] => {
    const actions: RowAction[] = [
      { label: 'View', icon: Eye, onClick: () => navigate(`/sales/invoices/${invoice.id}`) },
      { label: 'Print', icon: Printer, onClick: () => navigate(`/sales/invoices/${invoice.id}/print`) },
    ]

    if (invoice.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/sales/invoices/${invoice.id}/edit`) },
          { label: 'Submit', icon: Send, onClick: () => submitMutation.mutate(invoice.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingInvoice(invoice) })
      }
    } else if (invoice.status === 'submitted' && canUpdate) {
      actions.push({ label: 'Cancel', icon: Ban, variant: 'destructive', onClick: () => cancelMutation.mutate(invoice.id) })
    }

    return actions
  }

  const columns: DataTableColumn<Invoice>[] = [
    { header: 'Invoice Number', accessor: (row) => row.document_number ?? '—', sortKey: 'document_number' },
    { header: 'Invoice Date', accessor: (row) => formatDate(row.invoice_date), sortKey: 'invoice_date' },
    { header: 'Customer', accessor: (row) => row.customer?.customer_name ?? '—' },
    { header: 'Delivery', accessor: (row) => row.delivery?.document_number ?? '—' },
    { header: 'Grand Total', accessor: (row) => formatCurrency(row.grand_total), className: 'text-right', sortKey: 'grand_total' },
    {
      header: 'Outstanding',
      accessor: (row) => formatCurrency(row.outstanding_amount),
      className: 'text-right',
      sortKey: 'outstanding_amount',
    },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.display_status} /> },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => <RowActionsMenu actions={actionsFor(row)} />,
    },
  ]

  const hasFilters = !!(search || filters.status || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={salesSectionNav} />

      <PageHeader
        title="Invoices"
        description="Billed against a delivered order. Accounts Receivable is created once an Invoice is submitted."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} invoices` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Invoice', icon: Plus, onClick: () => navigate('/sales/invoices/new') } : undefined}
          />
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox
          value={search}
          onChange={(value) => {
            setSearch(value)
            setPage(1)
          }}
          placeholder="Search invoice number or customer…"
        />
        <SalesOrderFiltersBar
          value={filters}
          onChange={(value) => {
            setFilters(value)
            setPage(1)
          }}
        />
      </div>

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={hasFilters ? 'No invoices match your search or filters.' : 'No invoices yet.'}
        onRowClick={(row) => navigate(`/sales/invoices/${row.id}`)}
        sort={sort}
        onSortChange={handleSortChange}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <DeleteDialog
        open={!!deletingInvoice}
        onOpenChange={(open) => !open && setDeletingInvoice(null)}
        itemLabel={deletingInvoice?.document_number ?? undefined}
        onConfirm={() => {
          if (deletingInvoice) deleteMutation.mutate(deletingInvoice.id)
        }}
      />
    </div>
  )
}
