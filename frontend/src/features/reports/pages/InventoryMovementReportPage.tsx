import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, ExternalLink, RotateCw, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SectionNav } from '@/components/shared/SectionNav'
import { Button } from '@/components/ui/button'
import { formatDate, formatNumber } from '@/lib/utils'
import { fetchStockLedgerEntries } from '@/features/inventory/api/stockLedgerApi'
import { StockLedgerFiltersBar } from '@/features/inventory/components/StockLedgerFiltersBar'
import { resolveVoucherLink } from '@/features/inventory/lib/voucherLinks'
import { emptyStockLedgerFilters } from '@/features/inventory/lib/stockLedgerFilters'
import type { StockLedgerEntry, StockLedgerFilterValues } from '@/features/inventory/types'

/**
 * Read-only report over the Stock Ledger — reuses fetchStockLedgerEntries()
 * and StockLedgerFiltersBar exactly as built for Inventory's own Stock
 * Ledger list (same filters: Date Range, Item, Warehouse, Voucher Type).
 */
export function InventoryMovementReportPage() {
  const navigate = useNavigate()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filters, setFilters] = useState<StockLedgerFilterValues>(emptyStockLedgerFilters)

  const listQuery = useQuery({
    queryKey: [
      'inventory-movement-report',
      page,
      search,
      filters.warehouse_id,
      filters.item_id,
      filters.voucher_type,
      filters.dateFrom,
      filters.dateTo,
    ],
    queryFn: () =>
      fetchStockLedgerEntries({
        page,
        ...(search ? { search } : {}),
        ...(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
        ...(filters.item_id ? { item_id: filters.item_id } : {}),
        ...(filters.voucher_type ? { voucher_type: filters.voucher_type } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const rows = useMemo(() => listQuery.data?.data ?? [], [listQuery.data])

  const columns: DataTableColumn<StockLedgerEntry>[] = [
    { header: 'Date', accessor: (row) => formatDate(row.posting_datetime) },
    { header: 'Item', accessor: (row) => (row.item ? `${row.item.item_code} — ${row.item.item_name}` : '—') },
    { header: 'Warehouse', accessor: (row) => row.warehouse?.name ?? '—' },
    { header: 'Voucher Type', accessor: (row) => <StatusBadge status={row.voucher_type} /> },
    {
      header: 'Voucher No',
      accessor: (row) => {
        const link = resolveVoucherLink(row.voucher_type, row.voucher_id)
        if (!link) return row.reference_no ?? '—'

        return (
          <Button
            variant="link"
            className="h-auto p-0"
            onClick={(event) => {
              event.stopPropagation()
              navigate(link)
            }}
          >
            {row.reference_no ?? '—'}
            <ExternalLink className="size-3.5" />
          </Button>
        )
      },
    },
    {
      header: 'Qty In',
      accessor: (row) => (row.qty_change > 0 ? formatNumber(row.qty_change) : '—'),
      className: 'text-right',
    },
    {
      header: 'Qty Out',
      accessor: (row) => (row.qty_change < 0 ? formatNumber(Math.abs(row.qty_change)) : '—'),
      className: 'text-right',
    },
    { header: 'Running Balance', accessor: (row) => formatNumber(row.balance_qty), className: 'text-right font-medium' },
  ]

  const hasFilters = !!(
    search ||
    filters.warehouse_id ||
    filters.item_id ||
    filters.voucher_type ||
    filters.dateFrom ||
    filters.dateTo
  )

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="Inventory Movement Report"
        description="Every stock movement, across every item and warehouse."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} entries` : undefined}
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
          placeholder="Search reference number, item code or name…"
        />
        <StockLedgerFiltersBar
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
        emptyMessage={hasFilters ? 'No stock movements match your search or filters.' : 'No stock movements yet.'}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
    </div>
  )
}
