import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, Eye, Pencil, Plus, RotateCw, Send, Trash2, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { RowActionsMenu, type RowAction } from '@/components/shared/RowActionsMenu'
import { Pagination } from '@/components/shared/Pagination'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SectionNav } from '@/components/shared/SectionNav'
import { toastApiError } from '@/shared/services/errorHandler'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { deleteReceiptEntry, fetchReceiptEntries, submitReceiptEntry } from '../api/receiptEntryApi'
import { ReceiptEntryFiltersBar } from '../components/ReceiptEntryFiltersBar'
import { emptyReceiptEntryFilters } from '../lib/receiptEntryFilters'
import { PAYMENT_METHOD_LABELS } from '../lib/paymentMethodLabels'
import type { ReceiptEntry, ReceiptEntryFilterValues } from '../types'

/** Incoming Payment — settles Accounts Receivable created by Delivery. Never touches stock. */
export function IncomingPaymentListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('finance.incoming_payment.create')
  const canUpdate = useHasPermission('finance.incoming_payment.update')
  const canDelete = useHasPermission('finance.incoming_payment.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<ReceiptEntryFilterValues>(emptyReceiptEntryFilters)
  const [deletingReceipt, setDeletingReceipt] = useState<ReceiptEntry | null>(null)

  const listQuery = useQuery({
    queryKey: ['receipt-entries', page, search, filters.status, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchReceiptEntries({
        page,
        ...(search ? { search } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['receipt-entries'] })

  const submitMutation = useMutation({
    mutationFn: submitReceiptEntry,
    onSuccess: () => {
      invalidate()
      toast.success('Payment confirmed — receivable updated.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteReceiptEntry,
    onSuccess: () => {
      invalidate()
      toast.success('Payment deleted.')
      setDeletingReceipt(null)
    },
    onError: (error) => toastApiError(error),
  })

  const rows = useMemo(() => listQuery.data?.data ?? [], [listQuery.data])

  const actionsFor = (receipt: ReceiptEntry): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/finance/incoming/${receipt.id}`) }]

    if (receipt.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/finance/incoming/${receipt.id}/edit`) },
          { label: 'Confirm Payment', icon: Send, onClick: () => submitMutation.mutate(receipt.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingReceipt(receipt) })
      }
    }
    // submitted is terminal — Receipt Entry has no cancel action (see receiptEntryApi.ts).

    return actions
  }

  const columns: DataTableColumn<ReceiptEntry>[] = [
    { header: 'Payment No', accessor: (row) => row.document_number ?? '—' },
    { header: 'Customer', accessor: (row) => row.customer?.customer_name ?? '—' },
    { header: 'Payment Method', accessor: (row) => PAYMENT_METHOD_LABELS[row.payment_method] },
    { header: 'Payment Date', accessor: (row) => formatDate(row.receipt_date) },
    { header: 'Amount', accessor: (row) => formatCurrency(row.total_amount), className: 'text-right' },
    {
      header: 'Unallocated',
      accessor: (row) =>
        row.status === 'submitted' ? (
          <span className={Number(row.unallocated_amount) > 0 ? 'text-amber-600' : undefined}>
            {formatCurrency(row.unallocated_amount)}
          </span>
        ) : (
          '—'
        ),
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
      <SectionNav group="finance" />

      <PageHeader
        title="Incoming Payment"
        description="Payments received from customers, settling outstanding Accounts Receivable."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} payments` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Payment', icon: Plus, onClick: () => navigate('/finance/incoming/new') } : undefined}
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
          placeholder="Search payment number or customer…"
        />
        <ReceiptEntryFiltersBar
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
        emptyMessage={hasFilters ? 'No payments match your search or filters.' : 'No payments yet.'}
        onRowClick={(row) => navigate(`/finance/incoming/${row.id}`)}
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
