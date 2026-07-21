import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Boxes, Download, Package, RotateCw, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { SectionNav } from '@/components/shared/SectionNav'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { formatNumber } from '@/lib/utils'
import { fetchStockBalanceReport } from '@/features/inventory/api/stockBalanceApi'
import { StockBalanceFiltersBar } from '@/features/inventory/components/StockBalanceFiltersBar'
import { emptyStockBalanceFilters } from '@/features/inventory/lib/stockBalanceFilters'
import type { StockBalanceFilterValues, StockBalanceRow } from '@/features/inventory/types'
import { reportsSectionNav } from '../navigation'

/**
 * Read-only report over the Stock Balance — reuses fetchStockBalanceReport()
 * and StockBalanceFiltersBar exactly as built for Inventory's own Stock
 * Balance list (same filters: Item, Warehouse — Item Group carried along too).
 */
export function InventoryBalanceReportPage() {
  const navigate = useNavigate()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<StockBalanceFilterValues>(emptyStockBalanceFilters)

  const listQuery = useQuery({
    queryKey: ['inventory-balance-report', page, search, filters.warehouse_id, filters.item_group_id, filters.item_id],
    queryFn: () =>
      fetchStockBalanceReport({
        page,
        ...(search ? { search } : {}),
        ...(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
        ...(filters.item_group_id ? { item_group_id: filters.item_group_id } : {}),
        ...(filters.item_id ? { item_id: filters.item_id } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const rows = useMemo(() => listQuery.data?.data ?? [], [listQuery.data])

  // ponytail: summed from the currently-loaded page only, not a backend aggregate — same ceiling as Purchase/Sales Report's total cards.
  const totalStockQty = useMemo(() => rows.reduce((sum, row) => sum + row.current_qty, 0), [rows])

  const columns: DataTableColumn<StockBalanceRow>[] = [
    { header: 'Item', accessor: (row) => `${row.item_code} — ${row.item_name}` },
    { header: 'Warehouse', accessor: (row) => row.warehouse_name },
    { header: 'Current Qty', accessor: (row) => formatNumber(row.current_qty), className: 'text-right' },
    { header: 'UOM', accessor: (row) => row.uom },
  ]

  const hasFilters = !!(search || filters.warehouse_id || filters.item_group_id || filters.item_id)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={reportsSectionNav} />

      <PageHeader
        title="Inventory Balance Report"
        description="Current on-hand quantity per item and warehouse."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} rows` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
          />
        }
      />

      <div className="grid gap-4 sm:grid-cols-2">
        <SummaryCard
          title="Total Items"
          value={formatNumber(listQuery.data?.meta.total ?? 0)}
          icon={Package}
          isLoading={listQuery.isLoading}
        />
        <SummaryCard
          title="Total Stock Quantity"
          value={formatNumber(totalStockQty)}
          description="Sum of the currently loaded page"
          icon={Boxes}
          isLoading={listQuery.isLoading}
        />
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
        <StockBalanceFiltersBar
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
        emptyMessage={hasFilters ? 'No stock matches your search or filters.' : 'No stock on hand yet.'}
        onRowClick={(row) => navigate(`/inventory/stock-ledger?item_id=${row.item_id}&warehouse_id=${row.warehouse_id}`)}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
    </div>
  )
}
