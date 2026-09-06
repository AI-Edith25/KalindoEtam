import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, Eye, Pencil, Plus, RotateCw, Send, Trash2, Upload, XCircle } from 'lucide-react'
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
import { cancelIssueStock, deleteIssueStock, fetchIssueStocks, submitIssueStock } from '../api/issueStockApi'
import { IssueStockFiltersBar } from '../components/IssueStockFiltersBar'
import { emptyIssueStockFilters } from '../lib/issueStockFilters'
import type { IssueStock, IssueStockFilterValues } from '../types'

const SORTERS: Record<string, (issueStock: IssueStock) => string | number> = {
  document_number: (issueStock) => issueStock.document_number ?? '',
  issue_date: (issueStock) => issueStock.issue_date,
}

/** Records stock leaving the warehouse outside of a sale (internal use, damage, samples). Same shell as OpeningStockListPage, no import wizard. */
export function IssueStockListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('inventory.issue_stock.create')
  const canUpdate = useHasPermission('inventory.issue_stock.update')
  const canDelete = useHasPermission('inventory.issue_stock.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<IssueStockFilterValues>(emptyIssueStockFilters)
  const [sort, setSort] = useState<DataTableSort | undefined>(undefined)
  const [deletingIssueStock, setDeletingIssueStock] = useState<IssueStock | null>(null)

  const listQuery = useQuery({
    queryKey: ['issue-stocks', page, search, filters.warehouse_id, filters.status, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchIssueStocks({
        page,
        ...(search ? { search } : {}),
        ...(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['issue-stocks'] })
    queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
    queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
    queryClient.invalidateQueries({ queryKey: ['fifo-layers'] })
  }

  const submitMutation = useMutation({
    mutationFn: submitIssueStock,
    onSuccess: () => {
      invalidate()
      toast.success('Issue Stock submitted — FIFO layers consumed.')
    },
    onError: (error) => toastApiError(error),
  })

  const cancelMutation = useMutation({
    mutationFn: cancelIssueStock,
    onSuccess: () => {
      invalidate()
      toast.success('Issue Stock cancelled.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteIssueStock,
    onSuccess: () => {
      invalidate()
      toast.success('Issue Stock deleted.')
      setDeletingIssueStock(null)
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

  const actionsFor = (issueStock: IssueStock): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/inventory/issue-stock/${issueStock.id}`) }]

    if (issueStock.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/inventory/issue-stock/${issueStock.id}/edit`) },
          { label: 'Submit', icon: Send, onClick: () => submitMutation.mutate(issueStock.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingIssueStock(issueStock) })
      }
    } else if (issueStock.status === 'submitted' && canUpdate) {
      actions.push({ label: 'Cancel', icon: XCircle, variant: 'destructive', onClick: () => cancelMutation.mutate(issueStock.id) })
    }

    return actions
  }

  const columns: DataTableColumn<IssueStock>[] = [
    { header: 'Document Number', accessor: (row) => row.document_number ?? '—', sortKey: 'document_number' },
    { header: 'Issue Date', accessor: (row) => formatDate(row.issue_date), sortKey: 'issue_date' },
    { header: 'Warehouse', accessor: (row) => row.warehouse?.name ?? '—' },
    { header: 'Lines', accessor: (row) => formatNumber(row.line_count ?? 0), className: 'text-right' },
    { header: 'Total Value', accessor: (row) => (row.total_value === null ? '—' : formatCurrency(row.total_value)), className: 'text-right' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => <RowActionsMenu actions={actionsFor(row)} />,
    },
  ]

  const hasFilters = !!(search || filters.warehouse_id || filters.status || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="inventory" />

      <PageHeader
        title="Issue Stock"
        description="Record stock leaving the warehouse outside of a sale — internal use, damage, samples."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} documents` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Issue Stock', icon: Plus, onClick: () => navigate('/inventory/issue-stock/new') } : undefined}
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
        <IssueStockFiltersBar
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
        emptyMessage={hasFilters ? 'No issue stock documents match your search or filters.' : 'No issue stock documents yet.'}
        onRowClick={(row) => navigate(`/inventory/issue-stock/${row.id}`)}
        sort={sort}
        onSortChange={handleSortChange}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <DeleteDialog
        open={!!deletingIssueStock}
        onOpenChange={(open) => !open && setDeletingIssueStock(null)}
        itemLabel={deletingIssueStock?.document_number ?? undefined}
        onConfirm={() => {
          if (deletingIssueStock) deleteMutation.mutate(deletingIssueStock.id)
        }}
      />
    </div>
  )
}
