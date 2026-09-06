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
import { cancelReceiptStock, deleteReceiptStock, fetchReceiptStocks, submitReceiptStock } from '../api/receiptStockApi'
import { ReceiptStockFiltersBar } from '../components/ReceiptStockFiltersBar'
import { emptyReceiptStockFilters } from '../lib/receiptStockFilters'
import type { ReceiptStock, ReceiptStockFilterValues } from '../types'

const SORTERS: Record<string, (receiptStock: ReceiptStock) => string | number> = {
  document_number: (receiptStock) => receiptStock.document_number ?? '',
  receipt_date: (receiptStock) => receiptStock.receipt_date,
}

/** Records stock coming in outside of a purchase (returns from usage, stock found, supplier gifts). Same shell as OpeningStockListPage, no import wizard. */
export function ReceiptStockListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('inventory.receipt_stock.create')
  const canUpdate = useHasPermission('inventory.receipt_stock.update')
  const canDelete = useHasPermission('inventory.receipt_stock.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<ReceiptStockFilterValues>(emptyReceiptStockFilters)
  const [sort, setSort] = useState<DataTableSort | undefined>(undefined)
  const [deletingReceiptStock, setDeletingReceiptStock] = useState<ReceiptStock | null>(null)

  const listQuery = useQuery({
    queryKey: ['receipt-stocks', page, search, filters.warehouse_id, filters.status, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchReceiptStocks({
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
    queryClient.invalidateQueries({ queryKey: ['receipt-stocks'] })
    queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
    queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
    queryClient.invalidateQueries({ queryKey: ['fifo-layers'] })
  }

  const submitMutation = useMutation({
    mutationFn: submitReceiptStock,
    onSuccess: () => {
      invalidate()
      toast.success('Receipt Stock submitted — layer created.')
    },
    onError: (error) => toastApiError(error),
  })

  const cancelMutation = useMutation({
    mutationFn: cancelReceiptStock,
    onSuccess: () => {
      invalidate()
      toast.success('Receipt Stock cancelled.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteReceiptStock,
    onSuccess: () => {
      invalidate()
      toast.success('Receipt Stock deleted.')
      setDeletingReceiptStock(null)
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

  const actionsFor = (receiptStock: ReceiptStock): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/inventory/receipt-stock/${receiptStock.id}`) }]

    if (receiptStock.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/inventory/receipt-stock/${receiptStock.id}/edit`) },
          { label: 'Submit', icon: Send, onClick: () => submitMutation.mutate(receiptStock.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingReceiptStock(receiptStock) })
      }
    } else if (receiptStock.status === 'submitted' && canUpdate) {
      actions.push({ label: 'Cancel', icon: XCircle, variant: 'destructive', onClick: () => cancelMutation.mutate(receiptStock.id) })
    }

    return actions
  }

  const columns: DataTableColumn<ReceiptStock>[] = [
    { header: 'Document Number', accessor: (row) => row.document_number ?? '—', sortKey: 'document_number' },
    { header: 'Receipt Date', accessor: (row) => formatDate(row.receipt_date), sortKey: 'receipt_date' },
    { header: 'Warehouse', accessor: (row) => row.warehouse?.name ?? '—' },
    { header: 'Lines', accessor: (row) => formatNumber(row.line_count ?? 0), className: 'text-right' },
    { header: 'Total Value', accessor: (row) => formatCurrency(row.total_value ?? 0), className: 'text-right' },
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
        title="Receipt Stock"
        description="Record stock coming in outside of a purchase — returns from usage, stock found, supplier gifts."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} documents` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Receipt Stock', icon: Plus, onClick: () => navigate('/inventory/receipt-stock/new') } : undefined}
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
        <ReceiptStockFiltersBar
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
        emptyMessage={hasFilters ? 'No receipt stock documents match your search or filters.' : 'No receipt stock documents yet.'}
        onRowClick={(row) => navigate(`/inventory/receipt-stock/${row.id}`)}
        sort={sort}
        onSortChange={handleSortChange}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <DeleteDialog
        open={!!deletingReceiptStock}
        onOpenChange={(open) => !open && setDeletingReceiptStock(null)}
        itemLabel={deletingReceiptStock?.document_number ?? undefined}
        onConfirm={() => {
          if (deletingReceiptStock) deleteMutation.mutate(deletingReceiptStock.id)
        }}
      />
    </div>
  )
}
