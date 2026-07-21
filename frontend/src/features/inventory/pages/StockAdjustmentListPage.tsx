import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, Eye, Pencil, Plus, RotateCw, Send, Trash2, Upload } from 'lucide-react'
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
import { cn, formatDate, formatNumber } from '@/lib/utils'
import { deleteStockAdjustment, fetchStockAdjustments, submitStockAdjustment } from '../api/stockAdjustmentApi'
import { StockAdjustmentFiltersBar } from '../components/StockAdjustmentFiltersBar'
import { emptyStockAdjustmentFilters } from '../lib/stockAdjustmentFilters'
import { inventorySectionNav } from '../navigation'
import type { StockAdjustment, StockAdjustmentFilterValues } from '../types'

const SORTERS: Record<string, (adjustment: StockAdjustment) => string | number> = {
  document_number: (adjustment) => adjustment.document_number ?? '',
  adjustment_date: (adjustment) => adjustment.adjustment_date,
}

/** Aggregates a document's lines into one summary — a Stock Adjustment can cover several items, so the list shows the total physical/system/difference across all of them, not a single line's numbers. */
function adjustmentTotals(adjustment: StockAdjustment) {
  return {
    physical: adjustment.items.reduce((sum, line) => sum + line.counted_qty, 0),
    system: adjustment.items.reduce((sum, line) => sum + line.system_qty, 0),
    difference: adjustment.items.reduce((sum, line) => sum + line.difference_qty, 0),
  }
}

export function StockAdjustmentListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('stock.create')
  const canUpdate = useHasPermission('stock.update')
  const canDelete = useHasPermission('stock.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<StockAdjustmentFilterValues>(emptyStockAdjustmentFilters)
  const [sort, setSort] = useState<DataTableSort | undefined>(undefined)
  const [deletingAdjustment, setDeletingAdjustment] = useState<StockAdjustment | null>(null)

  const listQuery = useQuery({
    queryKey: ['stock-adjustments', page, search, filters.status, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchStockAdjustments({
        page,
        ...(search ? { search } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['stock-adjustments'] })

  const submitMutation = useMutation({
    mutationFn: submitStockAdjustment,
    onSuccess: () => {
      invalidate()
      queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
      queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
      toast.success('Stock Adjustment confirmed — stock updated.')
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteStockAdjustment,
    onSuccess: () => {
      invalidate()
      toast.success('Stock Adjustment deleted.')
      setDeletingAdjustment(null)
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

  const actionsFor = (adjustment: StockAdjustment): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/inventory/adjustments/${adjustment.id}`) }]

    if (adjustment.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/inventory/adjustments/${adjustment.id}/edit`) },
          { label: 'Confirm Adjustment', icon: Send, onClick: () => submitMutation.mutate(adjustment.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingAdjustment(adjustment) })
      }
    }
    // submitted is terminal — Stock Adjustment has no cancel action (see stockAdjustmentApi.ts).

    return actions
  }

  const columns: DataTableColumn<StockAdjustment>[] = [
    { header: 'Document Number', accessor: (row) => row.document_number ?? '—', sortKey: 'document_number' },
    { header: 'Warehouse', accessor: (row) => row.warehouse?.name ?? '—' },
    { header: 'Adjustment Date', accessor: (row) => formatDate(row.adjustment_date), sortKey: 'adjustment_date' },
    {
      header: 'Physical / System',
      accessor: (row) => {
        const { physical, system } = adjustmentTotals(row)
        return `${formatNumber(physical)} / ${formatNumber(system)}`
      },
      className: 'text-right',
    },
    {
      header: 'Difference',
      accessor: (row) => {
        const { difference } = adjustmentTotals(row)
        return (
          <span
            className={cn(
              'font-medium',
              difference > 0 && 'text-green-600 dark:text-green-400',
              difference < 0 && 'text-destructive',
            )}
          >
            {difference > 0 ? '+' : ''}
            {formatNumber(difference)}
          </span>
        )
      },
      className: 'text-right',
    },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
    { header: 'Lines', accessor: (row) => formatNumber(row.items.length), className: 'text-right' },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => <RowActionsMenu actions={actionsFor(row)} />,
    },
  ]

  const hasFilters = !!(search || filters.status || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={inventorySectionNav} />

      <PageHeader
        title="Stock Adjustments"
        description="Reconcile physical counts against system records."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} adjustments` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Adjustment', icon: Plus, onClick: () => navigate('/inventory/adjustments/new') } : undefined}
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
          placeholder="Search document number or warehouse…"
        />
        <StockAdjustmentFiltersBar
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
        emptyMessage={hasFilters ? 'No stock adjustments match your search or filters.' : 'No stock adjustments yet.'}
        onRowClick={(row) => navigate(`/inventory/adjustments/${row.id}`)}
        sort={sort}
        onSortChange={handleSortChange}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <DeleteDialog
        open={!!deletingAdjustment}
        onOpenChange={(open) => !open && setDeletingAdjustment(null)}
        itemLabel={deletingAdjustment?.document_number ?? undefined}
        onConfirm={() => {
          if (deletingAdjustment) deleteMutation.mutate(deletingAdjustment.id)
        }}
      />
    </div>
  )
}
