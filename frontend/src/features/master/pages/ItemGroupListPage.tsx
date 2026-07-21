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
import { deleteItemGroup, fetchItemGroupsPaged } from '../api/itemGroupApi'
import { ItemGroupFormDrawer } from '../components/ItemGroupFormDrawer'
import { ItemGroupDetailDrawer } from '../components/ItemGroupDetailDrawer'
import { applyItemGroupFilters, emptyItemGroupFilters, type ItemGroupFilterValues } from '../lib/itemGroupFilters'
import { masterDataNav } from '../navigation'
import type { ItemGroup } from '../types'

const SORTERS: Record<string, (g: ItemGroup) => string | number> = {
  name: (g) => g.name,
}

export function ItemGroupListPage() {
  const canCreate = useHasPermission('item_group.create')
  const canUpdate = useHasPermission('item_group.update')
  const canDelete = useHasPermission('item_group.delete')
  const list = useEntityListPage<ItemGroup, ItemGroupFilterValues>({
    queryKey: 'item-groups-paged',
    fetchList: fetchItemGroupsPaged,
    deleteOne: deleteItemGroup,
    applyFilters: applyItemGroupFilters,
    emptyFilters: emptyItemGroupFilters,
    sorters: SORTERS,
    deletedMessage: 'Item Group deleted.',
  })

  const columns: DataTableColumn<ItemGroup>[] = [
    { header: 'Name', accessor: (row) => row.name, sortKey: 'name' },
    { header: 'Description', accessor: (row) => row.description ?? '—' },
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
        title="Item Groups"
        description="Manage the item groups used to categorize the item catalog."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} item groups` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Item Group', icon: Plus, onClick: list.openCreate } : undefined}
          />
        }
      />

      <SearchBox value={list.search} onChange={list.setSearch} placeholder="Search name or description…" />

      <DataTable
        columns={columns}
        data={list.rows}
        rowKey={(row) => row.id}
        isLoading={list.listQuery.isLoading}
        isError={list.listQuery.isError}
        onRetry={() => list.listQuery.refetch()}
        emptyMessage={list.search ? 'No item groups match your search.' : 'No item groups yet.'}
        onRowClick={(row) => list.setDetailItem(row)}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} />}

      <ItemGroupFormDrawer open={list.formOpen} onOpenChange={list.setFormOpen} itemGroup={list.editingItem} />

      <ItemGroupDetailDrawer
        open={!!list.detailItem}
        onOpenChange={(open) => !open && list.setDetailItem(null)}
        itemGroup={list.detailItem}
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
