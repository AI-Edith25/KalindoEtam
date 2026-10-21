import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Banknote, Download, RotateCw, Truck } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SectionNav } from '@/components/shared/SectionNav'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { exportDriverCommissionReport, fetchDriverCommissionReport } from '../api/driverCommissionReportApi'
import { DriverCommissionReportFiltersBar } from '../components/DriverCommissionReportFiltersBar'
import { emptyDriverCommissionReportFilters } from '../lib/reportFilters'
import { reportFileName } from '../lib/exportFileName'
import type { DriverCommissionReportDriverSummary, DriverCommissionReportFilterValues, DriverCommissionReportRow } from '../types'

export function DriverCommissionReportPage() {
  const [filters, setFilters] = useState<DriverCommissionReportFilterValues>(emptyDriverCommissionReportFilters)
  const [isExporting, setIsExporting] = useState(false)

  const activeParams = {
    ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
    ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
    ...(filters.driver ? { driver: filters.driver } : {}),
    ...(filters.fleet_id ? { fleet_id: filters.fleet_id } : {}),
    ...(filters.item_id ? { item_id: filters.item_id } : {}),
    status: filters.status,
  }

  const reportQuery = useQuery({
    queryKey: ['driver-commission-report', activeParams],
    queryFn: () => fetchDriverCommissionReport(activeParams),
  })

  const rows = useMemo(
    () => (reportQuery.data?.rows ?? []).map((row, index) => ({ ...row, _key: `${row.do_number ?? 'row'}-${row.item_name ?? ''}-${index}` })),
    [reportQuery.data],
  )
  const summary = reportQuery.data?.summary

  const exportReport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = await exportDriverCommissionReport(activeParams, format)
      downloadBlob(reportFileName('KomisiSopir', filters.dateFrom || 'all', filters.dateTo || 'all', format), blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const columns: DataTableColumn<DriverCommissionReportRow>[] = [
    { header: 'Tanggal', accessor: (row) => formatDate(row.date) },
    { header: 'No. SO', accessor: (row) => row.so_number ?? '—' },
    { header: 'No. DO', accessor: (row) => row.do_number ?? '—' },
    { header: 'Customer', accessor: (row) => row.customer ?? '—' },
    { header: 'Jenis Barang', accessor: (row) => row.item_name ?? '—' },
    { header: 'Qty (Zak)', accessor: (row) => formatNumber(row.qty), className: 'text-right' },
    { header: 'No. Plat', accessor: (row) => row.plat_number ?? '—' },
    { header: 'Sopir', accessor: (row) => row.driver ?? '—' },
    { header: 'Tarif/Zak', accessor: (row) => formatCurrency(row.rate_per_zak), className: 'text-right' },
    { header: 'Total Komisi', accessor: (row) => formatCurrency(row.total_commission), className: 'text-right' },
  ]

  const driverSummaryColumns: DataTableColumn<DriverCommissionReportDriverSummary>[] = useMemo(
    () => [
      { header: 'Sopir', accessor: (row) => row.driver ?? '—' },
      { header: 'Qty (Zak)', accessor: (row) => formatNumber(row.total_qty), className: 'text-right' },
      { header: 'Total Komisi', accessor: (row) => formatCurrency(row.total_commission), className: 'text-right' },
    ],
    [],
  )

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="Komisi Sopir"
        description="Commission earned per driver delivery — see the Status filter to include/exclude deliveries already paid in the field."
        count={reportQuery.data ? `${formatNumber(rows.length)} rows` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => reportQuery.refetch(), disabled: reportQuery.isFetching },
              { label: 'Export XLSX', icon: Download, onClick: () => exportReport('xlsx'), disabled: isExporting || rows.length === 0 },
              { label: 'Export CSV', icon: Download, onClick: () => exportReport('csv'), disabled: isExporting || rows.length === 0 },
            ]}
          />
        }
      />

      <DriverCommissionReportFiltersBar value={filters} onChange={setFilters} />

      {summary && (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <SummaryCard title="Total Qty (Zak)" value={formatNumber(summary.total_qty)} icon={Truck} />
          <SummaryCard title="Total Komisi" value={formatCurrency(summary.total_commission)} icon={Banknote} />
        </div>
      )}

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row._key}
        isLoading={reportQuery.isLoading}
        isError={reportQuery.isError}
        onRetry={() => reportQuery.refetch()}
        emptyMessage="No commissioned deliveries match your filters."
      />

      {summary && summary.by_driver.length > 0 && (
        <>
          <h3 className="text-sm font-medium text-muted-foreground">Per Sopir</h3>
          <DataTable columns={driverSummaryColumns} data={summary.by_driver} rowKey={(row) => row.driver ?? 'unknown'} />
        </>
      )}
    </div>
  )
}
