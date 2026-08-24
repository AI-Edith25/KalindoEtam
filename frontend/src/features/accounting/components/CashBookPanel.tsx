import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, RotateCw } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { Button } from '@/components/ui/button'
import { formatCurrency, formatDate } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { fetchCashBook } from '../api/cashBookApi'
import { exportJournalList } from '../api/journalListApi'
import { CashBookFiltersBar } from './CashBookFiltersBar'
import type { CashBookFilterValues, CashBookRow, CashBookView } from '../types'

const VIEWS: { value: CashBookView; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'receipt', label: 'Official Receipt' },
  { value: 'payment', label: 'Payment Voucher' },
]

interface CashBookPanelProps {
  view: CashBookView
  onViewChange: (view: CashBookView) => void
  search: string
  onSearchChange: (search: string) => void
  filters: CashBookFilterValues
  onFiltersChange: (filters: CashBookFilterValues) => void
  page: number
  onPageChange: (page: number) => void
}

/**
 * Cash Book Transaction — one page, one table, a view toggle (not separate
 * tabs) that changes only the table's contents; filters/pagination/export
 * stay in place. All URL-synced state (view/search/filters/page) is owned by
 * JournalListPage — this component is a pure display + fetch layer.
 */
export function CashBookPanel({ view, onViewChange, search, onSearchChange, filters, onFiltersChange, page, onPageChange }: CashBookPanelProps) {
  const navigate = useNavigate()
  const [isExporting, setIsExporting] = useState(false)

  const activeParams = {
    view,
    ...(search ? { search } : {}),
    ...(filters.status ? { status: filters.status } : {}),
    ...(filters.branchId ? { branch_id: filters.branchId } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['cash-book', page, view, search, filters.status, filters.branchId, filters.dateFrom, filters.dateTo],
    queryFn: () => fetchCashBook({ ...activeParams, page }),
    placeholderData: (previous) => previous,
  })

  const exportReport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportJournalList({ ...activeParams, format })
      downloadBlob(`journal-list.${format}`, blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const rows = listQuery.data?.data ?? []

  const columns: DataTableColumn<CashBookRow>[] = [
    { header: 'Document', accessor: (row) => row.document_number ?? '—' },
    { header: 'Type', accessor: (row) => <StatusBadge status={row.type} /> },
    { header: 'Party', accessor: (row) => row.party_name ?? '—' },
    { header: 'Payment Method', accessor: (row) => row.payment_method_name ?? '—' },
    { header: 'Date', accessor: (row) => formatDate(row.date) },
    { header: 'Debit', accessor: (row) => (row.debit > 0 ? formatCurrency(row.debit) : '—'), className: 'text-right' },
    { header: 'Credit', accessor: (row) => (row.credit > 0 ? formatCurrency(row.credit) : '—'), className: 'text-right' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.status} /> },
  ]

  // Extra columns follow /finance/incoming (Unallocated) and /finance/outgoing (Reference) — only
  // meaningful once the view is narrowed to one document type.
  if (view === 'receipt') {
    columns.push({
      header: 'Unallocated',
      accessor: (row) => (row.status === 'submitted' ? formatCurrency(row.unallocated) : '—'),
      className: 'text-right',
    })
  } else if (view === 'payment') {
    columns.push({ header: 'Reference', accessor: (row) => row.reference_number ?? '—' })
  }

  const hasFilters = !!(search || filters.status || filters.branchId || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-1 rounded-md border p-1">
          {VIEWS.map((option) => (
            <Button
              key={option.value}
              size="sm"
              variant={view === option.value ? 'default' : 'ghost'}
              onClick={() => onViewChange(option.value)}
            >
              {option.label}
            </Button>
          ))}
        </div>
        <ActionBar
          actions={[
            { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
            { label: 'Export XLSX', icon: Download, onClick: () => exportReport('xlsx'), disabled: isExporting },
            { label: 'Export CSV', icon: Download, onClick: () => exportReport('csv'), disabled: isExporting },
          ]}
        />
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox
          value={search}
          onChange={(value) => {
            onSearchChange(value)
            onPageChange(1)
          }}
          placeholder="Search document number or party…"
        />
        <CashBookFiltersBar
          value={filters}
          onChange={(value) => {
            onFiltersChange(value)
            onPageChange(1)
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
        emptyMessage={hasFilters ? 'No transactions match your search or filters.' : 'No cash/bank transactions yet.'}
        onRowClick={(row) => navigate(row.type === 'receipt' ? `/finance/incoming/${row.id}` : `/finance/outgoing/${row.id}`)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={onPageChange} />}
    </div>
  )
}
