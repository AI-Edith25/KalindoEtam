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
import { formatCurrency, formatNumber } from '@/lib/utils'
import { deleteItem, fetchItems } from '../api/itemApi'
import { ItemFormDrawer } from '../components/ItemFormDrawer'
import { ItemDetailDrawer } from '../components/ItemDetailDrawer'
import { ItemFiltersBar } from '../components/ItemFiltersBar'
import { applyItemFilters, emptyItemFilters, type ItemFilterValues } from '../lib/itemFilters'
import { masterDataNav } from '../navigation'
import type { Item } from '../types'

const SORTERS: Record<string, (item: Item) => string | number> = {
  item_code: (i) => i.item_code,
  item_name: (i) => i.item_name,
  standard_rate: (i) => Number(i.standard_rate),
  current_stock: (i) => i.current_stock,
}

export function ItemListPage() {
  const canCreate = useHasPermission('item.create')
  const canUpdate = useHasPermission('item.update')
  const canDelete = useHasPermission('item.delete')
  const list = useEntityListPage<Item, ItemFilterValues>({
    queryKey: 'items',
    fetchList: fetchItems,
    deleteOne: deleteItem,
    applyFilters: applyItemFilters,
    emptyFilters: emptyItemFilters,
    sorters: SORTERS,
    deletedMessage: 'Item deleted.',
  })

  const columns: DataTableColumn<Item>[] = [
    { header: 'Code', accessor: (row) => row.item_code, sortKey: 'item_code' },
    { header: 'Name', accessor: (row) => row.item_name, sortKey: 'item_name' },
    { header: 'Group', accessor: (row) => row.item_group?.name ?? '—' },
    { header: 'UOM', accessor: (row) => row.uom?.name ?? '—' },
    {
      header: 'Standard Rate',
      accessor: (row) => formatCurrency(row.standard_rate),
      className: 'text-right',
      sortKey: 'standard_rate',
    },
    {
      header: 'Stock',
      accessor: (row) => formatNumber(row.current_stock),
      className: 'text-right',
      sortKey: 'current_stock',
    },
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
        title="Items"
        description="Manage the item catalog used across purchase, sales, and inventory."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} items` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Item', icon: Plus, onClick: list.openCreate } : undefined}
          />
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox value={list.search} onChange={list.setSearch} placeholder="Search code, name, or group…" />
        <ItemFiltersBar value={list.filters} onChange={list.setFilters} />
      </div>

      <DataTable
        columns={columns}
        data={list.rows}
        rowKey={(row) => row.id}
        isLoading={list.listQuery.isLoading}
        isError={list.listQuery.isError}
        onRetry={() => list.listQuery.refetch()}
        emptyMessage={list.search || list.filters.itemGroupId || list.filters.uomId ? 'No items match your search or filters.' : 'No items yet.'}
        onRowClick={(row) => list.setDetailItem(row)}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} />}

      <ItemFormDrawer open={list.formOpen} onOpenChange={list.setFormOpen} item={list.editingItem} />

      <ItemDetailDrawer
        open={!!list.detailItem}
        onOpenChange={(open) => !open && list.setDetailItem(null)}
        item={list.detailItem}
        onEdit={list.openEdit}
      />

      <DeleteDialog
        open={!!list.deletingItem}
        onOpenChange={(open) => !open && list.setDeletingItem(null)}
        itemLabel={list.deletingItem?.item_name}
        onConfirm={list.confirmDelete}
      />
    </div>
  )
}
