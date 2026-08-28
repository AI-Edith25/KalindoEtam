import { useQuery } from '@tanstack/react-query'
import { Download, Pencil, Plus, RotateCw, Trash2, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { RowActionsMenu } from '@/components/shared/RowActionsMenu'
import { Pagination } from '@/components/shared/Pagination'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { SectionNav } from '@/components/shared/SectionNav'
import { useEntityListPage } from '@/shared/hooks/useEntityListPage'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { fetchBranches, fetchSalesPersonsLookup } from '../api/lookupsApi'
import { deleteSalesTarget, fetchSalesTargets } from '../api/salesTargetApi'
import { SalesTargetFormDrawer } from '../components/SalesTargetFormDrawer'
import { SalesTargetFiltersBar } from '../components/SalesTargetFiltersBar'
import { applySalesTargetFilters, emptySalesTargetFilters, type SalesTargetFilterValues } from '../lib/salesTargetFilters'
import { monthLabel } from '../lib/months'
import type { SalesTarget } from '../types'

const SORTERS: Record<string, (s: SalesTarget) => string | number> = {
  period: (s) => s.period_year * 100 + s.period_month,
  sales_person: (s) => s.sales_person?.name ?? '',
  target_amount: (s) => Number(s.target_amount),
}

export function SalesTargetListPage() {
  const canCreate = useHasPermission('master.sales_targets.create')
  const canUpdate = useHasPermission('master.sales_targets.update')
  const canDelete = useHasPermission('master.sales_targets.delete')

  const salesPersonsQuery = useQuery({ queryKey: ['sales-persons-lookup'], queryFn: fetchSalesPersonsLookup })
  const branchesQuery = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches })
  const salesPersonOptions = (salesPersonsQuery.data ?? []).map((s) => ({ value: s.id, label: `${s.code} — ${s.name}` }))
  const branchOptions = (branchesQuery.data ?? []).map((b) => ({ value: b.id, label: b.name }))

  const list = useEntityListPage<SalesTarget, SalesTargetFilterValues>({
    queryKey: 'sales-targets',
    fetchList: fetchSalesTargets,
    deleteOne: deleteSalesTarget,
    applyFilters: applySalesTargetFilters,
    emptyFilters: emptySalesTargetFilters,
    sorters: SORTERS,
    deletedMessage: 'Sales target deleted.',
  })

  const columns: DataTableColumn<SalesTarget>[] = [
    { header: 'Sales Person', accessor: (row) => row.sales_person?.name ?? '—', sortKey: 'sales_person' },
    { header: 'Branch', accessor: (row) => row.branch?.name ?? 'Company-wide' },
    { header: 'Period', accessor: (row) => `${monthLabel(row.period_month)} ${row.period_year}`, sortKey: 'period' },
    { header: 'Target', accessor: (row) => formatCurrency(row.target_amount), className: 'text-right', sortKey: 'target_amount' },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => (
        <RowActionsMenu
          actions={[
            ...(canUpdate ? [{ label: 'Edit', icon: Pencil, onClick: () => list.openEdit(row) }] : []),
            ...(canDelete ? [{ label: 'Delete', icon: Trash2, variant: 'destructive' as const, onClick: () => list.setDeletingItem(row) }] : []),
          ]}
        />
      ),
    },
  ]

  const hasFilters = !!(list.search || list.filters.periodMonth !== null || list.filters.periodYear !== null || list.filters.salesPersonId !== null)

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="master" />

      <PageHeader
        title="Sales Targets"
        description="Monthly sales targets per sales person, optionally split per branch."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} targets` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Sales Target', icon: Plus, onClick: list.openCreate } : undefined}
          />
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox value={list.search} onChange={list.setSearch} placeholder="Search sales person or branch…" />
        <SalesTargetFiltersBar value={list.filters} onChange={list.setFilters} salesPersonOptions={salesPersonOptions} />
      </div>

      <DataTable
        columns={columns}
        data={list.rows}
        rowKey={(row) => row.id}
        isLoading={list.listQuery.isLoading}
        isError={list.listQuery.isError}
        onRetry={() => list.listQuery.refetch()}
        emptyMessage={hasFilters ? 'No sales targets match your search or filters.' : 'No sales targets yet.'}
        onRowClick={canUpdate ? (row) => list.openEdit(row) : undefined}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} />}

      <SalesTargetFormDrawer
        open={list.formOpen}
        onOpenChange={list.setFormOpen}
        salesTarget={list.editingItem}
        salesPersonOptions={salesPersonOptions}
        branchOptions={branchOptions}
      />

      <DeleteDialog
        open={!!list.deletingItem}
        onOpenChange={(open) => !open && list.setDeletingItem(null)}
        itemLabel={list.deletingItem ? `${list.deletingItem.sales_person?.name} — ${monthLabel(list.deletingItem.period_month)} ${list.deletingItem.period_year}` : undefined}
        onConfirm={list.confirmDelete}
      />
    </div>
  )
}
