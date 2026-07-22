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
import { toastApiError } from '@/shared/services/errorHandler'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { cancelSalesOrder, deleteSalesOrder, fetchSalesOrders, submitSalesOrder } from '../api/salesOrderApi'
import { SalesOrderFiltersBar } from '../components/SalesOrderFiltersBar'
import { DeliveryProgress } from '../components/DeliveryProgress'
import { emptySalesOrderFilters } from '../lib/salesOrderFilters'
import { salesSectionNav } from '../navigation'
import type { SalesOrder, SalesOrderFilterValues } from '../types'

const SORTERS: Record<string, (so: SalesOrder) => string | number> = {
  document_number: (so) => so.document_number ?? '',
  order_date: (so) => so.order_date,
  total_amount: (so) => Number(so.total_amount),
}

export function SalesOrderListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('sales_order.create')
  const canUpdate = useHasPermission('sales_order.update')
  const canDelete = useHasPermission('sales_order.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<SalesOrderFilterValues>(emptySalesOrderFilters)
  const [sort, setSort] = useState<DataTableSort | undefined>(undefined)
  const [deletingOrder, setDeletingOrder] = useState<SalesOrder | null>(null)

  const listQuery = useQuery({
    queryKey: ['sales-orders', page, search, filters.status, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchSalesOrders({
        page,
        ...(search ? { search } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['sales-orders'] })

  const submitMutation = useMutation({
    mutationFn: submitSalesOrder,
    onSuccess: () => {
      invalidate()
      toast.success('Sales Order submitted.')
    },
    onError: (error) => toastApiError(error),
  })

  const cancelMutation = useMutation({
    mutationFn: cancelSalesOrder,
    onSuccess: () => {
      invalidate()
      toast.success('Sales Order cancelled.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteSalesOrder,
    onSuccess: () => {
      invalidate()
      toast.success('Sales Order deleted.')
      setDeletingOrder(null)
    },
    onError: (error) => toastApiError(error),
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

  const actionsFor = (order: SalesOrder): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/sales/orders/${order.id}`) }]

    if (order.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/sales/orders/${order.id}/edit`) },
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

  const columns: DataTableColumn<SalesOrder>[] = [
    { header: 'Document Number', accessor: (row) => row.document_number ?? '—', sortKey: 'document_number' },
    { header: 'Customer', accessor: (row) => row.customer?.customer_name ?? '—' },
    { header: 'Order Date', accessor: (row) => formatDate(row.order_date), sortKey: 'order_date' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
    { header: 'Delivery Progress', accessor: (row) => <DeliveryProgress order={row} /> },
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
      <SectionNav items={salesSectionNav} />

      <PageHeader
        title="Sales Orders"
        description="Track customer orders from creation through submission. Inventory is not affected until Delivery."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} orders` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Sales Order', icon: Plus, onClick: () => navigate('/sales/orders/new') } : undefined}
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
          placeholder="Search document number or customer…"
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
        emptyMessage={hasFilters ? 'No sales orders match your search or filters.' : 'No sales orders yet.'}
        onRowClick={(row) => navigate(`/sales/orders/${row.id}`)}
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
