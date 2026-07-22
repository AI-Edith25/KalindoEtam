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
import { deleteGoodsReceipt, fetchGoodsReceipts, submitGoodsReceipt } from '../api/goodsReceiptApi'
import { fetchPurchaseOrders } from '../api/purchaseOrderApi'
import { GoodsReceiptFiltersBar } from '../components/GoodsReceiptFiltersBar'
import { emptyGoodsReceiptFilters } from '../lib/goodsReceiptFilters'
import type { GoodsReceipt, GoodsReceiptFilterValues } from '../types'

const SORTERS: Record<string, (gr: GoodsReceipt) => string | number> = {
  document_number: (gr) => gr.document_number ?? '',
  receipt_date: (gr) => gr.receipt_date,
}

export function GoodsReceiptListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('purchase.goods_receipts.create')
  const canUpdate = useHasPermission('purchase.goods_receipts.update')
  const canDelete = useHasPermission('purchase.goods_receipts.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<GoodsReceiptFilterValues>(emptyGoodsReceiptFilters)
  const [sort, setSort] = useState<DataTableSort | undefined>(undefined)
  const [deletingReceipt, setDeletingReceipt] = useState<GoodsReceipt | null>(null)

  const listQuery = useQuery({
    queryKey: ['goods-receipts', page, search, filters.status, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchGoodsReceipts({
        page,
        ...(search ? { search } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  // GoodsReceiptResource doesn't nest its purchase_order — resolved client-side, same pattern as Warehouse's branch name in Master Data.
  const purchaseOrdersLookup = useQuery({
    queryKey: ['purchase-orders-lookup'],
    queryFn: () => fetchPurchaseOrders({ page: 1, per_page: 100 }),
  })
  const purchaseOrderNumber = (purchaseOrderId: string) =>
    purchaseOrdersLookup.data?.data.find((po) => po.id === purchaseOrderId)?.document_number ?? '—'

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['goods-receipts'] })

  const submitMutation = useMutation({
    mutationFn: submitGoodsReceipt,
    onSuccess: () => {
      invalidate()
      toast.success('Receipt confirmed — stock updated.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteGoodsReceipt,
    onSuccess: () => {
      invalidate()
      toast.success('Goods Receipt deleted.')
      setDeletingReceipt(null)
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

  const actionsFor = (receipt: GoodsReceipt): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/purchase/goods-receipts/${receipt.id}`) }]

    if (receipt.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/purchase/goods-receipts/${receipt.id}/edit`) },
          { label: 'Confirm Receipt', icon: Send, onClick: () => submitMutation.mutate(receipt.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingReceipt(receipt) })
      }
    }
    // submitted is terminal — Goods Receipt has no cancel action (see goodsReceiptApi.ts).

    return actions
  }

  const columns: DataTableColumn<GoodsReceipt>[] = [
    { header: 'Document Number', accessor: (row) => row.document_number ?? '—', sortKey: 'document_number' },
    {
      header: 'Purchase Order',
      accessor: (row) => (
        <Button
          variant="link"
          className="h-auto p-0"
          onClick={(event) => {
            event.stopPropagation()
            navigate(`/purchase/orders/${row.purchase_order_id}`)
          }}
        >
          {purchaseOrderNumber(row.purchase_order_id)}
          <ExternalLink className="size-3.5" />
        </Button>
      ),
    },
    { header: 'Supplier', accessor: (row) => row.supplier?.supplier_name ?? '—' },
    { header: 'Warehouse', accessor: (row) => row.warehouse?.name ?? '—' },
    { header: 'Receipt Date', accessor: (row) => formatDate(row.receipt_date), sortKey: 'receipt_date' },
    {
      header: 'Qty Received',
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
      <SectionNav group="purchase" />

      <PageHeader
        title="Goods Receipts"
        description="Receive ordered goods into a warehouse against a submitted Purchase Order."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} receipts` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Goods Receipt', icon: Plus, onClick: () => navigate('/purchase/goods-receipts/new') } : undefined}
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
        <GoodsReceiptFiltersBar
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
        emptyMessage={hasFilters ? 'No goods receipts match your search or filters.' : 'No goods receipts yet.'}
        onRowClick={(row) => navigate(`/purchase/goods-receipts/${row.id}`)}
        sort={sort}
        onSortChange={handleSortChange}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <DeleteDialog
        open={!!deletingReceipt}
        onOpenChange={(open) => !open && setDeletingReceipt(null)}
        itemLabel={deletingReceipt?.document_number ?? undefined}
        onConfirm={() => {
          if (deletingReceipt) deleteMutation.mutate(deletingReceipt.id)
        }}
      />
    </div>
  )
}
