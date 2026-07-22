import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, Eye, ExternalLink, Pencil, Plus, RotateCw, Send, Trash2, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { RowActionsMenu, type RowAction } from '@/components/shared/RowActionsMenu'
import { Pagination } from '@/components/shared/Pagination'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SectionNav } from '@/components/shared/SectionNav'
import { Button } from '@/components/ui/button'
import { toastApiError } from '@/shared/services/errorHandler'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { deletePaymentEntry, fetchPaymentEntries, submitPaymentEntry } from '../api/paymentEntryApi'
import { fetchPurchaseOrders } from '@/features/purchase/api/purchaseOrderApi'
import { PaymentEntryFiltersBar } from '../components/PaymentEntryFiltersBar'
import { emptyPaymentEntryFilters } from '../lib/paymentEntryFilters'
import { PAYMENT_METHOD_LABELS } from '../lib/paymentMethodLabels'
import { resolveSourceDocumentLink } from '../lib/sourceDocumentLink'
import { financeSectionNav } from '../navigation'
import type { PaymentEntry, PaymentEntryFilterValues } from '../types'

/** Outgoing Payment — settles Accounts Payable created by Goods Receipt. Never touches stock. */
export function OutgoingPaymentListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('payment_entry.create')
  const canUpdate = useHasPermission('payment_entry.update')
  const canDelete = useHasPermission('payment_entry.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<PaymentEntryFilterValues>(emptyPaymentEntryFilters)
  const [deletingPayment, setDeletingPayment] = useState<PaymentEntry | null>(null)

  const listQuery = useQuery({
    queryKey: ['payment-entries', page, search, filters.status, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchPaymentEntries({
        page,
        ...(search ? { search } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  // PaymentEntryItem.accounts_payable exposes purchase_order_id only, not a nested object — same lookup-join pattern as GoodsReceiptListPage.
  const purchaseOrdersLookup = useQuery({
    queryKey: ['purchase-orders-lookup'],
    queryFn: () => fetchPurchaseOrders({ page: 1, per_page: 100 }),
  })
  const purchaseOrderNumber = (purchaseOrderId: string) =>
    purchaseOrdersLookup.data?.data.find((po) => po.id === purchaseOrderId)?.document_number ?? '—'

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['payment-entries'] })

  const submitMutation = useMutation({
    mutationFn: submitPaymentEntry,
    onSuccess: () => {
      invalidate()
      toast.success('Payment confirmed — payable updated.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: deletePaymentEntry,
    onSuccess: () => {
      invalidate()
      toast.success('Payment deleted.')
      setDeletingPayment(null)
    },
    onError: (error) => toastApiError(error),
  })

  const rows = useMemo(() => listQuery.data?.data ?? [], [listQuery.data])

  const actionsFor = (payment: PaymentEntry): RowAction[] => {
    const actions: RowAction[] = [{ label: 'View', icon: Eye, onClick: () => navigate(`/finance/outgoing/${payment.id}`) }]

    if (payment.status === 'draft') {
      if (canUpdate) {
        actions.push(
          { label: 'Edit', icon: Pencil, onClick: () => navigate(`/finance/outgoing/${payment.id}/edit`) },
          { label: 'Confirm Payment', icon: Send, onClick: () => submitMutation.mutate(payment.id) },
        )
      }
      if (canDelete) {
        actions.push({ label: 'Delete', icon: Trash2, variant: 'destructive', onClick: () => setDeletingPayment(payment) })
      }
    }
    // submitted is terminal — Payment Entry has no cancel action (see paymentEntryApi.ts).

    return actions
  }

  const columns: DataTableColumn<PaymentEntry>[] = [
    { header: 'Payment No', accessor: (row) => row.document_number ?? '—' },
    {
      header: 'Source Document',
      accessor: (row) => {
        const line = row.items[0]
        if (!line) return '—'

        return (
          <Button
            variant="link"
            className="h-auto p-0"
            onClick={(event) => {
              event.stopPropagation()
              navigate(resolveSourceDocumentLink('purchase_order', line.accounts_payable.purchase_order_id))
            }}
          >
            {purchaseOrderNumber(line.accounts_payable.purchase_order_id)}
            <ExternalLink className="size-3.5" />
          </Button>
        )
      },
    },
    { header: 'Supplier', accessor: (row) => row.supplier?.supplier_name ?? '—' },
    { header: 'Payment Method', accessor: (row) => PAYMENT_METHOD_LABELS[row.payment_method] },
    { header: 'Payment Date', accessor: (row) => formatDate(row.payment_date) },
    { header: 'Amount', accessor: (row) => formatCurrency(row.total_amount), className: 'text-right' },
    {
      header: 'Status',
      accessor: (row) =>
        row.status === 'submitted' ? (
          <StatusBadge status={row.items[0]?.accounts_payable.status ?? row.status} />
        ) : (
          <StatusBadge status={row.status} />
        ),
    },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => <RowActionsMenu actions={actionsFor(row)} />,
    },
  ]

  const hasFilters = !!(search || filters.status || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={financeSectionNav} />

      <PageHeader
        title="Outgoing Payment"
        description="Payments the company makes to suppliers, settling outstanding Accounts Payable."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} payments` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Payment', icon: Plus, onClick: () => navigate('/finance/outgoing/new') } : undefined}
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
          placeholder="Search payment number or supplier…"
        />
        <PaymentEntryFiltersBar
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
        onRowClick={(row) => navigate(`/finance/outgoing/${row.id}`)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}

      <DeleteDialog
        open={!!deletingPayment}
        onOpenChange={(open) => !open && setDeletingPayment(null)}
        itemLabel={deletingPayment?.document_number ?? undefined}
        onConfirm={() => {
          if (deletingPayment) deleteMutation.mutate(deletingPayment.id)
        }}
      />
    </div>
  )
}
