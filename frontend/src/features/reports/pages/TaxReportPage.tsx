import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, Printer, RotateCw, Landmark, TrendingUp, TrendingDown } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { Pagination } from '@/components/shared/Pagination'
import { SectionNav } from '@/components/shared/SectionNav'
import { SummaryCard } from '@/features/dashboard/components/SummaryCard'
import { Button } from '@/components/ui/button'
import { TableCell, TableRow } from '@/components/ui/table'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { exportInputTax, exportOutputTax, fetchInputTax, fetchOutputTax, fetchTaxReportSummary } from '../api/taxReportApi'
import type { TaxReportRow } from '../types'
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { toastApiError } from '@/shared/services/errorHandler'
import { TaxReportFiltersBar } from '../components/TaxReportFiltersBar'
import { currentMonthTaxReportFilters } from '../lib/reportFilters'
import type { TaxReportFilterValues } from '../types'

type SubTab = 'output' | 'input'

/** Tax report (PPN Keluaran/Masukan) — mirrors AR/AP Detail's page shape (PageHeader/ActionBar/export-dropdown/Print), with a PPN Keluaran/Masukan sub-tab toggle instead of Aging-List/Perincian. */
export function TaxReportPage() {
  const navigate = useNavigate()

  const [page, setPage] = useState(1)
  const [subTab, setSubTab] = useState<SubTab>('output')
  const [filters, setFilters] = useState<TaxReportFilterValues>(currentMonthTaxReportFilters)

  const activeFilterParams = {
    date_from: filters.dateFrom,
    date_to: filters.dateTo,
    ...(subTab === 'output' && filters.tax_id ? { tax_id: filters.tax_id } : {}),
    ...(subTab === 'output' && filters.customer_id ? { customer_id: filters.customer_id } : {}),
    ...(subTab === 'output' && filters.branch_id ? { branch_id: filters.branch_id } : {}),
    ...(subTab === 'input' && filters.supplier_id ? { supplier_id: filters.supplier_id } : {}),
    ...(subTab === 'input' && filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : {}),
  }

  const printParams = new URLSearchParams({ ...activeFilterParams, sub_tab: subTab }).toString()

  const listQuery = useQuery({
    queryKey: ['tax-report', subTab, page, activeFilterParams],
    queryFn: () => (subTab === 'output' ? fetchOutputTax({ page, ...activeFilterParams }) : fetchInputTax({ page, ...activeFilterParams })),
    placeholderData: (previous) => previous,
  })

  // Summary always reflects the on-screen sub-tab's own filters for that side; the other side's
  // total in the cards below still needs its own (unfiltered-by-the-other-side's-specific-filters)
  // figure, so the summary endpoint is called once with whichever filters are active — date range
  // applies to both, Customer/Branch/Supplier/Warehouse only narrow their own side server-side.
  const summaryQuery = useQuery({
    queryKey: ['tax-report-summary', filters.dateFrom, filters.dateTo],
    queryFn: () => fetchTaxReportSummary({ date_from: filters.dateFrom, date_to: filters.dateTo }),
  })

  const [isExporting, setIsExporting] = useState(false)
  const exportReport = async (format: 'xlsx' | 'csv') => {
    setIsExporting(true)
    try {
      const blob = subTab === 'output' ? await exportOutputTax(activeFilterParams, format) : await exportInputTax(activeFilterParams, format)
      downloadBlob(`PPN${subTab === 'output' ? 'Keluaran' : 'Masukan'}.${format}`, blob)
    } catch (error) {
      toastApiError(error)
    } finally {
      setIsExporting(false)
    }
  }

  const rows = listQuery.data?.data ?? []
  const totalDpp = rows.reduce((sum, r) => sum + r.dpp, 0)
  const totalPpn = rows.reduce((sum, r) => sum + r.ppn, 0)

  const columns: DataTableColumn<TaxReportRow>[] =
    subTab === 'output'
      ? [
          { header: 'Tanggal', accessor: (row) => formatDate(row.document_date) },
          { header: 'No Invoice', accessor: (row) => row.document_number ?? '—' },
          { header: 'Customer', accessor: (row) => row.party_name },
          { header: 'NPWP', accessor: () => '—' },
          { header: 'Kode Pajak', accessor: (row) => row.tax_code ?? '—' },
          { header: 'Tarif', accessor: (row) => (row.tax_rate !== null ? `${row.tax_rate.toFixed(2)}%` : '—'), className: 'text-right' },
          { header: 'DPP', accessor: (row) => formatCurrency(row.dpp), className: 'text-right' },
          { header: 'PPN', accessor: (row) => formatCurrency(row.ppn), className: 'text-right' },
          { header: 'Total', accessor: (row) => formatCurrency(row.total), className: 'text-right' },
        ]
      : [
          { header: 'Tanggal', accessor: (row) => formatDate(row.document_date) },
          { header: 'No Invoice', accessor: (row) => row.document_number ?? '—' },
          { header: 'Supplier', accessor: (row) => row.party_name },
          { header: 'NPWP', accessor: () => '—' },
          { header: 'Kode Pajak', accessor: () => '—' },
          { header: 'Tarif', accessor: () => '—', className: 'text-right' },
          { header: 'DPP', accessor: (row) => formatCurrency(row.dpp), className: 'text-right' },
          { header: 'PPN', accessor: (row) => formatCurrency(row.ppn), className: 'text-right' },
          { header: 'Total', accessor: (row) => formatCurrency(row.total), className: 'text-right' },
        ]

  const footerRow = (
    <TableRow>
      <TableCell colSpan={6} className="font-semibold">
        TOTAL
      </TableCell>
      <TableCell className="text-right font-semibold">{formatCurrency(totalDpp)}</TableCell>
      <TableCell className="text-right font-semibold">{formatCurrency(totalPpn)}</TableCell>
      <TableCell className="text-right font-semibold">{formatCurrency(totalDpp + totalPpn)}</TableCell>
    </TableRow>
  )

  const selisih = summaryQuery.data?.selisih ?? 0

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="Tax"
        description="PPN Keluaran (Sales) and PPN Masukan (Purchase), by document."
        count={listQuery.data?.meta ? `${formatNumber(listQuery.data.meta.total)} rows` : undefined}
        actions={
          <>
            <Button variant="outline" onClick={() => exportReport('csv')} disabled={isExporting}>
              <Download className="size-4" />
              Export CSV
            </Button>
            <Button variant="outline" onClick={() => exportReport('xlsx')} disabled={isExporting}>
              <Download className="size-4" />
              Export XLSX
            </Button>
            <ActionBar
              actions={[
                { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
                { label: 'Print', icon: Printer, onClick: () => navigate(`/reports/tax/print${printParams ? `?${printParams}` : ''}`) },
              ]}
            />
          </>
        }
      />

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <SummaryCard
          title="Total PPN Keluaran"
          value={formatCurrency(summaryQuery.data?.output_ppn ?? 0)}
          icon={TrendingUp}
          isLoading={summaryQuery.isLoading}
        />
        <SummaryCard
          title="Total PPN Masukan"
          value={formatCurrency(summaryQuery.data?.input_ppn ?? 0)}
          icon={TrendingDown}
          isLoading={summaryQuery.isLoading}
        />
        <SummaryCard
          title={selisih >= 0 ? 'Selisih (Kurang Bayar)' : 'Selisih (Lebih Bayar)'}
          value={formatCurrency(Math.abs(selisih))}
          icon={Landmark}
          tone={selisih >= 0 ? 'danger' : 'default'}
          isLoading={summaryQuery.isLoading}
        />
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex items-center gap-1 rounded-md border p-1">
          <Button
            size="sm"
            variant={subTab === 'output' ? 'default' : 'ghost'}
            onClick={() => {
              setSubTab('output')
              setPage(1)
            }}
          >
            PPN Keluaran
          </Button>
          <Button
            size="sm"
            variant={subTab === 'input' ? 'default' : 'ghost'}
            onClick={() => {
              setSubTab('input')
              setPage(1)
            }}
          >
            PPN Masukan
          </Button>
        </div>
        <TaxReportFiltersBar
          mode={subTab}
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
        rowKey={(row) => `${row.document_type}-${row.document_id}`}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage="No documents in this period."
        rowClassName={(row) => (row.dpp < 0 || row.ppn < 0 ? 'text-destructive' : undefined)}
        footerRow={footerRow}
      />

      {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
    </div>
  )
}
