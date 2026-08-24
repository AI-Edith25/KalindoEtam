import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, FileText, Printer, RotateCw, TrendingUp, Trophy, Users } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn, type DataTableSort } from '@/components/shared/DataTable'
import { Pagination } from '@/components/shared/Pagination'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import {
  exportCustomerSales,
  fetchCustomerSales,
  fetchCustomerSalesDocuments,
  fetchSalesAchievement,
  type CustomerSalesParams,
} from '../api/customerSalesApi'
import { SalesReportFiltersBar } from './SalesReportFiltersBar'
import { reportFileName } from '../lib/exportFileName'
import type { CustomerSalesRow, SalesReportFilterValues } from '../types'

interface CustomerSalesPanelProps {
  filters: SalesReportFilterValues
  onFiltersChange: (filters: SalesReportFilterValues) => void
  page: number
  onPageChange: (page: number) => void
}

export function CustomerSalesPanel({ filters, onFiltersChange, page, onPageChange }: CustomerSalesPanelProps) {
  const navigate = useNavigate()
  const [sort, setSort] = useState<DataTableSort>({ key: 'amount', direction: 'desc' })
  const [isExporting, setIsExporting] = useState(false)
  const [documentsFor, setDocumentsFor] = useState<CustomerSalesRow | null>(null)

  const activeParams: Omit<CustomerSalesParams, 'page'> = {
    sort: sort.key as CustomerSalesParams['sort'],
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
    queryKey: ['customer-sales', page, sort.key, sort.direction, filters],
    queryFn: () => fetchCustomerSales({ ...activeParams, page }),
    placeholderData: (previous) => previous,
  })

  const achievementQuery = useQuery({
    queryKey: ['sales-achievement', filters],
    queryFn: () => fetchSalesAchievement(activeParams),
  })

  const documentsQuery = useQuery({
    queryKey: ['customer-sales-documents', documentsFor?.id, filters],
    queryFn: () => fetchCustomerSalesDocuments(documentsFor!.id, activeParams),
    enabled: !!documentsFor,
  })

  const rows = listQuery.data?.data ?? []
  const kpis = listQuery.data?.meta.kpis

  const exportReport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportCustomerSales(activeParams, format)
      downloadBlob(reportFileName('CustomerSalesReport', filters.dateFrom, filters.dateTo, format), blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const columns: DataTableColumn<CustomerSalesRow>[] = [
    { header: 'Customer Code', accessor: (row) => row.customer_code },
    { header: 'Customer Name', accessor: (row) => row.customer_name, sortKey: 'customer_name' },
    { header: 'Branch', accessor: (row) => row.branch_name ?? 'Multiple' },
    { header: 'Sales Person', accessor: (row) => row.sales_person_name ?? 'Multiple' },
    { header: '# Transactions', accessor: (row) => formatNumber(row.transaction_count), className: 'text-right', sortKey: 'transaction_count' },
    { header: 'Total Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right', sortKey: 'qty' },
    { header: 'Amount Excl. Tax', accessor: (row) => formatCurrency(row.amount), className: 'text-right', sortKey: 'amount' },
    { header: 'Tax', accessor: (row) => formatCurrency(row.tax_amount), className: 'text-right' },
    { header: 'Amount Incl. Tax', accessor: (row) => formatCurrency(row.amount_incl_tax), className: 'text-right' },
    {
      header: '% of Revenue',
      accessor: (row) => (kpis && kpis.total_revenue > 0 ? `${((row.amount / kpis.total_revenue) * 100).toFixed(1)}%` : '—'),
      className: 'text-right',
    },
    { header: 'Last Transaction', accessor: (row) => (row.last_transaction_date ? formatDate(row.last_transaction_date) : '—') },
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
        <SummaryCard title="Active Customers" value={formatNumber(kpis?.total_customers ?? 0)} icon={Users} isLoading={listQuery.isLoading} />
        <SummaryCard title="Total Revenue" value={formatCurrency(kpis?.total_revenue ?? 0)} icon={TrendingUp} isLoading={listQuery.isLoading} />
        <SummaryCard title="Avg per Customer" value={formatCurrency(kpis?.avg_per_customer ?? 0)} icon={FileText} isLoading={listQuery.isLoading} />
        <SummaryCard
          title="Top Customer"
          value={kpis?.top_customer_name ?? '—'}
          description={kpis ? formatCurrency(kpis.top_customer_amount) : undefined}
          icon={Trophy}
          isLoading={listQuery.isLoading}
        />
      </div>

      <div className="flex flex-wrap items-center justify-end gap-3">
        <ActionBar
          actions={[
            { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
            {
              label: 'Print',
              icon: Printer,
              onClick: () => {
                const params = new URLSearchParams({
                  tab: 'customers',
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

      <SalesReportFiltersBar value={filters} onChange={(next) => { onFiltersChange(next); onPageChange(1) }} />

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage={hasFilters ? 'Tidak ada data untuk filter ini.' : 'No sales in this period yet.'}
        onRowClick={(row) => setDocumentsFor(row)}
        sort={sort}
        onSortChange={(key) => setSort((prev) => (prev.key === key ? { key, direction: prev.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: 'desc' }))}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={onPageChange} />}

      <Card>
        <CardHeader>
          <CardTitle>Sales Achievement by Sales Person</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable
            columns={[
              { header: 'Sales Person', accessor: (row) => row.sales_person_name },
              { header: 'Total Qty', accessor: (row) => formatNumber(row.qty), className: 'text-right' },
              { header: 'Total Nominal', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
            ]}
            data={achievementQuery.data ?? []}
            rowKey={(row) => row.sales_person_id ?? 'unassigned'}
            isLoading={achievementQuery.isLoading}
            emptyMessage="No sales in this period yet."
          />
        </CardContent>
      </Card>

      <Dialog open={!!documentsFor} onOpenChange={(open) => !open && setDocumentsFor(null)}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Documents — {documentsFor?.customer_name}</DialogTitle>
          </DialogHeader>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Date</TableHead>
                <TableHead>Document #</TableHead>
                <TableHead>Reference SO #</TableHead>
                <TableHead>Type</TableHead>
                <TableHead className="text-right">Amount Excl.</TableHead>
                <TableHead className="text-right">Tax</TableHead>
                <TableHead className="text-right">Amount Incl.</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {documentsQuery.isLoading ? (
                <TableRow>
                  <TableCell colSpan={7} className="text-center text-muted-foreground">Loading…</TableCell>
                </TableRow>
              ) : documentsQuery.data?.documents.length ? (
                documentsQuery.data.documents.map((doc) => (
                  <TableRow key={doc.id}>
                    <TableCell>{doc.date ? formatDate(doc.date) : '—'}</TableCell>
                    <TableCell>{doc.document_number ?? '—'}</TableCell>
                    <TableCell>{doc.reference_so_number ?? '—'}</TableCell>
                    <TableCell className="capitalize">{doc.type ?? '—'}</TableCell>
                    <TableCell className="text-right">{formatCurrency(doc.amount)}</TableCell>
                    <TableCell className="text-right">{formatCurrency(doc.tax_amount)}</TableCell>
                    <TableCell className="text-right">{formatCurrency(doc.amount_incl_tax)}</TableCell>
                  </TableRow>
                ))
              ) : (
                <TableRow>
                  <TableCell colSpan={7} className="text-center text-muted-foreground">No documents found.</TableCell>
                </TableRow>
              )}
            </TableBody>
            {documentsQuery.data && (
              <TableFooter>
                <TableRow>
                  <TableCell colSpan={4} className="font-medium">Sub-Total</TableCell>
                  <TableCell className="text-right font-medium">{formatCurrency(documentsQuery.data.subtotal.amount)}</TableCell>
                  <TableCell className="text-right font-medium">{formatCurrency(documentsQuery.data.subtotal.tax_amount)}</TableCell>
                  <TableCell className="text-right font-medium">{formatCurrency(documentsQuery.data.subtotal.amount_incl_tax)}</TableCell>
                </TableRow>
              </TableFooter>
            )}
          </Table>
        </DialogContent>
      </Dialog>
    </div>
  )
}
