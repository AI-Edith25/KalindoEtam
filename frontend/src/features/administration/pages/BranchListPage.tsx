import { Eye, Pencil, Plus, RotateCw, Trash2 } from 'lucide-react'
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
import type { Branch } from '@/features/master/types'
import { deleteBranch, fetchBranchesPaged } from '../api/branchApi'
import { BranchFormDrawer } from '../components/BranchFormDrawer'
import { BranchDetailDrawer } from '../components/BranchDetailDrawer'
import { BranchFiltersBar } from '../components/BranchFiltersBar'
import { applyBranchFilters, emptyBranchFilters, type BranchFilterValues } from '../lib/branchFilters'

const SORTERS: Record<string, (b: Branch) => string | number> = {
  code: (b) => b.code,
  name: (b) => b.name,
}

export function BranchListPage() {
  const canCreate = useHasPermission('administration.branch.create')
  const canUpdate = useHasPermission('administration.branch.update')
  const canDelete = useHasPermission('administration.branch.delete')
  const list = useEntityListPage<Branch, BranchFilterValues>({
    queryKey: 'branches',
    fetchList: fetchBranchesPaged,
    deleteOne: deleteBranch,
    applyFilters: applyBranchFilters,
    emptyFilters: emptyBranchFilters,
    sorters: SORTERS,
    deletedMessage: 'Branch deleted.',
  })

  const columns: DataTableColumn<Branch>[] = [
    { header: 'Code', accessor: (row) => row.code, sortKey: 'code' },
    { header: 'Name', accessor: (row) => row.name, sortKey: 'name' },
    { header: 'Address', accessor: (row) => row.address ?? '—' },
    { header: 'Head Office', accessor: (row) => (row.is_head_office ? 'Yes' : 'No') },
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
      <SectionNav group="administration" />

      <PageHeader
        title="Branches"
        description="Manage the branches Sales Orders can be attributed to."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} branches` : undefined}
        actions={
          <ActionBar
            actions={[{ label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching }]}
            primary={canCreate ? { label: 'New Branch', icon: Plus, onClick: list.openCreate } : undefined}
          />
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox value={list.search} onChange={list.setSearch} placeholder="Search code or name…" />
        <BranchFiltersBar value={list.filters} onChange={list.setFilters} />
      </div>

      <DataTable
        columns={columns}
        data={list.rows}
        rowKey={(row) => row.id}
        isLoading={list.listQuery.isLoading}
        isError={list.listQuery.isError}
        onRetry={() => list.listQuery.refetch()}
        emptyMessage={list.search || list.filters.isActive !== null ? 'No branches match your search or filters.' : 'No branches yet.'}
        onRowClick={(row) => list.setDetailItem(row)}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} />}

      <BranchFormDrawer open={list.formOpen} onOpenChange={list.setFormOpen} branch={list.editingItem} />

      <BranchDetailDrawer
        open={!!list.detailItem}
        onOpenChange={(open) => !open && list.setDetailItem(null)}
        branch={list.detailItem}
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
