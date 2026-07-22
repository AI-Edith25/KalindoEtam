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
import { deleteWarehouse, fetchWarehouses } from '../api/warehouseApi'
import { useBranchesLookup } from '../hooks/useLookups'
import { WarehouseFormDrawer } from '../components/WarehouseFormDrawer'
import { WarehouseDetailDrawer } from '../components/WarehouseDetailDrawer'
import { WarehouseFiltersBar } from '../components/WarehouseFiltersBar'
import { applyWarehouseFilters, emptyWarehouseFilters, type WarehouseFilterValues } from '../lib/warehouseFilters'
import type { Warehouse } from '../types'

const SORTERS: Record<string, (w: Warehouse) => string | number> = {
  code: (w) => w.code,
  name: (w) => w.name,
}

export function WarehouseListPage() {
  const canCreate = useHasPermission('master.warehouses.create')
  const canUpdate = useHasPermission('master.warehouses.update')
  const canDelete = useHasPermission('master.warehouses.delete')
  const list = useEntityListPage<Warehouse, WarehouseFilterValues>({
    queryKey: 'warehouses',
    fetchList: fetchWarehouses,
    deleteOne: deleteWarehouse,
    applyFilters: applyWarehouseFilters,
    emptyFilters: emptyWarehouseFilters,
    sorters: SORTERS,
    deletedMessage: 'Warehouse deleted.',
  })

  const branches = useBranchesLookup()
  const branchName = (branchId: string) => branches.data?.find((b) => b.id === branchId)?.name ?? '—'

  const columns: DataTableColumn<Warehouse>[] = [
    { header: 'Code', accessor: (row) => row.code, sortKey: 'code' },
    { header: 'Name', accessor: (row) => row.name, sortKey: 'name' },
    { header: 'Branch', accessor: (row) => branchName(row.branch_id) },
    { header: 'Type', accessor: (row) => <StatusBadge status={row.warehouse_type} /> },
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
        title="Warehouses"
        description="Manage warehouses used across purchase receipts and sales deliveries."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} warehouses` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Warehouse', icon: Plus, onClick: list.openCreate } : undefined}
          />
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox value={list.search} onChange={list.setSearch} placeholder="Search code or name…" />
        <WarehouseFiltersBar value={list.filters} onChange={list.setFilters} />
      </div>

      <DataTable
        columns={columns}
        data={list.rows}
        rowKey={(row) => row.id}
        isLoading={list.listQuery.isLoading}
        isError={list.listQuery.isError}
        onRetry={() => list.listQuery.refetch()}
        emptyMessage={list.search || list.filters.warehouseType ? 'No warehouses match your search or filters.' : 'No warehouses yet.'}
        onRowClick={(row) => list.setDetailItem(row)}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} />}

      <WarehouseFormDrawer open={list.formOpen} onOpenChange={list.setFormOpen} warehouse={list.editingItem} />

      <WarehouseDetailDrawer
        open={!!list.detailItem}
        onOpenChange={(open) => !open && list.setDetailItem(null)}
        warehouse={list.detailItem}
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
