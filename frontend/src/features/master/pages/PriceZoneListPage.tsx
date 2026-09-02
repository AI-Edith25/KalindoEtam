import { Eye, Pencil, Plus, RotateCw, Trash2 } from 'lucide-react'
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
import { deletePriceZone, fetchPriceZonesPaged } from '../api/priceZoneApi'
import { PriceZoneFormDrawer } from '../components/PriceZoneFormDrawer'
import { PriceZoneDetailDrawer } from '../components/PriceZoneDetailDrawer'
import { applyPriceZoneFilters, emptyPriceZoneFilters, type PriceZoneFilterValues } from '../lib/priceZoneFilters'
import type { PriceZone } from '../types'

const SORTERS: Record<string, (z: PriceZone) => string | number> = {
  name: (z) => z.name,
}

export function PriceZoneListPage() {
  const canCreate = useHasPermission('master.price_zones.create')
  const canUpdate = useHasPermission('master.price_zones.update')
  const canDelete = useHasPermission('master.price_zones.delete')
  const list = useEntityListPage<PriceZone, PriceZoneFilterValues>({
    queryKey: 'price-zones-paged',
    fetchList: fetchPriceZonesPaged,
    deleteOne: deletePriceZone,
    applyFilters: applyPriceZoneFilters,
    emptyFilters: emptyPriceZoneFilters,
    sorters: SORTERS,
    deletedMessage: 'Price Zone deleted.',
  })

  const columns: DataTableColumn<PriceZone>[] = [
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
      <SectionNav group="master" />

      <PageHeader
        title="Price Zones"
        description="Sales price zones used to charge a different rate by delivery region — separate from the Area/warehouse list."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} price zones` : undefined}
        actions={
          <ActionBar
            actions={[{ label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching }]}
            primary={canCreate ? { label: 'New Price Zone', icon: Plus, onClick: list.openCreate } : undefined}
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
        emptyMessage={list.search ? 'No price zones match your search.' : 'No price zones yet.'}
        onRowClick={(row) => list.setDetailItem(row)}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} />}

      <PriceZoneFormDrawer open={list.formOpen} onOpenChange={list.setFormOpen} priceZone={list.editingItem} />

      <PriceZoneDetailDrawer
        open={!!list.detailItem}
        onOpenChange={(open) => !open && list.setDetailItem(null)}
        priceZone={list.detailItem}
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
