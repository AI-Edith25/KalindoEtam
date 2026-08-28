import { useMemo, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, Eye, Pencil, Plus, Printer, RotateCw, Send, Trash2 } from 'lucide-react'
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
import { fetchCustomersLookup, fetchSalesPersonsLookup, fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import { completeDelivery, deleteDelivery, exportDeliveries, fetchDeliveries } from '../api/deliveryApi'
import type { Delivery } from '../types'

const SORTERS: Record<string, (delivery: Delivery) => string | number> = {
  document_number: (delivery) => delivery.document_number ?? '',
  delivery_date: (delivery) => delivery.delivery_date,
}

const STATUS_OPTIONS = [
  { value: 'pending', label: 'Pending' },
  { value: 'complete', label: 'Complete' },
]

const EXPORT_COLUMNS: ExportColumn[] = [
  { key: 'delivery_date', label: 'Date' },
  { key: 'document_number', label: 'Document' },
  { key: 'reference', label: 'Reference' },
  { key: 'customer_name', label: 'Customer Name' },
  { key: 'amount', label: 'Amount' },
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

export function DeliveryListPage() {
  const navigate = useNavigate()
  const isOutstanding = useLocation().pathname.endsWith('/outstanding')
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('sales.deliveries.create')
  const canUpdate = useHasPermission('sales.deliveries.update')
  const canDelete = useHasPermission('sales.deliveries.delete')

  const [page, setPage] = useState(1)
  const [sort, setSort] = useState<DataTableSort | undefined>(undefined)
  const [deletingDelivery, setDeletingDelivery] = useState<Delivery | null>(null)
  const [exportPickerOpen, setExportPickerOpen] = useState(false)
  const [pendingExportFormat, setPendingExportFormat] = useState<'xlsx' | 'csv' | null>(null)
  const [isExporting, setIsExporting] = useState(false)

  const [urlFilters, setUrlFilters, resetUrlFilters] = useUrlFilters<AdvancedFilterValue>(EMPTY_FILTERS)
  const [draft, setDraft] = useState<AdvancedFilterValue>(urlFilters)

  const customersQuery = useQuery({ queryKey: ['customers-lookup'], queryFn: fetchCustomersLookup })
  const salesPersonsQuery = useQuery({ queryKey: ['sales-persons-lookup'], queryFn: fetchSalesPersonsLookup })
  const warehousesQuery = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  const customerOptions = (customersQuery.data ?? []).map((customer) => ({ value: customer.id, label: customer.customer_name }))
  const salesPersonOptions = (salesPersonsQuery.data ?? []).map((salesPerson) => ({ value: salesPerson.id, label: salesPerson.name }))
  const warehouseOptions = (warehousesQuery.data ?? []).map((warehouse) => ({ value: warehouse.id, label: warehouse.name }))

  const queryFilters = {
    ...(urlFilters.search ? { search: urlFilters.search } : {}),
    ...(urlFilters.status.length > 0 ? { status: urlFilters.status } : {}),
    ...(urlFilters.date_from ? { date_from: urlFilters.date_from } : {}),
    ...(urlFilters.date_to ? { date_to: urlFilters.date_to } : {}),
    ...(urlFilters.customer_id ? { customer_id: urlFilters.customer_id } : {}),
    ...(urlFilters.sales_person_id ? { sales_person_id: urlFilters.sales_person_id } : {}),
    ...(urlFilters.warehouse_id ? { warehouse_id: urlFilters.warehouse_id } : {}),
    ...(urlFilters.sales_order_number ? { sales_order_number: urlFilters.sales_order_number } : {}),
    ...(isOutstanding ? { outstanding: true } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['deliveries', page, queryFilters, isOutstanding],
    queryFn: () => fetchDeliveries({ page, ...queryFilters }),
    placeholderData: (previous) => previous,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['deliveries'] })

  const completeMutation = useMutation({
    mutationFn: completeDelivery,
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

  const totalFiltered = listQuery.data?.meta?.total ?? 0
  const selection = useRowSelection<Delivery>(rows, totalFiltered, { resetKey: [page, JSON.stringify(queryFilters)].join('|') })

  const handleSortChange = (key: string) => {
    setSort((prev) => (prev?.key === key ? { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: 'asc' }))
  }

  const actionsFor = (delivery: Delivery): RowAction[] => {
    const actions: RowAction[] = [
      { label: 'View', icon: Eye, onClick: () => navigate(`/sales/deliveries/${delivery.id}`) },
      { label: 'Print', icon: Printer, onClick: () => navigate(`/sales/deliveries/${delivery.id}/print`) },
    ]

    if (delivery.status === 'pending') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/sales/deliveries/${delivery.id}/edit`) },
          { label: 'Confirm Delivery', icon: Send, onClick: () => completeMutation.mutate(delivery.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingDelivery(delivery) })
      }
    }
    // complete is terminal — Delivery has no cancel action (see deliveryApi.ts).

    return actions
  }

  const columns: DataTableColumn<Delivery>[] = [
    selection.selectionColumn,
    { header: 'Date', accessor: (row) => formatDate(row.delivery_date), sortKey: 'delivery_date' },
    { header: 'Document', accessor: (row) => row.document_number ?? '—', sortKey: 'document_number' },
    { header: 'Reference', accessor: (row) => row.sales_order?.document_number ?? '—' },
    { header: 'Customer Name', accessor: (row) => row.customer?.customer_name ?? '—' },
    { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
    ...(!isOutstanding
      ? [
          {
            header: 'Status',
            accessor: (row: Delivery) =>
              row.is_invoiced === null ? '—' : <StatusBadge status={row.is_invoiced ? 'invoiced' : 'not_invoiced'} />,
          } satisfies DataTableColumn<Delivery>,
        ]
      : []),
    {
      header: '',
      className: 'text-right',
      accessor: (row) => <RowActionsMenu actions={actionsFor(row)} />,
    },
  ]

  const hasFilters = !!(
    urlFilters.search ||
    urlFilters.status.length > 0 ||
    urlFilters.date_from ||
    urlFilters.date_to ||
    urlFilters.customer_id ||
    urlFilters.sales_person_id ||
    urlFilters.warehouse_id ||
    urlFilters.sales_order_number
  )

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
    urlFilters.warehouse_id && {
      key: 'warehouse',
      label: `Gudang: ${warehouseOptions.find((option) => option.value === urlFilters.warehouse_id)?.label ?? '—'}`,
      onRemove: () => removeFilter({ warehouse_id: '' }),
    },
    urlFilters.sales_order_number && {
      key: 'sales_order_number',
      label: `No. SO: ${urlFilters.sales_order_number}`,
      onRemove: () => removeFilter({ sales_order_number: '' }),
    },
  ].filter((chip): chip is { key: string; label: string; onRemove: () => void } => !!chip)

  const runExport = async (format: 'xlsx' | 'csv', columns?: string[]) => {
    setIsExporting(true)
    try {
      const blob = await exportDeliveries({
        format,
        columns,
        ids: selection.selectedIdsForRequest ?? undefined,
        ...queryFilters,
      })
      const today = new Date().toISOString().slice(0, 10)
      downloadBlob(`deliveries_${urlFilters.date_from || today}_${urlFilters.date_to || today}.${format}`, blob)
      toast.success('Export started — check your downloads.')
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const runSummaryExport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportDeliveries({
        format,
        mode: 'summary',
        ids: selection.selectedIdsForRequest ?? undefined,
        ...queryFilters,
      })
      downloadBlob(`DeliveryOrderListing_Summary.${format}`, blob)
      toast.success('Export started — check your downloads.')
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const hasExplicitSelection = !!selection.selectedIdsForRequest && selection.selectedIdsForRequest.length > 0

  const printListSummary = () => {
    if (hasExplicitSelection) {
      navigate(`/sales/deliveries/print-list?ids=${selection.selectedIdsForRequest!.join(',')}`)
      return
    }
    const params = new URLSearchParams()
    Object.entries(queryFilters).forEach(([key, value]) => {
      if (Array.isArray(value)) value.forEach((v) => params.append(key, v))
      else params.set(key, String(value))
    })
    navigate(`/sales/deliveries/print-list?${params.toString()}`)
  }

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="sales" />

      <PageHeader
        title="Deliveries"
        description="Deliver ordered goods from a warehouse against an approved Sales Order."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} deliveries` : undefined}
        actions={
          <>
            {selection.hasSelection && (
              <Button type="button" variant="outline" onClick={printListSummary}>
                <Printer className="size-4" />
                Print
              </Button>
            )}

            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline" disabled={isExporting}>
                  <Download className="size-4" />
                  Export CSV
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem
                  onSelect={() => {
                    setPendingExportFormat('csv')
                    setExportPickerOpen(true)
                  }}
                >
                  Detail
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => runSummaryExport('csv')}>Summary</DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline" disabled={isExporting}>
                  <Download className="size-4" />
                  Export XLSX
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem
                  onSelect={() => {
                    setPendingExportFormat('xlsx')
                    setExportPickerOpen(true)
                  }}
                >
                  Detail
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => runSummaryExport('xlsx')}>Summary</DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>

            <ActionBar
              actions={[{ label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching }]}
              primary={canCreate ? { label: 'New Delivery', icon: Plus, onClick: () => navigate('/sales/deliveries/new') } : undefined}
            />
          </>
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex items-center gap-1 rounded-md border p-1">
          <Button size="sm" variant={!isOutstanding ? 'default' : 'ghost'} onClick={() => navigate('/sales/deliveries')}>
            Semua
          </Button>
          <Button size="sm" variant={isOutstanding ? 'default' : 'ghost'} onClick={() => navigate('/sales/deliveries/outstanding')}>
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
        warehouseOptions={warehouseOptions}
        showSalesOrderReference
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
            ? 'No outstanding deliveries — every completed delivery has been invoiced.'
            : hasFilters
              ? 'No deliveries match your search or filters.'
              : 'No deliveries yet.'
        }
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
