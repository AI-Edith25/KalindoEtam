import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, Download, Percent, Printer, RotateCw, TrendingUp, Wallet } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn, type DataTableSort } from '@/components/shared/DataTable'
import { Pagination } from '@/components/shared/Pagination'
import { Button } from '@/components/ui/button'
import { TableCell, TableRow } from '@/components/ui/table'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { cn, formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { exportMargin, fetchMargin, type MarginParams } from '../api/marginApi'
import { SalesReportFiltersBar } from './SalesReportFiltersBar'
import { reportFileName } from '../lib/exportFileName'
import type { MarginGroupBy, MarginRow, SalesReportFilterValues } from '../types'

interface MarginPanelProps {
  filters: SalesReportFilterValues
  onFiltersChange: (filters: SalesReportFilterValues) => void
  page: number
  onPageChange: (page: number) => void
}

const GROUPS: { value: MarginGroupBy; label: string }[] = [
  { value: 'item', label: 'Per Item' },
  { value: 'customer', label: 'Per Customer' },
  { value: 'invoice', label: 'Per Invoice' },
]

function formatMargin(value: number): string {
  return `${value.toFixed(2)}%`
}

function HppMissingIcon() {
  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger>
          <AlertTriangle className="size-3.5 text-destructive" />
        </TooltipTrigger>
        <TooltipContent>HPP belum tercatat</TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

/**
 * Margin tab — Profit = Penjualan (excl. tax) - HPP. Rules (HPP source, tax exclusion, margin
 * formula) are backend-fixed, not user-selectable, so this panel never offers a HPP-method or
 * include/exclude-tax control — see the ticket. hpp_missing rows are still shown in the table
 * (with a warning icon) but are already excluded from meta.kpis.avg_margin_pct server-side.
 */
export function MarginPanel({ filters, onFiltersChange, page, onPageChange }: MarginPanelProps) {
  const navigate = useNavigate()
  const [group, setGroup] = useState<MarginGroupBy>('item')
  const [sort, setSort] = useState<DataTableSort>({ key: 'profit', direction: 'desc' })
  const [isExporting, setIsExporting] = useState(false)

  const activeParams: Omit<MarginParams, 'page'> = {
    group,
    sort: sort.key as MarginParams['sort'],
    sort_dir: sort.direction,
    ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
    ...(filters.item_id ? { item_id: filters.item_id } : {}),
    ...(filters.sales_person_id ? { sales_person_id: filters.sales_person_id } : {}),
    ...(filters.branch_id ? { branch_id: filters.branch_id } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['margin', page, group, sort.key, sort.direction, filters],
    queryFn: () => fetchMargin({ ...activeParams, page }),
    placeholderData: (previous) => previous,
  })

  const rows = listQuery.data?.data ?? []
  const kpis = listQuery.data?.meta.kpis

  const setGroupAndReset = (next: MarginGroupBy) => {
    setGroup(next)
    setSort({ key: 'profit', direction: 'desc' })
    onPageChange(1)
  }

  const exportReport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportMargin(activeParams, format)
      downloadBlob(reportFileName('MarginReport', filters.dateFrom, filters.dateTo, format), blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const profitClass = (value: number) => cn('text-right', value < 0 && 'text-destructive')

  const itemColumns: DataTableColumn<MarginRow>[] = [
    { header: 'Kode Item', accessor: (row) => row.item_code ?? '—' },
    {
      header: 'Nama Item',
      accessor: (row) => (
        <span className="inline-flex items-center gap-1.5">
          {row.hpp_missing && <HppMissingIcon />}
          {row.item_name}
        </span>
      ),
      sortKey: 'item_name',
    },
    { header: 'Qty', accessor: (row) => formatNumber(row.qty ?? 0), className: 'text-right', sortKey: 'qty' },
    { header: 'Penjualan', accessor: (row) => formatCurrency(row.amount), className: 'text-right', sortKey: 'amount' },
    { header: 'HPP', accessor: (row) => formatCurrency(row.cost_amount), className: 'text-right', sortKey: 'cost_amount' },
    { header: 'Profit', accessor: (row) => <span className={cn(row.profit < 0 && 'text-destructive')}>{formatCurrency(row.profit)}</span>, className: 'text-right', sortKey: 'profit' },
    { header: 'Margin %', accessor: (row) => <span className={cn(row.margin_pct < 0 && 'text-destructive')}>{formatMargin(row.margin_pct)}</span>, className: 'text-right', sortKey: 'margin_pct' },
  ]

  const customerColumns: DataTableColumn<MarginRow>[] = [
    { header: 'Kode Cust', accessor: (row) => row.customer_code ?? '—' },
    {
      header: 'Nama Customer',
      accessor: (row) => (
        <span className="inline-flex items-center gap-1.5">
          {row.hpp_missing && <HppMissingIcon />}
          {row.customer_name}
        </span>
      ),
      sortKey: 'customer_name',
    },
    { header: 'Jml Invoice', accessor: (row) => formatNumber(row.invoice_count ?? 0), className: 'text-right' },
    { header: 'Penjualan', accessor: (row) => formatCurrency(row.amount), className: 'text-right', sortKey: 'amount' },
    { header: 'HPP', accessor: (row) => formatCurrency(row.cost_amount), className: 'text-right', sortKey: 'cost_amount' },
    { header: 'Profit', accessor: (row) => <span className={cn(row.profit < 0 && 'text-destructive')}>{formatCurrency(row.profit)}</span>, className: 'text-right', sortKey: 'profit' },
    { header: 'Margin %', accessor: (row) => <span className={cn(row.margin_pct < 0 && 'text-destructive')}>{formatMargin(row.margin_pct)}</span>, className: 'text-right', sortKey: 'margin_pct' },
  ]

  const invoiceColumns: DataTableColumn<MarginRow>[] = [
    { header: 'Tanggal', accessor: (row) => formatDate(row.date), sortKey: 'date' },
    {
      header: 'No Invoice',
      accessor: (row) => (
        <span className="inline-flex items-center gap-1.5">
          {row.hpp_missing && <HppMissingIcon />}
          {row.document_number ?? '—'}
        </span>
      ),
      sortKey: 'document_number',
    },
    { header: 'Customer', accessor: (row) => row.customer_name },
    { header: 'Sales Person', accessor: (row) => row.sales_person_name ?? 'Unassigned' },
    { header: 'Penjualan', accessor: (row) => formatCurrency(row.amount), className: 'text-right', sortKey: 'amount' },
    { header: 'HPP', accessor: (row) => formatCurrency(row.cost_amount), className: 'text-right', sortKey: 'cost_amount' },
    { header: 'Profit', accessor: (row) => <span className={cn(row.profit < 0 && 'text-destructive')}>{formatCurrency(row.profit)}</span>, className: 'text-right', sortKey: 'profit' },
    { header: 'Margin %', accessor: (row) => <span className={cn(row.margin_pct < 0 && 'text-destructive')}>{formatMargin(row.margin_pct)}</span>, className: 'text-right', sortKey: 'margin_pct' },
  ]

  const columns = group === 'customer' ? customerColumns : group === 'invoice' ? invoiceColumns : itemColumns

  const totalMarginPct = kpis && kpis.total_sales !== 0 ? (kpis.total_profit / kpis.total_sales) * 100 : 0

  const footerRow = kpis && (
    <TableRow>
      {group === 'item' && (
        <>
          <TableCell colSpan={2}>Grand Total</TableCell>
          <TableCell className="text-right">{formatNumber(rows.reduce((sum, r) => sum + (r.qty ?? 0), 0))}</TableCell>
        </>
      )}
      {group === 'customer' && (
        <>
          <TableCell colSpan={2}>Grand Total</TableCell>
          <TableCell className="text-right">{formatNumber(rows.reduce((sum, r) => sum + (r.invoice_count ?? 0), 0))}</TableCell>
        </>
      )}
      {group === 'invoice' && <TableCell colSpan={4}>Grand Total</TableCell>}
      <TableCell className="text-right">{formatCurrency(kpis.total_sales)}</TableCell>
      <TableCell className="text-right">{formatCurrency(kpis.total_cost)}</TableCell>
      <TableCell className={profitClass(kpis.total_profit)}>{formatCurrency(kpis.total_profit)}</TableCell>
      <TableCell className={profitClass(totalMarginPct)}>{formatMargin(totalMarginPct)}</TableCell>
    </TableRow>
  )

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-3">
        <SummaryCard title="Total Penjualan" value={formatCurrency(kpis?.total_sales ?? 0)} icon={Wallet} isLoading={listQuery.isLoading} />
        <SummaryCard
          title="Total Profit"
          value={formatCurrency(kpis?.total_profit ?? 0)}
          icon={TrendingUp}
          tone={kpis && kpis.total_profit < 0 ? 'danger' : 'default'}
          isLoading={listQuery.isLoading}
        />
        <SummaryCard
          title="Margin Rata-rata"
          value={formatMargin(kpis?.avg_margin_pct ?? 0)}
          icon={Percent}
          tone={kpis && kpis.avg_margin_pct < 0 ? 'danger' : 'default'}
          isLoading={listQuery.isLoading}
        />
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-1 rounded-md border p-1">
          {GROUPS.map((option) => (
            <Button key={option.value} size="sm" variant={group === option.value ? 'default' : 'ghost'} onClick={() => setGroupAndReset(option.value)}>
              {option.label}
            </Button>
          ))}
        </div>
        <ActionBar
          actions={[
            { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
            {
              label: 'Print',
              icon: Printer,
              onClick: () => {
                const params = new URLSearchParams({
                  tab: 'margin',
                  group,
                  ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
                  ...(filters.item_id ? { item_id: filters.item_id } : {}),
                  ...(filters.sales_person_id ? { sales_person_id: filters.sales_person_id } : {}),
                  ...(filters.branch_id ? { branch_id: filters.branch_id } : {}),
                  ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
                  ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
                })
                navigate(`/reports/sales/print?${params.toString()}`)
              },
            },
            { label: 'Export XLSX', icon: Download, onClick: () => exportReport('xlsx'), disabled: isExporting },
            { label: 'Export CSV', icon: Download, onClick: () => exportReport('csv'), disabled: isExporting },
          ]}
        />
      </div>

      <SalesReportFiltersBar value={filters} onChange={(next) => { onFiltersChange(next); onPageChange(1) }} hide={['itemGroup', 'status']} />

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage="No sales in this period yet."
        sort={sort}
        onSortChange={(key) => setSort((prev) => (prev.key === key ? { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: 'desc' }))}
        footerRow={footerRow}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={onPageChange} />}
    </div>
  )
}
