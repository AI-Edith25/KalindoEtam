import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, Eye, Pencil, Plus, RotateCw, Send, Trash2, Upload, X, XCircle } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
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
import {
  cancelOpeningStock,
  cancelOpeningStockBatch,
  deleteOpeningStock,
  fetchOpeningStocks,
  submitOpeningStock,
  submitOpeningStockBatch,
} from '../api/openingStockApi'
import { OpeningStockFiltersBar } from '../components/OpeningStockFiltersBar'
import { emptyOpeningStockFilters } from '../lib/openingStockFilters'
import type { OpeningStock, OpeningStockFilterValues } from '../types'

const SORTERS: Record<string, (openingStock: OpeningStock) => string | number> = {
  document_number: (openingStock) => openingStock.document_number ?? '',
  cutoff_date: (openingStock) => openingStock.cutoff_date,
}

export function OpeningStockListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('inventory.opening_stock.create')
  const canUpdate = useHasPermission('inventory.opening_stock.update')
  const canDelete = useHasPermission('inventory.opening_stock.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<OpeningStockFilterValues>(emptyOpeningStockFilters)
  const [sort, setSort] = useState<DataTableSort | undefined>(undefined)
  const [deletingOpeningStock, setDeletingOpeningStock] = useState<OpeningStock | null>(null)

  const listQuery = useQuery({
    queryKey: [
      'opening-stocks',
      page,
      search,
      filters.warehouse_id,
      filters.status,
      filters.dateFrom,
      filters.dateTo,
      filters.importBatchId,
    ],
    queryFn: () =>
      fetchOpeningStocks({
        page,
        ...(search ? { search } : {}),
        ...(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
        ...(filters.importBatchId ? { import_batch_id: filters.importBatchId } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const filteredBatchFilename = listQuery.data?.data.find((row) => row.import_batch_id === filters.importBatchId)?.import_batch_filename

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['opening-stocks'] })
    queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
    queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
    queryClient.invalidateQueries({ queryKey: ['fifo-layers'] })
  }

  const submitMutation = useMutation({
    mutationFn: submitOpeningStock,
    onSuccess: () => {
      invalidate()
      toast.success('Opening Stock submitted — layers created.')
    },
    onError: (error) => toastApiError(error),
  })

  const cancelMutation = useMutation({
    mutationFn: cancelOpeningStock,
    onSuccess: () => {
      invalidate()
      toast.success('Opening Stock cancelled.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteOpeningStock,
    onSuccess: () => {
      invalidate()
      toast.success('Opening Stock deleted.')
      setDeletingOpeningStock(null)
    },
    onError: (error) => toastApiError(error),
  })

  const submitBatchMutation = useMutation({
    mutationFn: submitOpeningStockBatch,
    onSuccess: (documents) => {
      invalidate()
      toast.success(`Submitted ${documents.length} document(s) in this batch.`)
    },
    onError: (error) => toastApiError(error),
  })

  const cancelBatchMutation = useMutation({
    mutationFn: cancelOpeningStockBatch,
    onSuccess: (documents) => {
      invalidate()
      toast.success(`Cancelled ${documents.length} document(s) in this batch.`)
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

  const actionsFor = (openingStock: OpeningStock): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/inventory/opening-stock/${openingStock.id}`) }]

    if (openingStock.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/inventory/opening-stock/${openingStock.id}/edit`) },
          { label: 'Submit', icon: Send, onClick: () => submitMutation.mutate(openingStock.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingOpeningStock(openingStock) })
      }
    } else if (openingStock.status === 'submitted' && canUpdate) {
      actions.push({ label: 'Cancel', icon: XCircle, variant: 'destructive', onClick: () => cancelMutation.mutate(openingStock.id) })
    }

    return actions
  }

  const columns: DataTableColumn<OpeningStock>[] = [
    { header: 'Document Number', accessor: (row) => row.document_number ?? '—', sortKey: 'document_number' },
    { header: 'Cutoff Date', accessor: (row) => formatDate(row.cutoff_date), sortKey: 'cutoff_date' },
    { header: 'Warehouse', accessor: (row) => row.warehouse?.name ?? '—' },
    {
      header: 'Batch',
      accessor: (row) =>
        row.import_batch_id ? (
          <Badge
            variant="secondary"
            className="cursor-pointer"
            onClick={(e) => {
              e.stopPropagation()
              setFilters((prev) => ({ ...prev, importBatchId: row.import_batch_id! }))
              setPage(1)
            }}
          >
            {row.import_batch_filename ?? 'Imported'}
          </Badge>
        ) : (
          '—'
        ),
    },
    { header: 'Lines', accessor: (row) => formatNumber(row.line_count ?? 0), className: 'text-right' },
    { header: 'Total Value', accessor: (row) => formatCurrency(row.total_value ?? 0), className: 'text-right' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => <RowActionsMenu actions={actionsFor(row)} />,
    },
  ]

  const hasFilters = !!(search || filters.warehouse_id || filters.status || filters.dateFrom || filters.dateTo || filters.importBatchId)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="inventory" />

      <PageHeader
        title="Opening Stock"
        description="Enter opening inventory balances and their cost, separate from any purchase document."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} documents` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: !canCreate, onClick: () => navigate('/inventory/opening-stock/quick-import') },
            ]}
            primary={canCreate ? { label: 'New Opening Stock', icon: Plus, onClick: () => navigate('/inventory/opening-stock/new') } : undefined}
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
          placeholder="Search document number…"
        />
        <OpeningStockFiltersBar
          value={filters}
          onChange={(value) => {
            setFilters(value)
            setPage(1)
          }}
        />
      </div>

      {filters.importBatchId && (
        <div className="flex flex-wrap items-center gap-2 rounded-md border bg-muted/50 p-3">
          <Badge variant="secondary">Batch: {filteredBatchFilename ?? filters.importBatchId}</Badge>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => {
              setFilters((prev) => ({ ...prev, importBatchId: '' }))
              setPage(1)
            }}
          >
            <X className="size-4" />
            Clear
          </Button>
          {canUpdate && (
            <>
              <Button
                type="button"
                size="sm"
                onClick={() => submitBatchMutation.mutate(filters.importBatchId)}
                disabled={submitBatchMutation.isPending}
              >
                <Send className="size-4" />
                Submit All
              </Button>
              <Button
                type="button"
                variant="destructive"
                size="sm"
                onClick={() => cancelBatchMutation.mutate(filters.importBatchId)}
                disabled={cancelBatchMutation.isPending}
              >
                <XCircle className="size-4" />
                Cancel All
              </Button>
            </>
          )}
        </div>
      )}

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={hasFilters ? 'No opening stock documents match your search or filters.' : 'No opening stock documents yet.'}
        onRowClick={(row) => navigate(`/inventory/opening-stock/${row.id}`)}
        sort={sort}
        onSortChange={handleSortChange}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <DeleteDialog
        open={!!deletingOpeningStock}
        onOpenChange={(open) => !open && setDeletingOpeningStock(null)}
        itemLabel={deletingOpeningStock?.document_number ?? undefined}
        onConfirm={() => {
          if (deletingOpeningStock) deleteMutation.mutate(deletingOpeningStock.id)
        }}
      />
    </div>
  )
}
