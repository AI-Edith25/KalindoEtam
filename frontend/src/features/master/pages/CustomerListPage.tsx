import { useState } from 'react'
import axios from 'axios'
import { useNavigate } from 'react-router-dom'
import { Download, Eye, Loader2, Pencil, Plus, RotateCw, Trash2, Upload } from 'lucide-react'
import { toast } from 'sonner'
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
import { downloadBlob } from '@/shared/lib/downloadBlob'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { deleteCustomer, exportCustomers, fetchCustomers } from '../api/customerApi'
import { CustomerFormDrawer } from '../components/CustomerFormDrawer'
import { CustomerDetailDrawer } from '../components/CustomerDetailDrawer'
import { CustomerFiltersBar } from '../components/CustomerFiltersBar'
import { applyCustomerFilters, emptyCustomerFilters, type CustomerFilterValues } from '../lib/customerFilters'
import type { Customer } from '../types'

const SORTERS: Record<string, (c: Customer) => string | number> = {
  customer_code: (c) => c.customer_code,
  customer_name: (c) => c.customer_name,
}

export function CustomerListPage() {
  const navigate = useNavigate()
  const canCreate = useHasPermission('master.customers.create')
  const canUpdate = useHasPermission('master.customers.update')
  const canDelete = useHasPermission('master.customers.delete')
  const canImport = useHasPermission('master.customers.import')
  const canExport = useHasPermission('master.customers.view')
  const [isExporting, setIsExporting] = useState(false)
  const list = useEntityListPage<Customer, CustomerFilterValues>({
    queryKey: 'customers',
    fetchList: fetchCustomers,
    deleteOne: deleteCustomer,
    applyFilters: applyCustomerFilters,
    emptyFilters: emptyCustomerFilters,
    sorters: SORTERS,
    deletedMessage: 'Customer deleted.',
  })

  const columns: DataTableColumn<Customer>[] = [
    { header: 'Code', accessor: (row) => row.customer_code, sortKey: 'customer_code' },
    { header: 'Name', accessor: (row) => row.customer_name, sortKey: 'customer_name' },
    { header: 'Phone', accessor: (row) => row.phone ?? '—' },
    { header: 'Telephone', accessor: (row) => row.telephone ?? '—' },
    { header: 'Email', accessor: (row) => row.email ?? '—' },
    { header: 'Credit Limit', accessor: (row) => (row.credit_limit != null ? formatCurrency(row.credit_limit) : 'No Limit') },
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

  const runExport = async () => {
    setIsExporting(true)
    try {
      const blob = await exportCustomers({
        search: list.search.trim() || undefined,
        is_active: list.filters.isActive ?? undefined,
      })
      const now = new Date()
      const pad = (value: number) => String(value).padStart(2, '0')
      const stamp = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}`
      downloadBlob(`xlsCustomerListing_${stamp}.xlsx`, blob)
      toast.success('Export started — check your downloads.')
    } catch (error) {
      // exportCustomers uses responseType: 'blob', so a JSON error body (e.g. the 10,000-row
      // cap message) arrives as an unparsed Blob rather than parsed JSON — read it back here
      // instead of calling toastApiError(), which can't see inside a Blob.
      let message = 'Failed to export customers.'
      if (axios.isAxiosError(error) && error.response?.data instanceof Blob) {
        try {
          const parsed = JSON.parse(await error.response.data.text())
          const firstFieldError = parsed?.errors ? Object.values(parsed.errors)[0] : undefined
          message = (Array.isArray(firstFieldError) ? firstFieldError[0] : undefined) ?? parsed?.message ?? message
        } catch {
          // keep the generic message
        }
      }
      toast.error(message)
    } finally {
      setIsExporting(false)
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="master" />

      <PageHeader
        title="Customers"
        description="Manage customers used across the sales workflow."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} customers` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching },
              {
                label: isExporting ? 'Exporting…' : 'Export',
                icon: isExporting ? Loader2 : Download,
                disabled: !canExport || isExporting,
                onClick: runExport,
              },
              { label: 'Import', icon: Upload, disabled: !canImport, onClick: () => navigate('/master/customers/quick-import') },
            ]}
            primary={canCreate ? { label: 'New Customer', icon: Plus, onClick: list.openCreate } : undefined}
          />
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <SearchBox value={list.search} onChange={list.setSearch} placeholder="Search code, name, or email…" />
        <CustomerFiltersBar value={list.filters} onChange={list.setFilters} />
      </div>

      <DataTable
        columns={columns}
        data={list.rows}
        rowKey={(row) => row.id}
        isLoading={list.listQuery.isLoading}
        isError={list.listQuery.isError}
        onRetry={() => list.listQuery.refetch()}
        emptyMessage={list.search || list.filters.isActive !== null ? 'No customers match your search or filters.' : 'No customers yet.'}
        onRowClick={(row) => list.setDetailItem(row)}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} />}

      <CustomerFormDrawer open={list.formOpen} onOpenChange={list.setFormOpen} customer={list.editingItem} />

      <CustomerDetailDrawer
        open={!!list.detailItem}
        onOpenChange={(open) => !open && list.setDetailItem(null)}
        customer={list.detailItem}
        onEdit={list.openEdit}
      />

      <DeleteDialog
        open={!!list.deletingItem}
        onOpenChange={(open) => !open && list.setDeletingItem(null)}
        itemLabel={list.deletingItem?.customer_name}
        onConfirm={list.confirmDelete}
      />
    </div>
  )
}
