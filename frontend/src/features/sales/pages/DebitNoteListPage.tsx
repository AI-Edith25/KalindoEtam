import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Download, Eye, Pencil, Plus, Printer, RotateCcw, RotateCw, Send, Trash2 } from 'lucide-react'
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
import { deleteDebitNote, exportDebitNotes, fetchDebitNotes, reverseDebitNote, submitDebitNote } from '../api/debitNoteApi'
import { DEBIT_NOTE_REASON_LABELS, DEBIT_NOTE_REASON_OPTIONS } from '../lib/debitNoteReasonLabels'
import type { DebitNote } from '../types'

const SORTERS: Record<string, (debitNote: DebitNote) => string | number> = {
  document_number: (debitNote) => debitNote.document_number ?? '',
  debit_note_date: (debitNote) => debitNote.debit_note_date,
  total_amount: (debitNote) => Number(debitNote.total_amount),
}

// Cancelled is a valid DocumentStatus value, but DebitNoteService has no cancel path — draft/submitted
// are the only states a Debit Note actually reaches (see DebitNoteService::submit()/reverse()).
const STATUS_OPTIONS = [
  { value: 'draft', label: 'Draft' },
  { value: 'submitted', label: 'Submitted' },
]

const REASON_OPTIONS = DEBIT_NOTE_REASON_OPTIONS.map(([value, label]) => ({ value, label }))

