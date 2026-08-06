import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, Printer, RotateCw, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SectionNav } from '@/components/shared/SectionNav'
import { Card, CardContent } from '@/components/ui/card'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { fetchAccountsReceivableAging, type AccountsReceivableAgingRow } from '../api/accountsReceivableAgingApi'
import { AccountsReceivableAgingFiltersBar } from '../components/AccountsReceivableAgingFiltersBar'
import { emptyArAgingReportFilters } from '../lib/reportFilters'
import type { ArAgingReportFilterValues } from '../types'

/** A read model, like Trial Balance — no create/edit/delete anywhere on this page. */
export function AccountsReceivableAgingReportPage() {
  const navigate = useNavigate()
  const [filters, setFilters] = useState<ArAgingReportFilterValues>(emptyArAgingReportFilters)

  const listQuery = useQuery({
    queryKey: ['ar-aging-report', filters.customer_id, filters.asOfDate],
    queryFn: () =>
      fetchAccountsReceivableAging({
        ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
        ...(filters.asOfDate ? { as_of_date: filters.asOfDate } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const rows = listQuery.data?.rows ?? []
  const totals = listQuery.data?.totals

  const columns: DataTableColumn<AccountsReceivableAgingRow>[] = [
    { header: 'Customer', accessor: (row) => row.customer_name },
    { header: '0-30 Days', accessor: (row) => formatCurrency(row.bucket_0_30), className: 'text-right' },
    { header: '31-60 Days', accessor: (row) => formatCurrency(row.bucket_31_60), className: 'text-right' },
    { header: '61-90 Days', accessor: (row) => formatCurrency(row.bucket_61_90), className: 'text-right' },
    { header: 'Over 90 Days', accessor: (row) => formatCurrency(row.bucket_over_90), className: 'text-right' },
    { header: 'Total Outstanding', accessor: (row) => formatCurrency(row.total_outstanding), className: 'text-right font-medium' },
  ]

  const printParams = new URLSearchParams({
    ...(filters.customer_id ? { customer_id: filters.customer_id } : {}),
    ...(filters.asOfDate ? { as_of_date: filters.asOfDate } : {}),
  }).toString()

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="AR Aging"
        description="Outstanding receivables aged into 0-30 / 31-60 / 61-90 / Over 90 day buckets, by customer."
        count={rows.length ? `${formatNumber(rows.length)} customers` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
              {
                label: 'Print',
                icon: Printer,
                onClick: () => navigate(`/reports/ar-aging/print${printParams ? `?${printParams}` : ''}`),
              },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
          />
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <AccountsReceivableAgingFiltersBar value={filters} onChange={setFilters} />
      </div>

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.customer_id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage="No outstanding receivables."
      />

      {totals && (
        <Card>
          <CardContent className="flex flex-wrap items-center justify-end gap-6 py-4">
            <div className="flex items-center gap-2 text-base">
              <span className="text-muted-foreground">Total Outstanding</span>
              <span className="font-semibold">{formatCurrency(totals.total_outstanding)}</span>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  )
}
