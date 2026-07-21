import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, RotateCw, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { SectionNav } from '@/components/shared/SectionNav'
import { formatNumber } from '@/lib/utils'
import { fetchStockBalanceReport } from '../api/stockBalanceApi'
import { StockBalanceFiltersBar } from '../components/StockBalanceFiltersBar'
import { emptyStockBalanceFilters } from '../lib/stockBalanceFilters'
import { inventorySectionNav } from '../navigation'
import type { StockBalanceFilterValues, StockBalanceRow } from '../types'

/** Landing page for Inventory — "what do we have, where." Row click drills into Stock Ledger, pre-filtered to that item+warehouse. */
export function StockBalanceListPage() {
  const navigate = useNavigate()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<StockBalanceFilterValues>(emptyStockBalanceFilters)

  const listQuery = useQuery({
    queryKey: ['stock-balances-report', page, search, filters.warehouse_id, filters.item_group_id, filters.item_id],
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

  const rows = listQuery.data?.data ?? []

  const columns: DataTableColumn<StockBalanceRow>[] = [
    { header: 'Item', accessor: (row) => `${row.item_code} — ${row.item_name}` },
    { header: 'Warehouse', accessor: (row) => row.warehouse_name },
    { header: 'Current Qty', accessor: (row) => formatNumber(row.current_qty), className: 'text-right' },
    { header: 'Reserved Qty', accessor: (row) => (row.reserved_qty === null ? '—' : formatNumber(row.reserved_qty)), className: 'text-right text-muted-foreground' },
    { header: 'Available Qty', accessor: (row) => formatNumber(row.available_qty), className: 'text-right' },
    { header: 'Reorder Level', accessor: (row) => (row.reorder_level === null ? '—' : formatNumber(row.reorder_level)), className: 'text-right text-muted-foreground' },
  ]

  const hasFilters = !!(search || filters.warehouse_id || filters.item_group_id || filters.item_id)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={inventorySectionNav} />

      <PageHeader
        title="Stock Balance"
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
