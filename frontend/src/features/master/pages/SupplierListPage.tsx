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
import { deleteSupplier, fetchSuppliers } from '../api/supplierApi'
import { SupplierFormDrawer } from '../components/SupplierFormDrawer'
import { SupplierDetailDrawer } from '../components/SupplierDetailDrawer'
import { SupplierFiltersBar } from '../components/SupplierFiltersBar'
import { applySupplierFilters, emptySupplierFilters, type SupplierFilterValues } from '../lib/supplierFilters'
import { masterDataNav } from '../navigation'
import type { Supplier } from '../types'

const SORTERS: Record<string, (s: Supplier) => string | number> = {
  supplier_code: (s) => s.supplier_code,
  supplier_name: (s) => s.supplier_name,
}

export function SupplierListPage() {
  const canCreate = useHasPermission('supplier.create')
  const canUpdate = useHasPermission('supplier.update')
  const canDelete = useHasPermission('supplier.delete')
  const list = useEntityListPage<Supplier, SupplierFilterValues>({
    queryKey: 'suppliers',
    fetchList: fetchSuppliers,
    deleteOne: deleteSupplier,
    applyFilters: applySupplierFilters,
    emptyFilters: emptySupplierFilters,
    sorters: SORTERS,
    deletedMessage: 'Supplier deleted.',
  })

  const columns: DataTableColumn<Supplier>[] = [
    { header: 'Code', accessor: (row) => row.supplier_code, sortKey: 'supplier_code' },
    { header: 'Name', accessor: (row) => row.supplier_name, sortKey: 'supplier_name' },
    { header: 'Phone', accessor: (row) => row.phone ?? '—' },
    { header: 'Email', accessor: (row) => row.email ?? '—' },
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
        title="Suppliers"
        description="Manage suppliers used across the purchase workflow."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} suppliers` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Supplier', icon: Plus, onClick: list.openCreate } : undefined}
          />
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox value={list.search} onChange={list.setSearch} placeholder="Search code, name, or email…" />
        <SupplierFiltersBar value={list.filters} onChange={list.setFilters} />
      </div>

      <DataTable
        columns={columns}
        data={list.rows}
        rowKey={(row) => row.id}
        isLoading={list.listQuery.isLoading}
        isError={list.listQuery.isError}
        onRetry={() => list.listQuery.refetch()}
        emptyMessage={list.search || list.filters.isActive !== null ? 'No suppliers match your search or filters.' : 'No suppliers yet.'}
        onRowClick={(row) => list.setDetailItem(row)}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} />}

      <SupplierFormDrawer open={list.formOpen} onOpenChange={list.setFormOpen} supplier={list.editingItem} />

      <SupplierDetailDrawer
        open={!!list.detailItem}
        onOpenChange={(open) => !open && list.setDetailItem(null)}
        supplier={list.detailItem}
        onEdit={list.openEdit}
      />

      <DeleteDialog
        open={!!list.deletingItem}
        onOpenChange={(open) => !open && list.setDeletingItem(null)}
        itemLabel={list.deletingItem?.supplier_name}
        onConfirm={list.confirmDelete}
      />
    </div>
  )
}
