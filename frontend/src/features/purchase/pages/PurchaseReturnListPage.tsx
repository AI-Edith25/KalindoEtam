import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Download, Eye, Pencil, Plus, RotateCcw, RotateCw, Send, Trash2, Upload } from 'lucide-react'
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
import { deletePurchaseReturn, exportPurchaseReturns, fetchPurchaseReturns, reversePurchaseReturn, submitPurchaseReturn } from '../api/purchaseReturnApi'
import { PurchaseReturnFiltersBar } from '../components/PurchaseReturnFiltersBar'
import { emptyPurchaseReturnFilters } from '../lib/purchaseReturnFilters'
import { PURCHASE_RETURN_REASON_LABELS } from '../lib/purchaseReturnReasonLabels'
import type { PurchaseReturn, PurchaseReturnFilterValues } from '../types'

export function PurchaseReturnListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('purchase.returns.create')
  const canUpdate = useHasPermission('purchase.returns.update')
  const canDelete = useHasPermission('purchase.returns.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<PurchaseReturnFilterValues>(emptyPurchaseReturnFilters)
  const [deletingReturn, setDeletingReturn] = useState<PurchaseReturn | null>(null)
  const [isExporting, setIsExporting] = useState(false)

  const activeFilterParams = {
    ...(search ? { search } : {}),
    ...(filters.status ? { status: filters.status } : {}),
    ...(filters.reason ? { reason: filters.reason } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['purchase-returns', page, search, filters.status, filters.reason, filters.dateFrom, filters.dateTo],
    queryFn: () => fetchPurchaseReturns({ page, ...activeFilterParams }),
    placeholderData: (previous) => previous,
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['purchase-returns'] })
    queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] })
    queryClient.invalidateQueries({ queryKey: ['accounts-payables'] })
  }

  const submitMutation = useMutation({
    mutationFn: submitPurchaseReturn,
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Return submitted — Accounts Payable updated.')
    },
    onError: (error) => toastApiError(error),
  })

  const reverseMutation = useMutation({
    mutationFn: reversePurchaseReturn,
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Return reversed.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: deletePurchaseReturn,
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Return deleted.')
      setDeletingReturn(null)
    },
    onError: (error) => toastApiError(error),
  })

  const exportAs = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportPurchaseReturns(activeFilterParams, format)
      downloadBlob(`purchase-returns.${format}`, blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const rows = listQuery.data?.data ?? []

  const actionsFor = (purchaseReturn: PurchaseReturn): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/purchase/returns/${purchaseReturn.id}`) }]

    if (purchaseReturn.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/purchase/returns/${purchaseReturn.id}/edit`) },
          { label: 'Submit', icon: Send, onClick: () => submitMutation.mutate(purchaseReturn.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingReturn(purchaseReturn) })
      }
    } else if (purchaseReturn.status === 'submitted' && !purchaseReturn.is_reversed && canUpdate) {
      actions.push({ label: 'Reverse', icon: RotateCcw, variant: 'destructive', onClick: () => reverseMutation.mutate(purchaseReturn.id) })
    }

    return actions
  }

  const columns: DataTableColumn<PurchaseReturn>[] = [
    { header: 'Return No', accessor: (row) => row.document_number ?? '—' },
    { header: 'Purchase Invoice', accessor: (row) => row.purchase_invoice?.document_number ?? '—' },
    { header: 'Supplier', accessor: (row) => row.supplier?.supplier_name ?? '—' },
    { header: 'Reason', accessor: (row) => PURCHASE_RETURN_REASON_LABELS[row.reason] },
    { header: 'Date', accessor: (row) => formatDate(row.return_date) },
    { header: 'Amount', accessor: (row) => formatCurrency(row.total_amount), className: 'text-right' },
    {
      header: 'Status',
      accessor: (row) => (
        <div className="flex items-center gap-2">
          <StatusBadge status={row.status} />
          {row.is_reversed && <Badge variant="secondary">Reversed</Badge>}
        </div>
      ),
    },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => <RowActionsMenu actions={actionsFor(row)} />,
    },
  ]

  const hasFilters = !!(search || filters.status || filters.reason || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="purchase" />

      <PageHeader
        title="Returns"
        description="The only accounting-correction path for a posted Purchase Invoice — corrections never flow through cancelling the Invoice itself."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} returns` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export XLSX', icon: Download, onClick: () => exportAs('xlsx'), disabled: isExporting },
              { label: 'Export CSV', icon: Download, onClick: () => exportAs('csv'), disabled: isExporting },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Return', icon: Plus, onClick: () => navigate('/purchase/returns/new') } : undefined}
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
          placeholder="Search return number, invoice, or supplier…"
        />
        <PurchaseReturnFiltersBar
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
        emptyMessage={hasFilters ? 'No purchase returns match your search or filters.' : 'No purchase returns yet.'}
        onRowClick={(row) => navigate(`/purchase/returns/${row.id}`)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <DeleteDialog
        open={!!deletingReturn}
        onOpenChange={(open) => !open && setDeletingReturn(null)}
        itemLabel={deletingReturn?.document_number ?? undefined}
        onConfirm={() => {
          if (deletingReturn) deleteMutation.mutate(deletingReturn.id)
        }}
      />
    </div>
  )
}
