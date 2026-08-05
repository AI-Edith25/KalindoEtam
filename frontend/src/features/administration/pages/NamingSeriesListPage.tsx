import { Pencil, Plus, RotateCw, Trash2 } from 'lucide-react'
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
import { deleteNamingSeries, fetchNamingSeriesPaged } from '../api/namingSeriesApi'
import { NamingSeriesFormDrawer } from '../components/NamingSeriesFormDrawer'
import type { NamingSeries } from '../types'

/** No dedicated filters bar — the whole table is a handful of rows; search alone is enough. */
function applyNamingSeriesFilters(items: NamingSeries[], search: string): NamingSeries[] {
  const query = search.trim().toLowerCase()
  if (!query) return items

  return items.filter(
    (item) => item.document_type.toLowerCase().includes(query) || item.module.toLowerCase().includes(query),
  )
}

const SORTERS: Record<string, (item: NamingSeries) => string | number> = {
  module: (item) => item.module,
  document_type: (item) => item.document_type,
  current_number: (item) => item.current_number,
}

export function NamingSeriesListPage() {
  const canCreate = useHasPermission('administration.naming_series.create')
  const canUpdate = useHasPermission('administration.naming_series.update')
  const canDelete = useHasPermission('administration.naming_series.delete')

  const list = useEntityListPage<NamingSeries, Record<string, never>>({
    queryKey: 'naming-series',
    fetchList: fetchNamingSeriesPaged,
    deleteOne: deleteNamingSeries,
    applyFilters: (items, search) => applyNamingSeriesFilters(items, search),
    emptyFilters: {},
    sorters: SORTERS,
    deletedMessage: 'Naming series deleted.',
  })

  const columns: DataTableColumn<NamingSeries>[] = [
    { header: 'Module', accessor: (row) => row.module, sortKey: 'module' },
    { header: 'Document Type', accessor: (row) => row.document_type, sortKey: 'document_type' },
    { header: 'Prefix', accessor: (row) => row.prefix || '—' },
    { header: 'Suffix', accessor: (row) => row.suffix || '—' },
    { header: 'Digits', accessor: (row) => row.digit_length, className: 'text-right' },
    { header: 'Current No.', accessor: (row) => formatNumber(row.current_number), className: 'text-right', sortKey: 'current_number' },
    { header: 'Default', accessor: (row) => <StatusBadge status={row.is_default ? 'active' : 'inactive'} /> },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.is_active ? 'active' : 'inactive'} /> },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => (
        <RowActionsMenu
          actions={[
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
        title="Naming Series"
        description="The prefix/counter each document type generates its next number from — Goods and Transportation Invoices number independently here."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} series` : undefined}
        actions={
          <ActionBar
            actions={[{ label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching }]}
            primary={canCreate ? { label: 'New Series', icon: Plus, onClick: list.openCreate } : undefined}
          />
        }
      />

      <SearchBox value={list.search} onChange={list.setSearch} placeholder="Search module or document type…" />

      <DataTable
        columns={columns}
        data={list.rows}
        rowKey={(row) => row.id}
        isLoading={list.listQuery.isLoading}
        isError={list.listQuery.isError}
        onRetry={() => list.listQuery.refetch()}
        emptyMessage={list.search ? 'No naming series match your search.' : 'No naming series yet.'}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} />}

      <NamingSeriesFormDrawer open={list.formOpen} onOpenChange={list.setFormOpen} namingSeries={list.editingItem} />

      <DeleteDialog
        open={!!list.deletingItem}
        onOpenChange={(open) => !open && list.setDeletingItem(null)}
        itemLabel={list.deletingItem?.document_type}
        onConfirm={list.confirmDelete}
      />
    </div>
  )
}
