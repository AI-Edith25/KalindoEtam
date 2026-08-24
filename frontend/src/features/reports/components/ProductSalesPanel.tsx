import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, Package, Printer, RotateCw, TrendingUp, Trophy, Users } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn, type DataTableSort } from '@/components/shared/DataTable'
import { Pagination } from '@/components/shared/Pagination'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { exportProductSales, fetchProductSales, fetchProductSalesCustomers, type ProductSalesParams } from '../api/productSalesApi'
import { SalesReportFiltersBar } from './SalesReportFiltersBar'
import { reportFileName } from '../lib/exportFileName'
import type { ProductSalesRow, SalesReportFilterValues, SalesReportGroupBy } from '../types'

interface ProductSalesPanelProps {
  filters: SalesReportFilterValues
  onFiltersChange: (filters: SalesReportFilterValues) => void
  page: number
  onPageChange: (page: number) => void
}

export function ProductSalesPanel({ filters, onFiltersChange, page, onPageChange }: ProductSalesPanelProps) {
  const navigate = useNavigate()
  const [group, setGroup] = useState<SalesReportGroupBy>('item')
  const [sort, setSort] = useState<DataTableSort>({ key: 'amount', direction: 'desc' })
  const [isExporting, setIsExporting] = useState(false)
  const [customersFor, setCustomersFor] = useState<ProductSalesRow | null>(null)

  const activeParams: Omit<ProductSalesParams, 'page'> = {
    group,
    sort: sort.key as ProductSalesParams['sort'],
    sort_dir: sort.direction,
    ...(filters.status ? { status: filters.status } : {}),
    ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
    ...(filters.item_id ? { item_id: filters.item_id } : {}),
    ...(filters.item_group_id ? { item_group_id: filters.item_group_id } : {}),
    ...(filters.sales_person_id ? { sales_person_id: filters.sales_person_id } : {}),
    ...(filters.branch_id ? { branch_id: filters.branch_id } : {}),
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
  }

  const listQuery = useQuery({
    queryKey: ['product-sales', page, group, sort.key, sort.direction, filters],
    queryFn: () => fetchProductSales({ ...activeParams, page }),
    placeholderData: (previous) => previous,
  })

  const customersQuery = useQuery({
    queryKey: ['product-sales-customers', customersFor?.id, filters],
    queryFn: () => fetchProductSalesCustomers(customersFor!.id, activeParams),
    enabled: !!customersFor && !customersFor.is_group,
  })

  const rows = listQuery.data?.data ?? []
  const kpis = listQuery.data?.meta.kpis
  const totalRevenue = kpis?.total_revenue ?? 0

  const exportReport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportProductSales(activeParams, format)
      downloadBlob(reportFileName('ProductSalesReport', filters.dateFrom, filters.dateTo, format), blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const columns: DataTableColumn<ProductSalesRow>[] = group === 'item_group'
    ? [
        { header: 'Item Group', accessor: (row) => row.item_name, sortKey: 'item_name' },
        { header: 'SKU Count', accessor: (row) => formatNumber(row.sku_count ?? 0), className: 'text-right' },
        { header: 'Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right', sortKey: 'qty' },
        { header: 'Amount Excl. Tax', accessor: (row) => formatCurrency(row.amount), className: 'text-right', sortKey: 'amount' },
        { header: 'Tax', accessor: (row) => formatCurrency(row.tax_amount), className: 'text-right' },
        { header: 'Amount Incl. Tax', accessor: (row) => formatCurrency(row.amount_incl_tax), className: 'text-right' },
        {
          header: '% of Revenue',
          accessor: (row) => (totalRevenue > 0 ? `${((row.amount / totalRevenue) * 100).toFixed(1)}%` : '—'),
          className: 'text-right',
        },
      ]
    : [
        { header: 'Item Code', accessor: (row) => row.item_code ?? '—' },
        { header: 'Description', accessor: (row) => row.item_name, sortKey: 'item_name' },
        { header: 'Item Group', accessor: (row) => row.item_group_name ?? 'Unassigned' },
        { header: 'UOM', accessor: (row) => row.uom_name ?? '—' },
        { header: 'Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right', sortKey: 'qty' },
        { header: 'Amount Excl. Tax', accessor: (row) => formatCurrency(row.amount), className: 'text-right', sortKey: 'amount' },
        { header: 'Tax', accessor: (row) => formatCurrency(row.tax_amount), className: 'text-right' },
        { header: 'Amount Incl. Tax', accessor: (row) => formatCurrency(row.amount_incl_tax), className: 'text-right' },
        {
          header: '% of Revenue',
          accessor: (row) => (totalRevenue > 0 ? `${((row.amount / totalRevenue) * 100).toFixed(1)}%` : '—'),
          className: 'text-right',
        },
        {
          header: '',
          id: 'actions',
          accessor: (row) => (
            <Button
              variant="ghost"
              size="sm"
              onClick={(event) => {
                event.stopPropagation()
                setCustomersFor(row)
              }}
            >
              <Users className="size-3.5" />
              Customers
            </Button>
          ),
        },
      ]

  const hasFilters = !!(
    filters.customer_id ||
    filters.item_id ||
    filters.item_group_id ||
    filters.sales_person_id ||
    filters.branch_id ||
    filters.status
  )

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <SummaryCard title="Total Qty" value={formatNumber(kpis?.total_qty ?? 0)} icon={Package} isLoading={listQuery.isLoading} />
        <SummaryCard title="Total Revenue" value={formatCurrency(kpis?.total_revenue ?? 0)} icon={TrendingUp} isLoading={listQuery.isLoading} />
        <SummaryCard title="SKUs Sold" value={formatNumber(kpis?.sku_count ?? 0)} icon={Users} isLoading={listQuery.isLoading} />
        <SummaryCard
          title="Top Item"
          value={kpis?.top_item_name ?? '—'}
          description={kpis ? formatCurrency(kpis.top_item_amount) : undefined}
          icon={Trophy}
          isLoading={listQuery.isLoading}
        />
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-1 rounded-md border p-1">
          <Button size="sm" variant={group === 'item' ? 'default' : 'ghost'} onClick={() => { setGroup('item'); onPageChange(1) }}>
            Detail per Item
          </Button>
          <Button size="sm" variant={group === 'item_group' ? 'default' : 'ghost'} onClick={() => { setGroup('item_group'); onPageChange(1) }}>
            Ringkas per Group
          </Button>
        </div>
        <ActionBar
          actions={[
            { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
            {
              label: 'Print',
              icon: Printer,
              onClick: () => {
                const params = new URLSearchParams({
                  tab: 'products',
                  ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
                  ...(filters.item_id ? { item_id: filters.item_id } : {}),
                  ...(filters.item_group_id ? { item_group_id: filters.item_group_id } : {}),
                  ...(filters.sales_person_id ? { sales_person_id: filters.sales_person_id } : {}),
                  ...(filters.branch_id ? { branch_id: filters.branch_id } : {}),
                  ...(filters.status ? { status: filters.status } : {}),
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

      <SalesReportFiltersBar value={filters} onChange={(next) => { onFiltersChange(next); onPageChange(1) }} hide={['itemGroup']} />

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={hasFilters ? 'Tidak ada data untuk filter ini.' : 'No sales in this period yet.'}
        sort={sort}
        onSortChange={(key) => setSort((prev) => (prev.key === key ? { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: 'desc' }))}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={onPageChange} />}

      <Dialog open={!!customersFor} onOpenChange={(open) => !open && setCustomersFor(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Customers — {customersFor?.item_name}</DialogTitle>
          </DialogHeader>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Customer</TableHead>
                <TableHead className="text-right">Qty</TableHead>
                <TableHead className="text-right">Amount</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {customersQuery.isLoading ? (
                <TableRow>
                  <TableCell colSpan={3} className="text-center text-muted-foreground">Loading…</TableCell>
                </TableRow>
              ) : customersQuery.data?.length ? (
                customersQuery.data.map((row) => (
                  <TableRow key={row.customer_id}>
                    <TableCell>{row.customer_name}</TableCell>
                    <TableCell className="text-right">{formatNumber(row.qty)}</TableCell>
                    <TableCell className="text-right">{formatCurrency(row.amount)}</TableCell>
                  </TableRow>
                ))
              ) : (
                <TableRow>
                  <TableCell colSpan={3} className="text-center text-muted-foreground">No customers found.</TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </DialogContent>
      </Dialog>
    </div>
  )
}
