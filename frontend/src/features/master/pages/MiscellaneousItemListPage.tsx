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
import { useEntityListPage } from '@/shared/hooks/useEntityListPage'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { formatNumber } from '@/lib/utils'
import { deleteMiscellaneousItem, fetchMiscellaneousItemsPaged } from '../api/miscellaneousItemApi'
import { MiscellaneousItemFormDrawer } from '../components/MiscellaneousItemFormDrawer'
import { MiscellaneousItemDetailDrawer } from '../components/MiscellaneousItemDetailDrawer'
import {
  applyMiscellaneousItemFilters,
  emptyMiscellaneousItemFilters,
  type MiscellaneousItemFilterValues,
} from '../lib/miscellaneousItemFilters'
import type { MiscellaneousChargeType, MiscellaneousItem } from '../types'

export const CHARGE_TYPE_LABELS: Record<MiscellaneousChargeType, string> = {
  addition: 'Addition (+)',
  deduction: 'Deduction (-)',
  addition_percent: 'Addition (+) (By %)',
  deduction_percent: 'Deduction (-) (By %)',
}

const SORTERS: Record<string, (m: MiscellaneousItem) => string | number> = {
  misc_code: (m) => m.misc_code,
}

export function MiscellaneousItemListPage() {
  const navigate = useNavigate()
  const canCreate = useHasPermission('master.miscellaneous.create')
  const canUpdate = useHasPermission('master.miscellaneous.update')
  const canDelete = useHasPermission('master.miscellaneous.delete')
  const canImport = useHasPermission('master.miscellaneous.import')
  const list = useEntityListPage<MiscellaneousItem, MiscellaneousItemFilterValues>({
    queryKey: 'miscellaneous-items-paged',
    fetchList: fetchMiscellaneousItemsPaged,
    deleteOne: deleteMiscellaneousItem,
    applyFilters: applyMiscellaneousItemFilters,
    emptyFilters: emptyMiscellaneousItemFilters,
    sorters: SORTERS,
    deletedMessage: 'Miscellaneous item deleted.',
  })

  const columns: DataTableColumn<MiscellaneousItem>[] = [
    { header: 'Misc Code', accessor: (row) => row.misc_code, sortKey: 'misc_code' },
    { header: 'Description', accessor: (row) => row.description },
    { header: 'Rate', className: 'text-right', accessor: (row) => formatNumber(Number(row.rate)) },
    { header: 'UOM', accessor: (row) => (row.uom ? `${row.uom.name}${row.uom.symbol ? ` (${row.uom.symbol})` : ''}` : '—') },
    { header: 'Charge Type', accessor: (row) => CHARGE_TYPE_LABELS[row.charge_type] },
    { header: 'Unit Cost', className: 'text-right', accessor: (row) => formatNumber(Number(row.unit_cost)) },
    { header: 'Sales Account', accessor: (row) => (row.sales_account ? `${row.sales_account.code} — ${row.sales_account.name}` : '—') },
    { header: 'Purchase Account', accessor: (row) => (row.purchase_account ? `${row.purchase_account.code} — ${row.purchase_account.name}` : '—') },
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
        title="Miscellaneous"
        description="Manage miscellaneous charge items — service-type costs that never affect stock."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} items` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: !canImport, onClick: () => navigate('/master/miscellaneous/quick-import') },
            ]}
            primary={canCreate ? { label: 'New Miscellaneous', icon: Plus, onClick: list.openCreate } : undefined}
          />
        }
      />

      <SearchBox value={list.search} onChange={list.setSearch} placeholder="Search misc code or description…" />

      <DataTable
        columns={columns}
        data={list.rows}
        rowKey={(row) => row.id}
        isLoading={list.listQuery.isLoading}
        isError={list.listQuery.isError}
        onRetry={() => list.listQuery.refetch()}
        emptyMessage={list.search ? 'No miscellaneous items match your search.' : 'No miscellaneous items yet.'}
        onRowClick={(row) => list.setDetailItem(row)}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} />}

      <MiscellaneousItemFormDrawer open={list.formOpen} onOpenChange={list.setFormOpen} miscellaneousItem={list.editingItem} />

      <MiscellaneousItemDetailDrawer
        open={!!list.detailItem}
        onOpenChange={(open) => !open && list.setDetailItem(null)}
        miscellaneousItem={list.detailItem}
        onEdit={list.openEdit}
      />

      <DeleteDialog
        open={!!list.deletingItem}
        onOpenChange={(open) => !open && list.setDeletingItem(null)}
        itemLabel={list.deletingItem?.misc_code}
        onConfirm={list.confirmDelete}
      />
    </div>
  )
}
