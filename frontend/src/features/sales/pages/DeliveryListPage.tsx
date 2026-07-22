import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, ExternalLink, Eye, Pencil, Plus, RotateCw, Send, Trash2, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn, type DataTableSort } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { RowActionsMenu, type RowAction } from '@/components/shared/RowActionsMenu'
import { Pagination } from '@/components/shared/Pagination'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SectionNav } from '@/components/shared/SectionNav'
import { Button } from '@/components/ui/button'
import { toastApiError } from '@/shared/services/errorHandler'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { formatDate, formatNumber } from '@/lib/utils'
import { deleteDelivery, fetchDeliveries, submitDelivery } from '../api/deliveryApi'
import { fetchSalesOrders } from '../api/salesOrderApi'
import { DeliveryFiltersBar } from '../components/DeliveryFiltersBar'
import { emptyDeliveryFilters } from '../lib/deliveryFilters'
import { salesSectionNav } from '../navigation'
import type { Delivery, DeliveryFilterValues } from '../types'

const SORTERS: Record<string, (delivery: Delivery) => string | number> = {
  document_number: (delivery) => delivery.document_number ?? '',
  delivery_date: (delivery) => delivery.delivery_date,
}

export function DeliveryListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('delivery.create')
  const canUpdate = useHasPermission('delivery.update')
  const canDelete = useHasPermission('delivery.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<DeliveryFilterValues>(emptyDeliveryFilters)
  const [sort, setSort] = useState<DataTableSort | undefined>(undefined)
  const [deletingDelivery, setDeletingDelivery] = useState<Delivery | null>(null)

  const listQuery = useQuery({
    queryKey: ['deliveries', page, search, filters.status, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchDeliveries({
        page,
        ...(search ? { search } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  // DeliveryResource doesn't nest its sales_order — resolved client-side, same pattern as Purchase Order's document_number in GoodsReceiptListPage.
  const salesOrdersLookup = useQuery({
    queryKey: ['sales-orders-lookup'],
    queryFn: () => fetchSalesOrders({ page: 1, per_page: 100 }),
  })
  const salesOrderNumber = (salesOrderId: string) =>
    salesOrdersLookup.data?.data.find((so) => so.id === salesOrderId)?.document_number ?? '—'

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['deliveries'] })

  const submitMutation = useMutation({
    mutationFn: submitDelivery,
    onSuccess: () => {
      invalidate()
      toast.success('Delivery confirmed — stock updated.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteDelivery,
    onSuccess: () => {
      invalidate()
      toast.success('Delivery deleted.')
      setDeletingDelivery(null)
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

  const actionsFor = (delivery: Delivery): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/sales/deliveries/${delivery.id}`) }]

    if (delivery.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/sales/deliveries/${delivery.id}/edit`) },
          { label: 'Confirm Delivery', icon: Send, onClick: () => submitMutation.mutate(delivery.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingDelivery(delivery) })
      }
    }
    // submitted is terminal — Delivery has no cancel action (see deliveryApi.ts).

    return actions
  }

  const columns: DataTableColumn<Delivery>[] = [
    { header: 'Delivery Number', accessor: (row) => row.document_number ?? '—', sortKey: 'document_number' },
    {
      header: 'Sales Order',
      accessor: (row) => (
        <Button
          variant="link"
          className="h-auto p-0"
          onClick={(event) => {
            event.stopPropagation()
            navigate(`/sales/orders/${row.sales_order_id}`)
          }}
        >
          {salesOrderNumber(row.sales_order_id)}
          <ExternalLink className="size-3.5" />
        </Button>
      ),
    },
    { header: 'Customer', accessor: (row) => row.customer?.customer_name ?? '—' },
    { header: 'Delivery Date', accessor: (row) => formatDate(row.delivery_date), sortKey: 'delivery_date' },
    { header: 'Warehouse', accessor: (row) => row.warehouse?.name ?? '—' },
    {
      header: 'Qty Delivered',
      accessor: (row) => formatNumber(row.items.reduce((sum, line) => sum + line.qty, 0)),
      className: 'text-right',
    },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
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
        title="Deliveries"
        description="Deliver ordered goods from a warehouse against a submitted Sales Order."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} deliveries` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Delivery', icon: Plus, onClick: () => navigate('/sales/deliveries/new') } : undefined}
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
          placeholder="Search delivery number or customer…"
        />
        <DeliveryFiltersBar
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
        emptyMessage={hasFilters ? 'No deliveries match your search or filters.' : 'No deliveries yet.'}
        onRowClick={(row) => navigate(`/sales/deliveries/${row.id}`)}
        sort={sort}
        onSortChange={handleSortChange}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <DeleteDialog
        open={!!deletingDelivery}
        onOpenChange={(open) => !open && setDeletingDelivery(null)}
        itemLabel={deletingDelivery?.document_number ?? undefined}
        onConfirm={() => {
          if (deletingDelivery) deleteMutation.mutate(deletingDelivery.id)
        }}
      />
    </div>
  )
}
