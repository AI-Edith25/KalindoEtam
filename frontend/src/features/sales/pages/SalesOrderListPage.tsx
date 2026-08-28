import { useMemo, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, ChevronDown, Download, Eye, Pencil, Plus, Printer, RotateCw, Send, Trash2 } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn, type DataTableSort } from '@/components/shared/DataTable'
import { RowActionsMenu, type RowAction } from '@/components/shared/RowActionsMenu'
import { Pagination } from '@/components/shared/Pagination'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SectionNav } from '@/components/shared/SectionNav'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { AdvancedFilterToolbar, type AdvancedFilterValue } from '@/components/shared/AdvancedFilterToolbar'
import { ExportColumnPickerDialog, type ExportColumn } from '@/components/shared/ExportColumnPickerDialog'
import { toastApiError } from '@/shared/services/errorHandler'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { useUrlFilters } from '@/shared/hooks/useUrlFilters'
import { useRowSelection } from '@/shared/hooks/useRowSelection'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { fetchCustomersLookup, fetchSalesPersonsLookup } from '@/features/master/api/lookupsApi'
import { approveSalesOrder, cancelSalesOrder, deleteSalesOrder, exportSalesOrders, fetchSalesOrders } from '../api/salesOrderApi'
import { BULK_PRINT_MAX_DOCUMENTS } from './SalesOrderBulkPrintPage'
import type { SalesOrder } from '../types'

const SORTERS: Record<string, (so: SalesOrder) => string | number> = {
  document_number: (so) => so.document_number ?? '',
  order_date: (so) => so.order_date,
  total_amount: (so) => Number(so.total_amount),
}

const STATUS_OPTIONS = [
  { value: 'submitted', label: 'Submitted' },
  { value: 'approved', label: 'Approved' },
  { value: 'cancelled', label: 'Cancelled' },
]

const EXPORT_COLUMNS: ExportColumn[] = [
  { key: 'order_date', label: 'Date' },
  { key: 'document_number', label: 'Document' },
  { key: 'customer_name', label: 'Customer Name' },
  { key: 'total_amount', label: 'Amount' },
  { key: 'status', label: 'Status' },
]

const EMPTY_FILTERS: AdvancedFilterValue = {
  search: '',
  date_from: '',
  date_to: '',
  preset: 'custom',
  status: [],
  customer_id: '',
  sales_person_id: '',
  warehouse_id: '',
  reason: '',
  sales_order_number: '',
  min_amount: '',
  max_amount: '',
}

