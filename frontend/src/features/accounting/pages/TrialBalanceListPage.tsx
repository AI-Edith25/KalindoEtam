import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, RotateCw, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SectionNav } from '@/components/shared/SectionNav'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { fetchCompaniesLookup } from '@/features/master/api/lookupsApi'
import { fetchTrialBalance } from '../api/trialBalanceApi'
import { TrialBalanceFiltersBar } from '../components/TrialBalanceFiltersBar'
import { emptyTrialBalanceFilters, resolvePeriodPreset, withinAccountRange } from '../lib/trialBalanceFilters'
import { accountingSectionNav } from '../navigation'
import type { TrialBalanceFilterValues, TrialBalanceRow } from '../types'

/** A read model, like General Ledger — no create/edit/delete anywhere on this page. See docs/TRIAL_BALANCE_DESIGN.md §1. */
export function TrialBalanceListPage() {
  const navigate = useNavigate()
  const [filters, setFilters] = useState<TrialBalanceFilterValues>(emptyTrialBalanceFilters)

  const companies = useQuery({ queryKey: ['companies-lookup'], queryFn: fetchCompaniesLookup })
  const { dateFrom, dateTo } = resolvePeriodPreset(filters, companies.data ?? [])

  const listQuery = useQuery({
    queryKey: ['trial-balance', dateFrom, dateTo, filters.branchId, filters.companyId],
    queryFn: () =>
      fetchTrialBalance({
        ...(dateFrom ? { date_from: dateFrom } : {}),
        ...(dateTo ? { date_to: dateTo } : {}),
        ...(filters.branchId ? { branch_id: filters.branchId } : {}),
        ...(filters.companyId ? { company_id: filters.companyId } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const visibleRows = useMemo(() => {
    const rows = listQuery.data?.rows ?? []
    return rows.filter((row) => {
      if (filters.hideZeroBalance && Number(row.debit) === 0 && Number(row.credit) === 0) return false
      if (!withinAccountRange(row.code, filters.codeFrom, filters.codeTo)) return false
      return true
    })
  }, [listQuery.data, filters.hideZeroBalance, filters.codeFrom, filters.codeTo])

  const isFiltered = filters.hideZeroBalance || filters.codeFrom !== '' || filters.codeTo !== ''
  const totalDebit = isFiltered
    ? visibleRows.reduce((sum, row) => sum + Number(row.debit), 0)
    : Number(listQuery.data?.total_debit ?? 0)
  const totalCredit = isFiltered
    ? visibleRows.reduce((sum, row) => sum + Number(row.credit), 0)
    : Number(listQuery.data?.total_credit ?? 0)
  const isBalanced = Math.round(totalDebit * 100) === Math.round(totalCredit * 100)

  const columns: DataTableColumn<TrialBalanceRow>[] = [
    { header: 'Code', accessor: (row) => row.code, className: 'text-muted-foreground' },
    { header: 'Name', accessor: (row) => row.name },
    { header: 'Type', accessor: (row) => <span className="capitalize">{row.account_type}</span>, className: 'text-muted-foreground' },
    { header: 'Debit', accessor: (row) => (Number(row.debit) > 0 ? formatCurrency(row.debit) : '—'), className: 'text-right font-medium' },
    { header: 'Credit', accessor: (row) => (Number(row.credit) > 0 ? formatCurrency(row.credit) : '—'), className: 'text-right font-medium' },
  ]

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={accountingSectionNav} />

      <PageHeader
        title="Trial Balance"
        description="A read-only report of every account's Debit or Credit ending balance for a reporting period — a presentation layer over the General Ledger, never a second calculation."
        count={visibleRows.length ? `${formatNumber(visibleRows.length)} accounts` : undefined}
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
        <TrialBalanceFiltersBar value={filters} onChange={setFilters} />
      </div>

      <DataTable
        columns={columns}
        data={visibleRows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage="No activity in this period."
        onRowClick={(row) =>
          navigate(`/accounting/general-ledger/${row.id}?${new URLSearchParams({ ...(dateFrom ? { date_from: dateFrom } : {}), ...(dateTo ? { date_to: dateTo } : {}) }).toString()}`)
        }
      />

      <Card>
        <CardContent className="flex flex-wrap items-center justify-end gap-6 py-4">
          <Badge className={isBalanced ? 'bg-green-100 text-green-700 border-transparent dark:bg-green-950 dark:text-green-300' : 'bg-red-100 text-red-700 border-transparent dark:bg-red-950 dark:text-red-300'}>
            {isBalanced ? 'Balanced' : `Imbalance detected (${formatCurrency(Math.abs(totalDebit - totalCredit))})`}
          </Badge>
          <div className="flex items-center gap-2 text-base">
            <span className="text-muted-foreground">Total Debit</span>
            <span className="font-semibold">{formatCurrency(totalDebit)}</span>
          </div>
          <div className="flex items-center gap-2 text-base">
            <span className="text-muted-foreground">Total Credit</span>
            <span className="font-semibold">{formatCurrency(totalCredit)}</span>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
