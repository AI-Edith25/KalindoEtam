import { Download, Eye, Pencil, Plus, RotateCw, Trash2, Upload } from 'lucide-react'
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
import { formatNumber } from '@/lib/utils'
import { deleteUom, fetchUomsPaged } from '../api/uomApi'
import { UomFormDrawer } from '../components/UomFormDrawer'
import { UomDetailDrawer } from '../components/UomDetailDrawer'
import { applyUomFilters, emptyUomFilters, type UomFilterValues } from '../lib/uomFilters'
import { masterDataNav } from '../navigation'
import type { Uom } from '../types'

const SORTERS: Record<string, (u: Uom) => string | number> = {
  name: (u) => u.name,
}

export function UomListPage() {
  const canCreate = useHasPermission('uom.create')
  const canUpdate = useHasPermission('uom.update')
  const canDelete = useHasPermission('uom.delete')
  const list = useEntityListPage<Uom, UomFilterValues>({
    queryKey: 'uoms-paged',
    fetchList: fetchUomsPaged,
    deleteOne: deleteUom,
    applyFilters: applyUomFilters,
    emptyFilters: emptyUomFilters,
    sorters: SORTERS,
    deletedMessage: 'UOM deleted.',
  })

  const columns: DataTableColumn<Uom>[] = [
    { header: 'Name', accessor: (row) => row.name, sortKey: 'name' },
    { header: 'Symbol', accessor: (row) => row.symbol ?? '—' },
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
        title="Units of Measurement"
        description="Manage the UOMs used by the item catalog."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} UOMs` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New UOM', icon: Plus, onClick: list.openCreate } : undefined}
          />
        }
      />

      <SearchBox value={list.search} onChange={list.setSearch} placeholder="Search name or symbol…" />

      <DataTable
        columns={columns}
        data={list.rows}
        rowKey={(row) => row.id}
        isLoading={list.listQuery.isLoading}
        isError={list.listQuery.isError}
        onRetry={() => list.listQuery.refetch()}
        emptyMessage={list.search ? 'No UOMs match your search.' : 'No UOMs yet.'}
        onRowClick={(row) => list.setDetailItem(row)}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} />}

      <UomFormDrawer open={list.formOpen} onOpenChange={list.setFormOpen} uom={list.editingItem} />

      <UomDetailDrawer
        open={!!list.detailItem}
        onOpenChange={(open) => !open && list.setDetailItem(null)}
        uom={list.detailItem}
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