export function SalesOrderListPage() {
  const navigate = useNavigate()
  const isOutstanding = useLocation().pathname.endsWith('/outstanding')
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('sales.orders.create')
  const canUpdate = useHasPermission('sales.orders.update')
  const canDelete = useHasPermission('sales.orders.delete')
  const canApprove = useHasPermission('sales.orders.approve')

  const [page, setPage] = useState(1)
  const [sort, setSort] = useState<DataTableSort | undefined>(undefined)
  const [deletingOrder, setDeletingOrder] = useState<SalesOrder | null>(null)
  const [exportPickerOpen, setExportPickerOpen] = useState(false)
  const [pendingExportFormat, setPendingExportFormat] = useState<'xlsx' | 'csv' | null>(null)

  const [urlFilters, setUrlFilters, resetUrlFilters] = useUrlFilters<AdvancedFilterValue>(EMPTY_FILTERS)
  const [draft, setDraft] = useState<AdvancedFilterValue>(urlFilters)

  const customersQuery = useQuery({ queryKey: ['customers-lookup'], queryFn: fetchCustomersLookup })
  const salesPersonsQuery = useQuery({ queryKey: ['sales-persons-lookup'], queryFn: fetchSalesPersonsLookup })
  const customerOptions = (customersQuery.data ?? []).map((customer) => ({ value: customer.id, label: customer.customer_name }))
  const salesPersonOptions = (salesPersonsQuery.data ?? []).map((salesPerson) => ({ value: salesPerson.id, label: salesPerson.name }))

  const queryFilters = {
    ...(urlFilters.search ? { search: urlFilters.search } : {}),
    ...(urlFilters.status.length > 0 ? { status: urlFilters.status } : {}),
    ...(urlFilters.date_from ? { date_from: urlFilters.date_from } : {}),
    ...(urlFilters.date_to ? { date_to: urlFilters.date_to } : {}),
    ...(urlFilters.customer_id ? { customer_id: urlFilters.customer_id } : {}),
    ...(urlFilters.sales_person_id ? { sales_person_id: urlFilters.sales_person_id } : {}),
    ...(isOutstanding ? { outstanding: true } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['sales-orders', page, queryFilters, isOutstanding],
    queryFn: () => fetchSalesOrders({ page, ...queryFilters }),
    placeholderData: (previous) => previous,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['sales-orders'] })

  const approveMutation = useMutation({
    // Row-action quick approve — no room here for the Editor/Detail pages' pre-emptive credit
    // banner, but the backend gate still applies; a block surfaces as a clear toast error
    // (see CustomerCreditService's message) rather than a silent failure.
    mutationFn: (id: string) => approveSalesOrder(id),
    onSuccess: () => {
      invalidate()
      toast.success('Sales Order approved.')
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

  const totalFiltered = listQuery.data?.meta?.total ?? 0
  const selection = useRowSelection<SalesOrder>(rows, totalFiltered, { resetKey: [page, JSON.stringify(queryFilters)].join('|') })

  const handleSortChange = (key: string) => {
    setSort((prev) => (prev?.key === key ? { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: 'asc' }))
  }

  const actionsFor = (order: SalesOrder): RowAction[] => {
    const actions: RowAction[] = [
      { label: 'View', icon: Eye, onClick: () => navigate(`/sales/orders/${order.id}`) },
      { label: 'Print', icon: Printer, onClick: () => navigate(`/sales/orders/${order.id}/print`) },
    ]

    if (order.status === 'submitted') {
      if (canUpdate) {
        actions.push({ label: 'Edit', icon: Pencil, onClick: () => navigate(`/sales/orders/${order.id}/edit`) })
      }
      if (canApprove) {
        actions.push({ label: 'Approve', icon: Send, onClick: () => approveMutation.mutate(order.id) })
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingOrder(order) })
      }
    }
    if ((order.status === 'submitted' || order.status === 'approved') && canUpdate) {
      actions.push({ label: 'Cancel', icon: Ban, variant: 'destructive', onClick: () => cancelMutation.mutate(order.id) })
    }

    return actions
  }

  const columns: DataTableColumn<SalesOrder>[] = [
    selection.selectionColumn,
    { header: 'Date', accessor: (row) => formatDate(row.order_date), sortKey: 'order_date' },
    { header: 'Document', accessor: (row) => row.document_number ?? '—', sortKey: 'document_number' },
    { header: 'Customer Name', accessor: (row) => row.customer?.customer_name ?? '—' },
    {
      header: 'Amount',
      accessor: (row) => formatCurrency(row.total_amount),
      className: 'text-right',
      sortKey: 'total_amount',
    },
    {
      header: 'Status',
      accessor: (row) => (
        <div className="flex items-center gap-1.5">
          <StatusBadge status={row.status} />
          {!isOutstanding && row.status === 'approved' && row.is_fully_delivered !== null && (
            <StatusBadge status={row.is_fully_delivered ? 'fully_delivered' : 'outstanding'} />
          )}
        </div>
      ),
    },
    ...(isOutstanding
      ? [
          {
            header: 'Progress',
            accessor: (row: SalesOrder) => {
              const items = row.items ?? []
              const deliveredCount = items.filter((item) => item.delivered_qty >= item.qty).length
              return `${deliveredCount}/${items.length} terkirim`
            },
          } satisfies DataTableColumn<SalesOrder>,
        ]
      : []),
    {
      header: '',
      className: 'text-right',
      accessor: (row) => <RowActionsMenu actions={actionsFor(row)} />,
    },
  ]

  const hasFilters = !!(urlFilters.search || urlFilters.status.length > 0 || urlFilters.date_from || urlFilters.date_to || urlFilters.customer_id || urlFilters.sales_person_id)

  const applyFilters = () => {
    setUrlFilters(draft)
    setPage(1)
  }

  const resetFilters = () => {
    setDraft(EMPTY_FILTERS)
    resetUrlFilters()
    setPage(1)
  }

  const removeFilter = (patch: Partial<AdvancedFilterValue>) => {
    const next = { ...urlFilters, ...patch }
    setDraft(next)
    setUrlFilters(patch)
    setPage(1)
  }

  const chips = [
    urlFilters.search && { key: 'search', label: `Cari: ${urlFilters.search}`, onRemove: () => removeFilter({ search: '' }) },
    urlFilters.date_from && { key: 'date_from', label: `Dari: ${urlFilters.date_from}`, onRemove: () => removeFilter({ date_from: '', preset: 'custom' as const }) },
    urlFilters.date_to && { key: 'date_to', label: `Sampai: ${urlFilters.date_to}`, onRemove: () => removeFilter({ date_to: '', preset: 'custom' as const }) },
    ...urlFilters.status.map((status) => ({
      key: `status-${status}`,
      label: STATUS_OPTIONS.find((option) => option.value === status)?.label ?? status,
      onRemove: () => removeFilter({ status: urlFilters.status.filter((s) => s !== status) }),
    })),
    urlFilters.customer_id && {
      key: 'customer',
      label: `Customer: ${customerOptions.find((option) => option.value === urlFilters.customer_id)?.label ?? '—'}`,
      onRemove: () => removeFilter({ customer_id: '' }),
    },
    urlFilters.sales_person_id && {
      key: 'sales_person',
      label: `Sales: ${salesPersonOptions.find((option) => option.value === urlFilters.sales_person_id)?.label ?? '—'}`,
      onRemove: () => removeFilter({ sales_person_id: '' }),
    },
  ].filter((chip): chip is { key: string; label: string; onRemove: () => void } => !!chip)

  const runExport = async (format: 'xlsx' | 'csv', columns?: string[]) => {
    try {
      const blob = await exportSalesOrders({
        format,
        columns,
        ids: selection.selectedIdsForRequest ?? undefined,
        ...queryFilters,
      })
      const today = new Date().toISOString().slice(0, 10)
      downloadBlob(`sales_orders_${urlFilters.date_from || today}_${urlFilters.date_to || today}.${format}`, blob)
      toast.success('Export started — check your downloads.')
    } catch (error) {
      toastApiError(error)
    }
  }

  // null/undefined selectedIdsForRequest = "select all filtered" mode; an empty array means
  // nothing is checked at all — both cases mean "act on the active filter", not a specific list.
  const hasExplicitSelection = !!selection.selectedIdsForRequest && selection.selectedIdsForRequest.length > 0

  const selectedDocumentIds = async (): Promise<string[]> => {
    if (hasExplicitSelection) return selection.selectedIdsForRequest!

    // No specific rows checked (either "select all filtered" or nothing at all) — bulk print
    // needs actual documents, not just a filter, so resolve up to the print cap worth of ids.
    const response = await fetchSalesOrders({ page: 1, per_page: BULK_PRINT_MAX_DOCUMENTS, ...queryFilters })
    return response.data.map((row) => row.id)
  }

  const printSelectedDocuments = async () => {
    const ids = await selectedDocumentIds()
    if (ids.length === 0) {
      toast.error('No documents to print.')
      return
    }
    if (ids.length > BULK_PRINT_MAX_DOCUMENTS) {
      toast.error(`Maksimal ${BULK_PRINT_MAX_DOCUMENTS} dokumen per sekali cetak. ${ids.length} dokumen dipilih.`)
      return
    }
    navigate(`/sales/orders/print-bulk?ids=${ids.join(',')}`)
  }

  const printListSummary = () => {
    if (hasExplicitSelection) {
      navigate(`/sales/orders/print-list?ids=${selection.selectedIdsForRequest!.join(',')}`)
      return
    }
    const params = new URLSearchParams()
    Object.entries(queryFilters).forEach(([key, value]) => {
      if (Array.isArray(value)) value.forEach((v) => params.append(key, v))
      else params.set(key, String(value))
    })
    navigate(`/sales/orders/print-list?${params.toString()}`)
  }

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="sales" />

      <PageHeader
        title="Sales Orders"
        description="Track customer orders from creation through submission. Inventory is not affected until Delivery."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} orders` : undefined}
        actions={
          <div className="flex items-center gap-2">
            <ActionBar actions={[{ label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching }]} />

            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline">
                  <Download className="size-4" />
                  Export
                  <ChevronDown className="size-3.5" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem
                  onSelect={() => {
                    setPendingExportFormat('xlsx')
                    setExportPickerOpen(true)
                  }}
                >
                  Export XLSX
                </DropdownMenuItem>
                <DropdownMenuItem
                  onSelect={() => {
                    setPendingExportFormat('csv')
                    setExportPickerOpen(true)
                  }}
                >
                  Export CSV
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={printListSummary}>Export PDF (Print Preview)</DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline">
                  <Printer className="size-4" />
                  Print
                  <ChevronDown className="size-3.5" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={printSelectedDocuments}>Print Documents (Bulk)</DropdownMenuItem>
                <DropdownMenuItem onSelect={printListSummary}>Print List Summary</DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>

            {canCreate && (
              <Button type="button" onClick={() => navigate('/sales/orders/new')}>
                <Plus className="size-4" />
                New Sales Order
              </Button>
            )}
          </div>
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex items-center gap-1 rounded-md border p-1">
          <Button size="sm" variant={!isOutstanding ? 'default' : 'ghost'} onClick={() => navigate('/sales/orders')}>
            Semua
          </Button>
          <Button size="sm" variant={isOutstanding ? 'default' : 'ghost'} onClick={() => navigate('/sales/orders/outstanding')}>
            Outstanding
          </Button>
        </div>
      </div>

      <AdvancedFilterToolbar
        value={draft}
        onChange={(patch) => setDraft((prev) => ({ ...prev, ...patch }))}
        onApply={applyFilters}
        onReset={resetFilters}
        hasActiveFilters={hasFilters}
        chips={chips}
        statusOptions={STATUS_OPTIONS}
        customerOptions={customerOptions}
        customerLoading={customersQuery.isLoading}
        salesPersonOptions={salesPersonOptions}
      />

      {selection.hasSelection && (
        <div className="flex flex-wrap items-center gap-3 rounded-md border bg-muted/40 p-2.5">
          <span className="text-sm font-medium">
            {selection.selectAllFiltered ? `Semua ${selection.selectedCount} hasil filter dipilih` : `${selection.selectedCount} dipilih`}
          </span>
          {!selection.selectAllFiltered && rows.length > 0 && rows.every((row) => selection.selectedIds.has(row.id)) && totalFiltered > rows.length && (
            <Button type="button" variant="link" size="sm" className="h-auto p-0" onClick={() => selection.setSelectAllFiltered(true)}>
              Pilih semua {totalFiltered} hasil filter
            </Button>
          )}
          <Button type="button" variant="ghost" size="sm" onClick={selection.clear}>
            Batalkan
          </Button>
          <span className="ml-auto text-xs text-muted-foreground">Export/Print di atas akan menggunakan seleksi ini.</span>
        </div>
      )}

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={
          isOutstanding
            ? 'No outstanding sales orders — every approved order has been fully delivered.'
            : hasFilters
              ? 'No sales orders match your search or filters.'
              : 'No sales orders yet.'
        }
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

      <ExportColumnPickerDialog
        open={exportPickerOpen}
        onOpenChange={setExportPickerOpen}
        columns={EXPORT_COLUMNS}
        targetDescription={
          hasExplicitSelection
            ? `${selection.selectedCount} dokumen terpilih akan diekspor.`
            : `Semua ${totalFiltered} hasil filter saat ini akan diekspor.`
        }
        onConfirm={(selectedColumns) => {
          if (pendingExportFormat) runExport(pendingExportFormat, selectedColumns)
        }}
      />
    </div>
  )
}
