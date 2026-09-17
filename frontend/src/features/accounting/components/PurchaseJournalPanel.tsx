import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Download, RotateCw } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { formatCurrency, formatDate } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { exportPurchaseJournal, fetchPurchaseJournal, purchaseJournalFileName } from '../api/purchaseJournalApi'
import { PurchaseJournalFiltersBar } from './PurchaseJournalFiltersBar'
import type { PurchaseJournalFilterValues, PurchaseJournalRow, PurchaseJournalView } from '../types'

interface PurchaseJournalPanelProps {
  view: PurchaseJournalView
  search: string
  onSearchChange: (search: string) => void
  filters: PurchaseJournalFilterValues
  onFiltersChange: (filters: PurchaseJournalFilterValues) => void
  page: number
  onPageChange: (page: number) => void
  /** The Journal Type select — which of Purchase Journal's 2 views this is comes from there now, not an in-panel toggle. */
  journalTypeSelect: ReactNode
}

/** Purchase Journal — Purchase Invoice/Purchase Return, same structural pattern as SalesJournalPanel/CashBookPanel. */
export function PurchaseJournalPanel({ view, search, onSearchChange, filters, onFiltersChange, page, onPageChange, journalTypeSelect }: PurchaseJournalPanelProps) {
  const [isExporting, setIsExporting] = useState(false)

  const activeParams = {
    view,
    ...(search ? { search } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['purchase-journal', page, view, search, filters.dateFrom, filters.dateTo],
    queryFn: () => fetchPurchaseJournal({ ...activeParams, page }),
    placeholderData: (previous) => previous,
  })

  const exportReport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportPurchaseJournal({ ...activeParams, format })
      downloadBlob(purchaseJournalFileName(view, format), blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const rows = listQuery.data?.data ?? []

  const columns: DataTableColumn<PurchaseJournalRow>[] = [
    { header: 'Date', accessor: (row) => formatDate(row.date) },
    { header: 'Document', accessor: (row) => row.document_number ?? '—' },
    { header: 'Reference', accessor: (row) => row.reference_number ?? '—' },
    { header: 'Supplier', accessor: (row) => row.supplier_name },
    { header: 'Amount Excl. Tax', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
    { header: 'Tax', accessor: (row) => formatCurrency(row.tax), className: 'text-right' },
    { header: 'Amount Incl. Tax', accessor: (row) => formatCurrency(row.amount_incl_tax), className: 'text-right' },
  ]

  const hasFilters = !!(search || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex justify-end">
        <ActionBar
          actions={[
            { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
            { label: 'Export XLSX', icon: Download, onClick: () => exportReport('xlsx'), disabled: isExporting },
            { label: 'Export CSV', icon: Download, onClick: () => exportReport('csv'), disabled: isExporting },
          ]}
        />
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox value={search} onChange={onSearchChange} placeholder="Search document number or supplier…" />
        <PurchaseJournalFiltersBar value={filters} onChange={onFiltersChange} leading={journalTypeSelect} />
      </div>

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={hasFilters ? 'No transactions match your search or filters.' : view === 'purchase_return' ? 'No purchase returns yet.' : 'No purchase invoices yet.'}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={onPageChange} />}
    </div>
  )
}
