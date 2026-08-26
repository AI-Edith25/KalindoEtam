import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, Download, Eye, Pencil, Plus, RotateCw, Send, Trash2, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { RowActionsMenu, type RowAction } from '@/components/shared/RowActionsMenu'
import { Pagination } from '@/components/shared/Pagination'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SectionNav } from '@/components/shared/SectionNav'
import { toastApiError } from '@/shared/services/errorHandler'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { cancelPurchaseInvoice, deletePurchaseInvoice, exportPurchaseInvoices, fetchPurchaseInvoices, submitPurchaseInvoice } from '../api/purchaseInvoiceApi'
import { PurchaseInvoiceFiltersBar } from '../components/PurchaseInvoiceFiltersBar'
import { emptyPurchaseInvoiceFilters } from '../lib/purchaseInvoiceFilters'
import type { PurchaseInvoice, PurchaseInvoiceFilterValues } from '../types'

export function PurchaseInvoiceListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('purchase.invoices.create')
  const canUpdate = useHasPermission('purchase.invoices.update')
  const canDelete = useHasPermission('purchase.invoices.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<PurchaseInvoiceFilterValues>(emptyPurchaseInvoiceFilters)
  const [deletingInvoice, setDeletingInvoice] = useState<PurchaseInvoice | null>(null)
  const [isExporting, setIsExporting] = useState(false)

  const activeFilterParams = {
    ...(search ? { search } : {}),
    ...(filters.status ? { status: filters.status } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['purchase-invoices', page, search, filters.status, filters.dateFrom, filters.dateTo],
    queryFn: () => fetchPurchaseInvoices({ page, ...activeFilterParams }),
    placeholderData: (previous) => previous,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] })

  const submitMutation = useMutation({
    mutationFn: submitPurchaseInvoice,
    onSuccess: () => {
      invalidate()
      queryClient.invalidateQueries({ queryKey: ['accounts-payables'] })
      toast.success('Purchase Invoice submitted — Accounts Payable created.')
    },
    onError: (error) => toastApiError(error),
  })

  const cancelMutation = useMutation({
    mutationFn: cancelPurchaseInvoice,
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Invoice cancelled.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: deletePurchaseInvoice,
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Invoice deleted.')
      setDeletingInvoice(null)
    },
    onError: (error) => toastApiError(error),
  })

  const exportAs = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportPurchaseInvoices(activeFilterParams, format)
      downloadBlob(`purchase-invoices.${format}`, blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const rows = listQuery.data?.data ?? []

  const actionsFor = (invoice: PurchaseInvoice): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/purchase/invoices/${invoice.id}`) }]

    if (invoice.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/purchase/invoices/${invoice.id}/edit`) },
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

  const columns: DataTableColumn<PurchaseInvoice>[] = [
    { header: 'Date', accessor: (row) => formatDate(row.invoice_date) },
    { header: 'Document Number', accessor: (row) => row.document_number ?? '—' },
    { header: 'Reference', accessor: (row) => row.goods_receipt?.document_number ?? '—' },
    { header: 'Supplier Name', accessor: (row) => row.supplier?.supplier_name ?? '—' },
    { header: 'Gross Amount', accessor: (row) => formatCurrency(row.subtotal), className: 'text-right' },
    { header: 'Tax Amount', accessor: (row) => formatCurrency(row.tax_amount), className: 'text-right' },
    { header: 'Amount', accessor: (row) => formatCurrency(row.grand_total), className: 'text-right' },
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
      <SectionNav group="purchase" />

      <PageHeader
        title="Invoices"
        description="Billed against a received Goods Receipt. Accounts Payable is created once an Invoice is submitted."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} invoices` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export XLSX', icon: Download, onClick: () => exportAs('xlsx'), disabled: isExporting },
              { label: 'Export CSV', icon: Download, onClick: () => exportAs('csv'), disabled: isExporting },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Invoice', icon: Plus, onClick: () => navigate('/purchase/invoices/new') } : undefined}
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
          placeholder="Search document number or supplier…"
        />
        <PurchaseInvoiceFiltersBar
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
        emptyMessage={hasFilters ? 'No purchase invoices match your search or filters.' : 'No purchase invoices yet.'}
        onRowClick={(row) => navigate(`/purchase/invoices/${row.id}`)}
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
