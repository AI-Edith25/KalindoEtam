import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowRight, Eye, Pencil, Plus, RotateCw, Send, Trash2 } from 'lucide-react'
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
import { formatDate, formatNumber } from '@/lib/utils'
import { deleteStockTransfer, fetchStockTransfers, submitStockTransfer } from '../api/stockTransferApi'
import { StockTransferFiltersBar } from '../components/StockTransferFiltersBar'
import { emptyStockTransferFilters } from '../lib/stockTransferFilters'
import type { StockTransfer, StockTransferFilterValues } from '../types'

const SORTERS: Record<string, (transfer: StockTransfer) => string | number> = {
  document_number: (transfer) => transfer.document_number ?? '',
  transfer_date: (transfer) => transfer.transfer_date,
}

function totalQty(transfer: StockTransfer): number {
  return transfer.items.reduce((sum, line) => sum + Number(line.qty), 0)
}

export function StockTransferListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('inventory.transfers.create')
  const canUpdate = useHasPermission('inventory.transfers.update')
  const canDelete = useHasPermission('inventory.transfers.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<StockTransferFilterValues>(emptyStockTransferFilters)
  const [sort, setSort] = useState<DataTableSort | undefined>(undefined)
  const [deletingTransfer, setDeletingTransfer] = useState<StockTransfer | null>(null)

  const listQuery = useQuery({
    queryKey: ['stock-transfers', page, search, filters.status, filters.warehouse_id, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchStockTransfers({
        page,
        ...(search ? { search } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['stock-transfers'] })

  const submitMutation = useMutation({
    mutationFn: submitStockTransfer,
    onSuccess: () => {
      invalidate()
      queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
      queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
      toast.success('Stock Transfer confirmed — stock moved.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteStockTransfer,
    onSuccess: () => {
      invalidate()
      toast.success('Stock Transfer deleted.')
      setDeletingTransfer(null)
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

  const actionsFor = (transfer: StockTransfer): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/inventory/transfers/${transfer.id}`) }]

    if (transfer.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/inventory/transfers/${transfer.id}/edit`) },
          { label: 'Confirm Transfer', icon: Send, onClick: () => submitMutation.mutate(transfer.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingTransfer(transfer) })
      }
    }
    // submitted is terminal — Stock Transfer has no cancel action (see stockTransferApi.ts).

    return actions
  }

  const columns: DataTableColumn<StockTransfer>[] = [
    { header: 'Document Number', accessor: (row) => row.document_number ?? '—', sortKey: 'document_number' },
    {
      header: 'Route',
      accessor: (row) => (
        <span className="inline-flex items-center gap-1.5">
          {row.source_warehouse?.name ?? '—'}
          <ArrowRight className="size-3.5 text-muted-foreground" />
          {row.destination_warehouse?.name ?? '—'}
        </span>
      ),
    },
    { header: 'Transfer Date', accessor: (row) => formatDate(row.transfer_date), sortKey: 'transfer_date' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
    { header: 'Total Qty', accessor: (row) => formatNumber(totalQty(row)), className: 'text-right' },
    { header: 'Lines', accessor: (row) => formatNumber(row.items.length), className: 'text-right' },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => <RowActionsMenu actions={actionsFor(row)} />,
    },
  ]

  const hasFilters = !!(search || filters.status || filters.warehouse_id || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="inventory" />

      <PageHeader
        title="Transfer Stock"
        description="Move stock directly between warehouses."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} transfers` : undefined}
        actions={
          <ActionBar
            actions={[{ label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching }]}
            primary={canCreate ? { label: 'New Transfer', icon: Plus, onClick: () => navigate('/inventory/transfers/new') } : undefined}
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
        <StockTransferFiltersBar
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
        emptyMessage={hasFilters ? 'No stock transfers match your search or filters.' : 'No stock transfers yet.'}
        onRowClick={(row) => navigate(`/inventory/transfers/${row.id}`)}
        sort={sort}
        onSortChange={handleSortChange}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <DeleteDialog
        open={!!deletingTransfer}
        onOpenChange={(open) => !open && setDeletingTransfer(null)}
        itemLabel={deletingTransfer?.document_number ?? undefined}
        onConfirm={() => {
          if (deletingTransfer) deleteMutation.mutate(deletingTransfer.id)
        }}
      />
    </div>
  )
}
