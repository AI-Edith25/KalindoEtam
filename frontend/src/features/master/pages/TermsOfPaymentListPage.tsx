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
import { deleteTermsOfPayment, fetchTermsOfPayments } from '../api/termsOfPaymentApi'
import { TermsOfPaymentFormDrawer } from '../components/TermsOfPaymentFormDrawer'
import { TermsOfPaymentDetailDrawer } from '../components/TermsOfPaymentDetailDrawer'
import { applyTermsOfPaymentFilters, emptyTermsOfPaymentFilters, type TermsOfPaymentFilterValues } from '../lib/termsOfPaymentFilters'
import type { TermsOfPayment } from '../types'

const SORTERS: Record<string, (t: TermsOfPayment) => string | number> = {
  code: (t) => t.code,
  name: (t) => t.name,
  days: (t) => t.days,
}

export function TermsOfPaymentListPage() {
  const navigate = useNavigate()
  const canCreate = useHasPermission('master.terms_of_payment.create')
  const canUpdate = useHasPermission('master.terms_of_payment.update')
  const canDelete = useHasPermission('master.terms_of_payment.delete')
  const canImport = useHasPermission('master.terms_of_payments.import')
  const list = useEntityListPage<TermsOfPayment, TermsOfPaymentFilterValues>({
    queryKey: 'terms-of-payments',
    fetchList: fetchTermsOfPayments,
    deleteOne: deleteTermsOfPayment,
    applyFilters: applyTermsOfPaymentFilters,
    emptyFilters: emptyTermsOfPaymentFilters,
    sorters: SORTERS,
    deletedMessage: 'Terms of payment deleted.',
  })

  const columns: DataTableColumn<TermsOfPayment>[] = [
    { header: 'Code', accessor: (row) => row.code, sortKey: 'code' },
    { header: 'Name', accessor: (row) => row.name, sortKey: 'name' },
    { header: 'Days', accessor: (row) => formatNumber(row.days), className: 'text-right', sortKey: 'days' },
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
        title="Terms of Payment"
        description="Manage payment terms used to auto-calculate Due Date on Deliveries and Invoices."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} terms` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: !canImport, onClick: () => navigate('/master/terms-of-payment/quick-import') },
            ]}
            primary={canCreate ? { label: 'New Terms of Payment', icon: Plus, onClick: list.openCreate } : undefined}
          />
        }
      />

      <SearchBox value={list.search} onChange={list.setSearch} placeholder="Search code or name…" />

      <DataTable
        columns={columns}
        data={list.rows}
        rowKey={(row) => row.id}
        isLoading={list.listQuery.isLoading}
        isError={list.listQuery.isError}
        onRetry={() => list.listQuery.refetch()}
        emptyMessage={list.search ? 'No terms of payment match your search.' : 'No terms of payment yet.'}
        onRowClick={(row) => list.setDetailItem(row)}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} />}

      <TermsOfPaymentFormDrawer open={list.formOpen} onOpenChange={list.setFormOpen} termsOfPayment={list.editingItem} />

      <TermsOfPaymentDetailDrawer
        open={!!list.detailItem}
        onOpenChange={(open) => !open && list.setDetailItem(null)}
        termsOfPayment={list.detailItem}
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
