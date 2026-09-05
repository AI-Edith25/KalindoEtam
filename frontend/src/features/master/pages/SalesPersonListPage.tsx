import { useNavigate } from 'react-router-dom'
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
import { deleteSalesPerson, fetchSalesPersons } from '../api/salesPersonApi'
import { SalesPersonFormDrawer } from '../components/SalesPersonFormDrawer'
import { SalesPersonDetailDrawer } from '../components/SalesPersonDetailDrawer'
import { SalesPersonFiltersBar } from '../components/SalesPersonFiltersBar'
import { applySalesPersonFilters, emptySalesPersonFilters, type SalesPersonFilterValues } from '../lib/salesPersonFilters'
import type { SalesPerson } from '../types'

const SORTERS: Record<string, (s: SalesPerson) => string | number> = {
  code: (s) => s.code,
  name: (s) => s.name,
}

export function SalesPersonListPage() {
  const navigate = useNavigate()
  const canCreate = useHasPermission('master.sales_persons.create')
  const canUpdate = useHasPermission('master.sales_persons.update')
  const canDelete = useHasPermission('master.sales_persons.delete')
  const canImport = useHasPermission('master.sales_persons.import')
  const list = useEntityListPage<SalesPerson, SalesPersonFilterValues>({
    queryKey: 'sales-persons',
    fetchList: fetchSalesPersons,
    deleteOne: deleteSalesPerson,
    applyFilters: applySalesPersonFilters,
    emptyFilters: emptySalesPersonFilters,
    sorters: SORTERS,
    deletedMessage: 'Sales person deleted.',
  })

  const columns: DataTableColumn<SalesPerson>[] = [
    { header: 'Code', accessor: (row) => row.code, sortKey: 'code' },
    { header: 'Name', accessor: (row) => row.name, sortKey: 'name' },
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
      <SectionNav group="master" />

      <PageHeader
        title="Sales Persons"
        description="Manage the salespeople attributed to Sales Orders."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} sales persons` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: !canImport, onClick: () => navigate('/master/sales-persons/quick-import') },
            ]}
            primary={canCreate ? { label: 'New Sales Person', icon: Plus, onClick: list.openCreate } : undefined}
          />
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox value={list.search} onChange={list.setSearch} placeholder="Search code, name, or email…" />
        <SalesPersonFiltersBar value={list.filters} onChange={list.setFilters} />
      </div>

      <DataTable
        columns={columns}
        data={list.rows}
        rowKey={(row) => row.id}
        isLoading={list.listQuery.isLoading}
        isError={list.listQuery.isError}
        onRetry={() => list.listQuery.refetch()}
        emptyMessage={list.search || list.filters.isActive !== null ? 'No sales persons match your search or filters.' : 'No sales persons yet.'}
        onRowClick={(row) => list.setDetailItem(row)}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} />}

      <SalesPersonFormDrawer open={list.formOpen} onOpenChange={list.setFormOpen} salesPerson={list.editingItem} />

      <SalesPersonDetailDrawer
        open={!!list.detailItem}
        onOpenChange={(open) => !open && list.setDetailItem(null)}
        salesPerson={list.detailItem}
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
