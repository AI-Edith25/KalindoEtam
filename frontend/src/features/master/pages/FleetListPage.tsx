import { Download, Eye, Pencil, Plus, RotateCw, Trash2 } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { PageSizeControl } from '@/components/shared/PageSizeControl'
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
import { deleteFleet, fetchFleets } from '../api/fleetApi'
import { FleetFormDrawer } from '../components/FleetFormDrawer'
import { FleetDetailDrawer } from '../components/FleetDetailDrawer'
import { applyFleetFilters, emptyFleetFilters, type FleetFilterValues } from '../lib/fleetFilters'
import type { Fleet } from '../types'

const SORTERS: Record<string, (f: Fleet) => string | number> = {
  plat_number: (f) => f.plat_number,
}

export function FleetListPage() {
  const canCreate = useHasPermission('master.fleets.create')
  const canUpdate = useHasPermission('master.fleets.update')
  const canDelete = useHasPermission('master.fleets.delete')
  const list = useEntityListPage<Fleet, FleetFilterValues>({
    queryKey: 'fleets',
    fetchList: fetchFleets,
    deleteOne: deleteFleet,
    applyFilters: applyFleetFilters,
    emptyFilters: emptyFleetFilters,
    sorters: SORTERS,
    deletedMessage: 'Fleet deleted.',
  })

  const columns: DataTableColumn<Fleet>[] = [
    { header: 'Plat Number', accessor: (row) => row.plat_number, sortKey: 'plat_number' },
    { header: 'Default Driver', accessor: (row) => row.default_driver_name ?? '—' },
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
      <SectionNav group="master" />

      <PageHeader
        title="Fleets"
        description="Manage the fleet (armada) used for Deliveries and driver commission tariffs."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} fleets` : undefined}
        actions={
          <>
            <PageSizeControl meta={list.listQuery.data?.meta} pageSize={list.pageSize} onPageSizeChange={list.setPageSize} />
            <ActionBar
              actions={[
                { label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching },
                { label: 'Export', icon: Download, disabled: true },
              ]}
              primary={canCreate ? { label: 'New Fleet', icon: Plus, onClick: list.openCreate } : undefined}
            />
          </>
        }
      />

      <SearchBox value={list.search} onChange={list.setSearch} placeholder="Search plat number or driver…" />

      <DataTable
        columns={columns}
        data={list.rows}
        rowKey={(row) => row.id}
        isLoading={list.listQuery.isLoading}
        isError={list.listQuery.isError}
        onRetry={() => list.listQuery.refetch()}
        emptyMessage={list.search ? 'No fleets match your search.' : 'No fleets yet.'}
        onRowClick={(row) => list.setDetailItem(row)}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} pageSize={list.pageSize} />}

      <FleetFormDrawer open={list.formOpen} onOpenChange={list.setFormOpen} fleet={list.editingItem} />

      <FleetDetailDrawer
        open={!!list.detailItem}
        onOpenChange={(open) => !open && list.setDetailItem(null)}
        fleet={list.detailItem}
        onEdit={list.openEdit}
      />

      <DeleteDialog
        open={!!list.deletingItem}
        onOpenChange={(open) => !open && list.setDeletingItem(null)}
        itemLabel={list.deletingItem?.plat_number}
        onConfirm={list.confirmDelete}
      />
    </div>
  )
}
