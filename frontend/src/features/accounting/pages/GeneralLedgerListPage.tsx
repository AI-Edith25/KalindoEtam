import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Download, RotateCw, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SectionNav } from '@/components/shared/SectionNav'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { fetchLedgerAccounts } from '../api/generalLedgerApi'
import { GeneralLedgerFiltersBar } from '../components/GeneralLedgerFiltersBar'
import { emptyGeneralLedgerFilters } from '../lib/generalLedgerFilters'
import { accountingSectionNav } from '../navigation'
import type { GeneralLedgerFilterValues, LedgerAccountSummary } from '../types'

/** A read model — no create/edit/delete anywhere on this page. See docs/GENERAL_LEDGER_DESIGN.md §1. */
export function GeneralLedgerListPage() {
  const navigate = useNavigate()
  const [filters, setFilters] = useState<GeneralLedgerFilterValues>(emptyGeneralLedgerFilters)

  const listQuery = useQuery({
    queryKey: ['general-ledger-accounts', filters.status, filters.referenceType, filters.branchId, filters.companyId, filters.dateFrom, filters.dateTo],
    queryFn: () =>
      fetchLedgerAccounts({
        ...(filters.status ? { status: filters.status } : {}),
        ...(filters.referenceType ? { reference_type: filters.referenceType } : {}),
        ...(filters.branchId ? { branch_id: filters.branchId } : {}),
        ...(filters.companyId ? { company_id: filters.companyId } : {}),
        ...(filters.dateFrom ? { date_from: filters.dateFrom } : {}),
        ...(filters.dateTo ? { date_to: filters.dateTo } : {}),
      }),
    placeholderData: (previous) => previous,
  })

  const rows = listQuery.data ?? []

  const columns: DataTableColumn<LedgerAccountSummary>[] = [
    { header: 'Code', accessor: (row) => row.code, className: 'text-muted-foreground' },
    { header: 'Name', accessor: (row) => row.name },
    { header: 'Type', accessor: (row) => <span className="capitalize">{row.account_type}</span>, className: 'text-muted-foreground' },
    { header: 'Opening Balance', accessor: (row) => formatCurrency(row.opening_balance), className: 'text-right font-medium' },
    { header: 'Debit', accessor: (row) => formatCurrency(row.debit), className: 'text-right font-medium' },
    { header: 'Credit', accessor: (row) => formatCurrency(row.credit), className: 'text-right font-medium' },
    { header: 'Ending Balance', accessor: (row) => formatCurrency(row.ending_balance), className: 'text-right font-medium' },
  ]

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={accountingSectionNav} />

      <PageHeader
        title="General Ledger"
        description="A read-only report of every account's balance for the selected period — opening, debit, credit, and ending — derived entirely from posted Journal Entries, never a separate ledger table. For account master data, see Chart of Accounts."
        count={rows.length ? `${formatNumber(rows.length)} accounts` : undefined}
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
        <GeneralLedgerFiltersBar value={filters} onChange={setFilters} variant="list" />
      </div>

      <DataTable
        columns={columns}
        data={rows}
        rowKey={(row) => row.id}
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        onRetry={() => listQuery.refetch()}
        emptyMessage="No account activity matches these filters."
        onRowClick={(row) => navigate(`/accounting/general-ledger/${row.id}`)}
      />
    </div>
  )
}
