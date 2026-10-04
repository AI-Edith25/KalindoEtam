import { useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, ExternalLink, Printer, RotateCw, Upload } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { Pagination } from '@/components/shared/Pagination'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { Button } from '@/components/ui/button'
import { TableCell, TableRow } from '@/components/ui/table'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { exportStockLedger, fetchStockLedgerEntries } from '../api/stockLedgerApi'
import { StockLedgerFiltersBar } from './StockLedgerFiltersBar'
import { resolveVoucherLink } from '../lib/voucherLinks'
import { emptyStockLedgerFilters } from '../lib/stockLedgerFilters'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { openPrintWindow } from '@/shared/lib/printOptions'
import { toastApiError } from '@/shared/services/errorHandler'
import type { StockLedgerEntry, StockLedgerFilterValues } from '../types'

/**
 * "Ledger" tab of Reports > Inventory Stock — moved as-is from the old
 * /inventory/stock-ledger page, plus a new Voucher Type column (the source
 * document type — Opening Stock/Goods Receipt/Delivery/etc. — distinct from
 * the existing generic In/Out "Movement Type" badge), the one column that
 * used to only exist on the now-deleted Inventory Movement report.
 */
export function StockLedgerPanel() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [isExporting, setIsExporting] = useState(false)
  const [filters, setFilters] = useState<StockLedgerFilterValues>(() => ({
    ...emptyStockLedgerFilters,
    item_id: searchParams.get('item_id') ? [searchParams.get('item_id')!] : [],
    warehouse_id: searchParams.get('warehouse_id') ?? '',
  }))

  const activeParams = {
    ...(search ? { search } : {}),
    ...(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
    ...(filters.item_id.length > 0 ? { item_id: filters.item_id } : {}),
    ...(filters.item_group_id ? { item_group_id: filters.item_group_id } : {}),
    ...(filters.voucher_type ? { voucher_type: filters.voucher_type } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
  }

  const listQuery = useQuery({
    queryKey: [
      'stock-ledger-entries',
      page,
      search,
      filters.warehouse_id,
      filters.item_id,
      filters.item_group_id,
      filters.voucher_type,
      filters.dateFrom,
      filters.dateTo,
    ],
    queryFn: () => fetchStockLedgerEntries({ page, ...activeParams }),
    placeholderData: (previous) => previous,
  })

  const rows = useMemo(() => listQuery.data?.data ?? [], [listQuery.data])
  const totals = listQuery.data?.meta.summary

  // TOTAL row — Qty In/Out and Line Amount are summed across every row matching the active
  // filters (not just this page); Running Balance is cumulative, so its total is the
  // chronologically last row's own balance, not a sum (see ledgerTotals() on the backend).
  const footerRow = totals && (
    <TableRow>
      <TableCell colSpan={7}>TOTAL</TableCell>
      <TableCell className="text-right">{formatNumber(totals.qty_in)}</TableCell>
      <TableCell className="text-right">{formatNumber(totals.qty_out)}</TableCell>
      <TableCell className="text-right">{formatNumber(totals.closing_balance_qty)}</TableCell>
      <TableCell />
      <TableCell className="text-right">{formatCurrency(totals.line_amount)}</TableCell>
      <TableCell />
    </TableRow>
  )

  const printReport = () => {
    const params = new URLSearchParams()
    Object.entries(activeParams).forEach(([key, value]) =>
      Array.isArray(value) ? value.forEach((v) => params.append(`${key}[]`, v)) : params.set(key, String(value)),
    )
    openPrintWindow(`/reports/inventory-stock/print-ledger?${params.toString()}`)
  }

  const exportReport = async () => {
    setIsExporting(true)
    try {
      const { blob, filename } = await exportStockLedger(activeParams)
      downloadBlob(filename, blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const columns: DataTableColumn<StockLedgerEntry>[] = [
    { header: 'Date', accessor: (row) => formatDate(row.posting_datetime) },
    { header: 'Item', accessor: (row) => (row.item ? `${row.item.item_code} — ${row.item.item_name}` : '—') },
    { header: 'Location', accessor: (row) => row.warehouse?.name ?? '—' },
    { header: 'Customer', accessor: (row) => row.customer_name ?? '—' },
    { header: 'Voucher Type', accessor: (row) => <StatusBadge status={row.voucher_type} /> },
    {
      header: 'Reference Document',
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
    { header: 'Movement Type', accessor: (row) => <StatusBadge status={row.transaction_type} /> },
    {
      header: 'Qty In',
      accessor: (row) => (Number(row.qty_change) > 0 ? formatNumber(row.qty_change) : '—'),
      className: 'text-right',
    },
    {
      header: 'Qty Out',
      accessor: (row) => (Number(row.qty_change) < 0 ? formatNumber(Math.abs(Number(row.qty_change))) : '—'),
      className: 'text-right',
    },
    { header: 'Running Balance', accessor: (row) => formatNumber(row.balance_qty), className: 'text-right font-medium' },
    { header: 'Unit Cost', accessor: (row) => (row.unit_cost ? formatCurrency(row.unit_cost) : '—'), className: 'text-right text-muted-foreground' },
    {
      header: 'Line Amount',
      accessor: (row) => {
        const amount = row.value_out ? -row.value_out : row.value_in
        return amount ? formatCurrency(amount) : '—'
      },
      className: 'text-right',
    },
    { header: 'Balance Value', accessor: (row) => (row.balance_value !== null ? formatCurrency(row.balance_value) : '—'), className: 'text-right font-medium' },
  ]

  const hasFilters = !!(search || filters.warehouse_id || filters.item_id.length > 0 || filters.item_group_id || filters.voucher_type || filters.dateFrom || filters.dateTo)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">Every inventory movement, across every item and warehouse.</p>
        <ActionBar
          actions={[
            { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
            { label: 'Print', icon: Printer, onClick: printReport },
            { label: 'Export', icon: Download, onClick: exportReport, disabled: isExporting },
            { label: 'Import', icon: Upload, disabled: true },
          ]}
        />
      </div>

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
        footerRow={footerRow}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
    </div>
  )
}
