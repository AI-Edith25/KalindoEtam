import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, Download, Eye, Pencil, Plus, RotateCw, Send, Trash2, Upload } from 'lucide-react'
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
import { cancelPurchaseOrder, deletePurchaseOrder, fetchPurchaseOrders, submitPurchaseOrder } from '../api/purchaseOrderApi'
import { PurchaseOrderFiltersBar } from '../components/PurchaseOrderFiltersBar'
import { ReceivingProgress } from '../components/ReceivingProgress'
import { emptyPurchaseOrderFilters } from '../lib/purchaseOrderFilters'
import { purchaseSectionNav } from '../navigation'
import type { PurchaseOrder, PurchaseOrderFilterValues } from '../types'

const SORTERS: Record<string, (po: PurchaseOrder) => string | number> = {
  document_number: (po) => po.document_number ?? '',
  order_date: (po) => po.order_date,
  total_amount: (po) => Number(po.total_amount),
}

export function PurchaseOrderListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('purchase_order.create')
  const canUpdate = useHasPermission('purchase_order.update')
  const canDelete = useHasPermission('purchase_order.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<PurchaseOrderFilterValues>(emptyPurchaseOrderFilters)
  const [sort, setSort] = useState<DataTableSort | undefined>(undefined)
  const [deletingOrder, setDeletingOrder] = useState<PurchaseOrder | null>(null)

  const listQuery = useQuery({
    queryKey: ['purchase-orders', page, search, filters.status, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchPurchaseOrders({
        page,
        ...(search ? { search } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })

  const submitMutation = useMutation({
    mutationFn: submitPurchaseOrder,
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Order submitted.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const cancelMutation = useMutation({
    mutationFn: cancelPurchaseOrder,
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Order cancelled.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const deleteMutation = useMutation({
    mutationFn: deletePurchaseOrder,
    onSuccess: () => {
      invalidate()
      toast.success('Purchase Order deleted.')
      setDeletingOrder(null)
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

  const actionsFor = (order: PurchaseOrder): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/purchase/orders/${order.id}`) }]

    if (order.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/purchase/orders/${order.id}/edit`) },
          { label: 'Submit', icon: Send, onClick: () => submitMutation.mutate(order.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingOrder(order) })
      }
    } else if (order.status === 'submitted' && canUpdate) {
      actions.push({ label: 'Cancel', icon: Ban, variant: 'destructive', onClick: () => cancelMutation.mutate(order.id) })
    }

    return actions
  }

  const columns: DataTableColumn<PurchaseOrder>[] = [
    { header: 'Document Number', accessor: (row) => row.document_number ?? '—', sortKey: 'document_number' },
    { header: 'Supplier', accessor: (row) => row.supplier?.supplier_name ?? '—' },
    { header: 'Date', accessor: (row) => formatDate(row.order_date), sortKey: 'order_date' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
    { header: 'Receiving Progress', accessor: (row) => <ReceivingProgress order={row} /> },
    {
      header: 'Total Amount',
      accessor: (row) => formatCurrency(row.total_amount),
      className: 'text-right',
      sortKey: 'total_amount',
    },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => <RowActionsMenu actions={actionsFor(row)} />,
    },
  ]

  const hasFilters = !!(search || filters.status || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={purchaseSectionNav} />

      <PageHeader
        title="Purchase Orders"
        description="Track purchase orders from creation through submission."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} orders` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Purchase Order', icon: Plus, onClick: () => navigate('/purchase/orders/new') } : undefined}
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
        <PurchaseOrderFiltersBar
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
        emptyMessage={hasFilters ? 'No purchase orders match your search or filters.' : 'No purchase orders yet.'}
        onRowClick={(row) => navigate(`/purchase/orders/${row.id}`)}
        sort={sort}
        onSortChange={handleSortChange}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <DeleteDialog
        open={!!deletingOrder}
        onOpenChange={(open) => !open && setDeletingOrder(null)}
        itemLabel={deletingOrder?.document_number ?? undefined}
        onConfirm={() => {
          if (deletingOrder) deleteMutation.mutate(deletingOrder.id)
        }}
      />
    </div>
  )
}
