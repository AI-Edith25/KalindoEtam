import { Download, Eye, Pencil, Plus, RotateCw, Trash2, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { RowActionsMenu } from '@/components/shared/RowActionsMenu'
import { Pagination } from '@/components/shared/Pagination'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { SectionNav } from '@/components/shared/SectionNav'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { useEntityListPage } from '@/shared/hooks/useEntityListPage'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { formatNumber } from '@/lib/utils'
import { deleteChartOfAccount, fetchChartOfAccountsPaged } from '../api/chartOfAccountApi'
import { ChartOfAccountFormDrawer } from '../components/ChartOfAccountFormDrawer'
import { ChartOfAccountDetailDrawer } from '../components/ChartOfAccountDetailDrawer'
import { ChartOfAccountFiltersBar } from '../components/ChartOfAccountFiltersBar'
import {
  applyChartOfAccountFilters,
  emptyChartOfAccountFilters,
  type ChartOfAccountFilterValues,
} from '../lib/chartOfAccountFilters'
import { masterDataNav } from '../navigation'
import type { ChartOfAccount } from '../types'

const SORTERS: Record<string, (a: ChartOfAccount) => string | number> = {
  code: (a) => a.code,
  name: (a) => a.name,
}

export function ChartOfAccountsPage() {
  const canCreate = useHasPermission('chart_of_account.create')
  const canUpdate = useHasPermission('chart_of_account.update')
  const canDelete = useHasPermission('chart_of_account.delete')
  const list = useEntityListPage<ChartOfAccount, ChartOfAccountFilterValues>({
    queryKey: 'chart-of-accounts-paged',
    fetchList: fetchChartOfAccountsPaged,
    deleteOne: deleteChartOfAccount,
    applyFilters: applyChartOfAccountFilters,
    emptyFilters: emptyChartOfAccountFilters,
    sorters: SORTERS,
    deletedMessage: 'Chart of Account deleted.',
  })

  const columns: DataTableColumn<ChartOfAccount>[] = [
    { header: 'Code', accessor: (row) => row.code, sortKey: 'code' },
    { header: 'Name', accessor: (row) => row.name, sortKey: 'name' },
    { header: 'Type', accessor: (row) => <StatusBadge status={row.account_type} /> },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.is_active ? 'active' : 'inactive'} /> },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => (
        <RowActionsMenu
          actions={[
            { label: 'View', icon: Eye, onClick: () => list.setDetailItem(row) },
            ...(canUpdate ? [{ label: 'Edit', icon: Pencil, onClick: () => list.openEdit(row) }] : []),
            ...(canDelete ? [{ label: 'Delete', icon: Trash2, variant: 'destructive' as const, onClick: () => list.setDeletingItem(row) }] : []),
          ]}
        />
      ),
    },
  ]

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={masterDataNav} />

      <PageHeader
        title="Chart of Accounts"
        description="The accounts business transactions post to — reused by the Accounting Engine's Journal Entries."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} accounts` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Account', icon: Plus, onClick: list.openCreate } : undefined}
          />
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox value={list.search} onChange={list.setSearch} placeholder="Search code or name…" />
        <ChartOfAccountFiltersBar value={list.filters} onChange={list.setFilters} />
      </div>

      <DataTable
        columns={columns}
        data={list.rows}
        rowKey={(row) => row.id}
        isLoading={list.listQuery.isLoading}
        isError={list.listQuery.isError}
        onRetry={() => list.listQuery.refetch()}
        emptyMessage={list.search ? 'No accounts match your search.' : 'No accounts yet.'}
        onRowClick={(row) => list.setDetailItem(row)}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} />}

      <ChartOfAccountFormDrawer open={list.formOpen} onOpenChange={list.setFormOpen} chartOfAccount={list.editingItem} />

      <ChartOfAccountDetailDrawer
        open={!!list.detailItem}
        onOpenChange={(open) => !open && list.setDetailItem(null)}
        chartOfAccount={list.detailItem}
        onEdit={list.openEdit}
      />

      <DeleteDialog
        open={!!list.deletingItem}
        onOpenChange={(open) => !open && list.setDeletingItem(null)}
        itemLabel={list.deletingItem?.name}
        onConfirm={list.confirmDelete}
      />
    </div>
  )
}
