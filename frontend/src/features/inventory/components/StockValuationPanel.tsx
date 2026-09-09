import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Coins, Download, Package, RotateCw } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { exportStockValuationReport, fetchStockValuationReport } from '../api/stockValuationApi'
import { StockValuationFiltersBar } from './StockValuationFiltersBar'
import { emptyStockValuationFilters } from '../lib/stockValuationFilters'
import type { StockValuationFilterValues, StockValuationRow } from '../types'

/**
 * "Valuation" tab of Reports > Inventory Stock — Opening/In/Out/Closing per item and
 * warehouse for a date range, replayed from the same FifoLayer audit trail Ledger's cost
 * columns come from (see InventoryValuationService's docblock for why this isn't just
 * Ledger's balance_value aggregated).
 */
export function StockValuationPanel() {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<StockValuationFilterValues>(emptyStockValuationFilters)
  const [isExporting, setIsExporting] = useState(false)

  const queryParams = {
    date_from: filters.dateFrom,
    date_to: filters.dateTo,
    ...(search ? { search } : {}),
    ...(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
    ...(filters.item_group_id ? { item_group_id: filters.item_group_id } : {}),
    ...(filters.item_id ? { item_id: filters.item_id } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['stock-valuation-report', page, search, filters],
    queryFn: () => fetchStockValuationReport({ page, ...queryParams }),
    placeholderData: (previous) => previous,
  })

  const rows = listQuery.data?.data ?? []
  const summary = listQuery.data?.meta.summary

  const handleExport = async () => {
    setIsExporting(true)
    try {
      const blob = await exportStockValuationReport(queryParams, 'xlsx')
      downloadBlob('InventoryValuation.xlsx', blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const columns: DataTableColumn<StockValuationRow>[] = [
    { header: 'Item', accessor: (row) => `${row.item_code} — ${row.item_name}` },
    { header: 'Warehouse', accessor: (row) => row.warehouse_name },
    { header: 'Opening Qty', accessor: (row) => formatNumber(row.opening_qty), className: 'text-right' },
    { header: 'Opening Value', accessor: (row) => formatCurrency(row.opening_value), className: 'text-right text-muted-foreground' },
    { header: 'Qty In', accessor: (row) => formatNumber(row.qty_in), className: 'text-right' },
    { header: 'Value In', accessor: (row) => formatCurrency(row.value_in), className: 'text-right text-muted-foreground' },
    { header: 'Qty Out', accessor: (row) => formatNumber(row.qty_out), className: 'text-right' },
    { header: 'Value Out', accessor: (row) => formatCurrency(row.value_out), className: 'text-right text-muted-foreground' },
    { header: 'Closing Qty', accessor: (row) => formatNumber(row.closing_qty), className: 'text-right font-medium' },
    { header: 'Unit Cost', accessor: (row) => formatCurrency(row.unit_cost), className: 'text-right text-muted-foreground' },
    { header: 'Closing Value', accessor: (row) => formatCurrency(row.closing_value), className: 'text-right font-medium' },
  ]

  const hasFilters = !!(search || filters.warehouse_id || filters.item_group_id || filters.item_id)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">Stock value movement per item and warehouse, for the selected period.</p>
        <ActionBar
          actions={[
            { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
            { label: 'Export', icon: Download, onClick: handleExport, disabled: isExporting },
          ]}
        />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <SummaryCard title="Total Inventory Value" value={formatCurrency(summary?.closing_value ?? 0)} icon={Coins} isLoading={listQuery.isLoading} />
        <SummaryCard title="Items" value={formatNumber(summary?.item_count ?? 0)} icon={Package} isLoading={listQuery.isLoading} />
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox
          value={search}
          onChange={(value) => {
            setSearch(value)
            setPage(1)
          }}
          placeholder="Search item code or name…"
        />
        <StockValuationFiltersBar
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
        rowKey={(row) => `${row.item_id}-${row.warehouse_id}`}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={hasFilters ? 'No stock movement matches your search or filters.' : 'No stock movement in this period.'}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
    </div>
  )
}
