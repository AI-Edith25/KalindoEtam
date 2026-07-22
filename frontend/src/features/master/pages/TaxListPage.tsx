import { Download, Eye, Pencil, Plus, Power, PowerOff, RotateCw, Upload } from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SearchBox } from '@/components/shared/SearchBox'
import { RowActionsMenu } from '@/components/shared/RowActionsMenu'
import { Pagination } from '@/components/shared/Pagination'
import { SectionNav } from '@/components/shared/SectionNav'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { useEntityListPage } from '@/shared/hooks/useEntityListPage'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatNumber } from '@/lib/utils'
import { deleteTax, fetchTaxesPaged, updateTax } from '../api/taxApi'
import { TaxFormDrawer } from '../components/TaxFormDrawer'
import { TaxDetailDrawer } from '../components/TaxDetailDrawer'
import { applyTaxFilters, emptyTaxFilters, type TaxFilterValues } from '../lib/taxFilters'
import { masterDataNav } from '../navigation'
import type { Tax } from '../types'

const TYPE_LABELS: Record<Tax['type'], string> = {
  vat: 'VAT',
  zero_rated: 'Zero Rated',
  exempt: 'Tax Exempt',
}

const SORTERS: Record<string, (t: Tax) => string | number> = {
  code: (t) => t.code,
  name: (t) => t.name,
}

/**
 * Activate/Deactivate is the primary lifecycle action (docs/TAX_ENGINE_DESIGN.md
 * §9) — Delete is deliberately not exposed here even though the backend
 * still supports it (guarded server-side against documents that already
 * reference the Tax); "prefer deactivation over deletion" per the approved design.
 */
export function TaxListPage() {
  const canCreate = useHasPermission('tax.create')
  const canUpdate = useHasPermission('tax.update')
  const queryClient = useQueryClient()

  const list = useEntityListPage<Tax, TaxFilterValues>({
    queryKey: 'taxes-paged',
    fetchList: fetchTaxesPaged,
    deleteOne: deleteTax,
    applyFilters: applyTaxFilters,
    emptyFilters: emptyTaxFilters,
    sorters: SORTERS,
  })

  const toggleActiveMutation = useMutation({
    mutationFn: (tax: Tax) => updateTax(tax.id, { code: tax.code, name: tax.name, type: tax.type, rate: Number(tax.rate), is_active: !tax.is_active }),
    onSuccess: (tax) => {
      queryClient.invalidateQueries({ queryKey: ['taxes-paged'] })
      toast.success(tax.is_active ? `${tax.name} activated.` : `${tax.name} deactivated.`)
    },
    onError: (error) => toastApiError(error),
  })

  const columns: DataTableColumn<Tax>[] = [
    { header: 'Code', accessor: (row) => row.code, sortKey: 'code' },
    { header: 'Name', accessor: (row) => row.name, sortKey: 'name' },
    { header: 'Type', accessor: (row) => TYPE_LABELS[row.type] },
    { header: 'Rate', accessor: (row) => (row.type === 'vat' ? `${row.rate}%` : '—'), className: 'text-right' },
    { header: 'Status', accessor: (row) => <StatusBadge status={row.is_active ? 'active' : 'inactive'} /> },
    {
      header: '',
      className: 'text-right',
      accessor: (row) => (
        <RowActionsMenu
          actions={[
            { label: 'View', icon: Eye, onClick: () => list.setDetailItem(row) },
            ...(canUpdate
              ? [
                  { label: 'Edit', icon: Pencil, onClick: () => list.openEdit(row) },
                  row.is_active
                    ? { label: 'Deactivate', icon: PowerOff, onClick: () => toggleActiveMutation.mutate(row) }
                    : { label: 'Activate', icon: Power, onClick: () => toggleActiveMutation.mutate(row) },
                ]
              : []),
          ]}
        />
      ),
    },
  ]

  return (
    <div className="flex flex-col gap-4">
      <SectionNav items={masterDataNav} />

      <PageHeader
        title="Taxes"
        description="Tax rates reused by Sales and Purchase — the single source of truth every invoice and purchase order's tax calculation goes through."
        count={list.listQuery.data?.meta ? `${formatNumber(list.listQuery.data.meta.total)} taxes` : undefined}
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: () => list.listQuery.refetch(), disabled: list.listQuery.isFetching },
              { label: 'Export', icon: Download, disabled: true },
              { label: 'Import', icon: Upload, disabled: true },
            ]}
            primary={canCreate ? { label: 'New Tax', icon: Plus, onClick: list.openCreate } : undefined}
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
        emptyMessage={list.search ? 'No taxes match your search.' : 'No taxes yet.'}
        onRowClick={(row) => list.setDetailItem(row)}
        sort={list.sort}
        onSortChange={list.handleSortChange}
      />

      {list.listQuery.data?.meta && <Pagination meta={list.listQuery.data.meta} onPageChange={list.setPage} />}

      <TaxFormDrawer open={list.formOpen} onOpenChange={list.setFormOpen} tax={list.editingItem} />

      <TaxDetailDrawer
        open={!!list.detailItem}
        onOpenChange={(open) => !open && list.setDetailItem(null)}
        tax={list.detailItem}
        onEdit={list.openEdit}
      />
    </div>
  )
}
