import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, Eye, ExternalLink, Pencil, Plus, RotateCw, Send, Trash2, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn, type DataTableSort } from '@/components/shared/DataTable'
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
import { PaymentEntryFiltersBar } from '../components/PaymentEntryFiltersBar'
import { emptyPaymentEntryFilters } from '../lib/paymentEntryFilters'
import { resolveSourceDocumentLink } from '../lib/sourceDocumentLink'
import type { PaymentEntry, PaymentEntryFilterValues } from '../types'

const SORTERS: Record<string, (payment: PaymentEntry) => number> = {
  unallocated_amount: (payment) =>
    payment.payment_type === 'supplier' && payment.status === 'submitted' ? Number(payment.unallocated_amount) : 0,
}

/** Payment Voucher — either settles Accounts Payable created by Goods Receipt, or posts a General Expense (no Supplier/PO) directly to an Expense account. Never touches stock. */
export function OutgoingPaymentListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canCreate = useHasPermission('finance.outgoing_payment.create')
  const canUpdate = useHasPermission('finance.outgoing_payment.update')
  const canDelete = useHasPermission('finance.outgoing_payment.delete')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<PaymentEntryFilterValues>(emptyPaymentEntryFilters)
  const [deletingPayment, setDeletingPayment] = useState<PaymentEntry | null>(null)
  const [sort, setSort] = useState<DataTableSort | undefined>(undefined)

  const listQuery = useQuery({
    queryKey: ['payment-entries', page, search, filters.status, filters.dateFrom, filters.dateTo, filters.unallocatedOnly],
    queryFn: () =>
      fetchPaymentEntries({
        page,
        ...(search ? { search } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
        ...(filters.unallocatedOnly ? { unallocated_only: true } : {}),
      }),
    placeholderData: (previous) => previous,
  })

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

  const rows = useMemo(() => {
    const data = listQuery.data?.data ?? []
    if (!sort) return data

    const getter = SORTERS[sort.key]
    if (!getter) return data

    return [...data].sort((a, b) => {
      const cmp = getter(a) - getter(b)
      return sort.direction === 'asc' ? cmp : -cmp
    })
  }, [listQuery.data, sort])

  const handleSortChange = (key: string) => {
    setSort((prev) => (prev?.key === key ? { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: 'asc' }))
  }

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
    { header: 'Document', accessor: (row) => row.document_number ?? '—' },
    { header: 'Type', accessor: (row) => <StatusBadge status={row.payment_type} /> },
    {
      header: 'Reference',
      accessor: (row) => {
        if (row.payment_type === 'general_expense') {
          return <span className="text-muted-foreground">General Expense</span>
        }

        // Allocation is now a separate step from paying — a payment can exist with no
        // allocation lines yet (advance payment), exactly one (the common immediate case,
        // still clickable through to the PI), or several (one payment, many bills).
        if (row.items.length === 0) {
          return <span className="text-muted-foreground">Unallocated</span>
        }

        if (row.items.length === 1) {
          const line = row.items[0]

          return (
            <Button
              variant="link"
              className="h-auto p-0"
              onClick={(event) => {
                event.stopPropagation()
                // Pre-Purchase-Invoice payables (created straight from a Goods Receipt,
                // before 65bc42a) have no invoice_id — fall back to their Goods Receipt.
                navigate(
                  line.accounts_payable.invoice_id
                    ? resolveSourceDocumentLink('purchase_invoice', line.accounts_payable.invoice_id)
                    : resolveSourceDocumentLink('goods_receipt', line.accounts_payable.goods_receipt_id),
                )
              }}
            >
              {line.accounts_payable.reference_number}
              <ExternalLink className="size-3.5" />
            </Button>
          )
        }

        return row.items.map((line) => line.accounts_payable.reference_number).join(', ')
      },
    },
    {
      header: 'Supplier',
      accessor: (row) => (row.payment_type === 'general_expense' ? (row.expense_account?.name ?? '—') : (row.supplier?.supplier_name ?? '—')),
    },
    { header: 'Payment Method', accessor: (row) => row.cash_account?.name ?? '—' },
    { header: 'Date', accessor: (row) => formatDate(row.payment_date) },
    { header: 'Amount', accessor: (row) => formatCurrency(row.total_amount), className: 'text-right' },
    {
      header: 'Unallocated',
      sortKey: 'unallocated_amount',
      accessor: (row) =>
        row.payment_type === 'supplier' && row.status === 'submitted' ? (
          <span className={Number(row.unallocated_amount) > 0 ? 'text-amber-600' : undefined}>
            {formatCurrency(row.unallocated_amount)}
          </span>
        ) : (
          '—'
        ),
      className: 'text-right',
    },
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

  const hasFilters = !!(search || filters.status || filters.dateFrom || filters.dateTo || filters.unallocatedOnly)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="finance" />

      <PageHeader
        title="Payment Voucher"
        description="Payments to suppliers (settling Accounts Payable) and general office expenses, in one list."
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
        sort={sort}
        onSortChange={handleSortChange}
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