const EXPORT_COLUMNS: ExportColumn[] = [
  { key: 'debit_note_date', label: 'Date' },
  { key: 'document_number', label: 'Debit Note No' },
  { key: 'invoice', label: 'Invoice' },
  { key: 'customer_name', label: 'Customer' },
  { key: 'reason', label: 'Reason' },
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

export function DebitNoteListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('sales.debit_notes.create')
  const canUpdate = useHasPermission('sales.debit_notes.update')
  const canDelete = useHasPermission('sales.debit_notes.delete')

  const [page, setPage] = useState(1)
  const [sort, setSort] = useState<DataTableSort | undefined>(undefined)
  const [deletingDebitNote, setDeletingDebitNote] = useState<DebitNote | null>(null)
  const [exportPickerOpen, setExportPickerOpen] = useState(false)
  const [pendingExportFormat, setPendingExportFormat] = useState<'xlsx' | 'csv' | null>(null)
  const [isExporting, setIsExporting] = useState(false)

  const [urlFilters, setUrlFilters, resetUrlFilters] = useUrlFilters<AdvancedFilterValue>(EMPTY_FILTERS)
  const [draft, setDraft] = useState<AdvancedFilterValue>(urlFilters)

  const customersQuery = useQuery({ queryKey: ['customers-lookup'], queryFn: fetchCustomersLookup })
  const salesPersonsQuery = useQuery({ queryKey: ['sales-persons-lookup'], queryFn: fetchSalesPersonsLookup })
  const customerOptions = (customersQuery.data ?? []).map((customer) => ({ value: customer.id, label: customer.customer_name }))
  const salesPersonOptions = (salesPersonsQuery.data ?? []).map((salesPerson) => ({ value: salesPerson.id, label: salesPerson.name }))

  const queryFilters = {
    ...(urlFilters.search ? { search: urlFilters.search } : {}),
    ...(urlFilters.status.length > 0 ? { status: urlFilters.status } : {}),
    ...(urlFilters.reason ? { reason: urlFilters.reason } : {}),
    ...(urlFilters.date_from ? { date_from: urlFilters.date_from } : {}),
    ...(urlFilters.date_to ? { date_to: urlFilters.date_to } : {}),
    ...(urlFilters.customer_id ? { customer_id: urlFilters.customer_id } : {}),
    ...(urlFilters.sales_person_id ? { sales_person_id: urlFilters.sales_person_id } : {}),
    ...(urlFilters.min_amount ? { min_amount: urlFilters.min_amount } : {}),
    ...(urlFilters.max_amount ? { max_amount: urlFilters.max_amount } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['debit-notes', page, queryFilters],
    queryFn: () => fetchDebitNotes({ page, ...queryFilters }),
    placeholderData: (previous) => previous,
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['debit-notes'] })
    queryClient.invalidateQueries({ queryKey: ['invoices'] })
  }

  const submitMutation = useMutation({
    mutationFn: submitDebitNote,
    onSuccess: () => {
      invalidate()
      toast.success('Debit Note submitted — Accounts Receivable updated.')
    },
    onError: (error) => toastApiError(error),
  })

  const reverseMutation = useMutation({
    mutationFn: reverseDebitNote,
    onSuccess: () => {
      invalidate()
      toast.success('Debit Note reversed.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteDebitNote,
    onSuccess: () => {
      invalidate()
      toast.success('Debit Note deleted.')
      setDeletingDebitNote(null)
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
  const selection = useRowSelection<DebitNote>(rows, totalFiltered, { resetKey: [page, JSON.stringify(queryFilters)].join('|') })

  const handleSortChange = (key: string) => {
    setSort((prev) => (prev?.key === key ? { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: 'asc' }))
  }

  const actionsFor = (debitNote: DebitNote): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/sales/debit-notes/${debitNote.id}`) }]

    if (debitNote.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/sales/debit-notes/${debitNote.id}/edit`) },
          { label: 'Submit', icon: Send, onClick: () => submitMutation.mutate(debitNote.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingDebitNote(debitNote) })
      }
    } else if (debitNote.status === 'submitted' && !debitNote.is_reversed && canUpdate) {
      actions.push({ label: 'Reverse', icon: RotateCcw, variant: 'destructive', onClick: () => reverseMutation.mutate(debitNote.id) })
    }

    return actions
  }

  const columns: DataTableColumn<DebitNote>[] = [
    selection.selectionColumn,
    { header: 'Debit Note No', accessor: (row) => row.document_number ?? '—', sortKey: 'document_number' },
    { header: 'Invoice', accessor: (row) => row.invoice?.document_number ?? '—' },
    { header: 'Customer', accessor: (row) => row.customer?.customer_name ?? '—' },
    { header: 'Reason', accessor: (row) => DEBIT_NOTE_REASON_LABELS[row.reason] },
    { header: 'Date', accessor: (row) => formatDate(row.debit_note_date), sortKey: 'debit_note_date' },
    { header: 'Amount', accessor: (row) => formatCurrency(row.total_amount), className: 'text-right', sortKey: 'total_amount' },
    {
      header: 'Status',
      accessor: (row) => (
        <div className="flex items-center gap-2">
          <StatusBadge status={row.status} />
          {row.is_reversed && <Badge variant="secondary">Reversed</Badge>}
        </div>
      ),
    },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => <RowActionsMenu actions={actionsFor(row)} />,
    },
  ]

  const hasFilters = !!(
    urlFilters.search ||
    urlFilters.status.length > 0 ||
    urlFilters.reason ||
    urlFilters.date_from ||
    urlFilters.date_to ||
    urlFilters.customer_id ||
    urlFilters.sales_person_id ||
    urlFilters.min_amount ||
    urlFilters.max_amount
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
    urlFilters.reason && {
      key: 'reason',
      label: `Alasan: ${REASON_OPTIONS.find((option) => option.value === urlFilters.reason)?.label ?? urlFilters.reason}`,
      onRemove: () => removeFilter({ reason: '' }),
    },
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
    urlFilters.min_amount && { key: 'min_amount', label: `Min: ${urlFilters.min_amount}`, onRemove: () => removeFilter({ min_amount: '' }) },
    urlFilters.max_amount && { key: 'max_amount', label: `Maks: ${urlFilters.max_amount}`, onRemove: () => removeFilter({ max_amount: '' }) },
  ].filter((chip): chip is { key: string; label: string; onRemove: () => void } => !!chip)

  const runExport = async (format: 'xlsx' | 'csv', columns?: string[]) => {
    setIsExporting(true)
    try {
      const blob = await exportDebitNotes({
        format,
        columns,
        ids: selection.selectedIdsForRequest ?? undefined,
        ...queryFilters,
      })
      const today = new Date().toISOString().slice(0, 10)
      downloadBlob(`debit_notes_${urlFilters.date_from || today}_${urlFilters.date_to || today}.${format}`, blob)
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
      const blob = await exportDebitNotes({
        format,
        mode: 'summary',
        ids: selection.selectedIdsForRequest ?? undefined,
        ...queryFilters,
      })
      downloadBlob(`DebitNoteToCustomerListing_Summary.${format}`, blob)
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
      navigate(`/sales/debit-notes/print-list?ids=${selection.selectedIdsForRequest!.join(',')}`)
      return
    }
    const params = new URLSearchParams()
    Object.entries(queryFilters).forEach(([key, value]) => {
      if (Array.isArray(value)) value.forEach((v) => params.append(key, v))
      else params.set(key, String(value))
    })
    navigate(`/sales/debit-notes/print-list?${params.toString()}`)
  }

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="sales" />

      <PageHeader
        title="Debit Notes"
        description="Increases a customer's receivable after a posted Invoice — under-billed quantities, price corrections, additional charges, freight, or tax."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} debit notes` : undefined}
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
              primary={canCreate ? { label: 'New Debit Note', icon: Plus, onClick: () => navigate('/sales/debit-notes/new') } : undefined}
            />
          </>
        }
      />

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
        reasonOptions={REASON_OPTIONS}
        showAmountRange
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
        emptyMessage={hasFilters ? 'No debit notes match your search or filters.' : 'No debit notes yet.'}
        onRowClick={(row) => navigate(`/sales/debit-notes/${row.id}`)}
        sort={sort}
        onSortChange={handleSortChange}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <DeleteDialog
        open={!!deletingDebitNote}
        onOpenChange={(open) => !open && setDeletingDebitNote(null)}
        itemLabel={deletingDebitNote?.document_number ?? undefined}
        onConfirm={() => {
          if (deletingDebitNote) deleteMutation.mutate(deletingDebitNote.id)
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
