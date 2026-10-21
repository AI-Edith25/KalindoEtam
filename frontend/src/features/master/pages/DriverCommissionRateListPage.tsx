import { useState } from 'react'
import { useQuery, useQueryClient, useMutation } from '@tanstack/react-query'
import { Plus, RotateCw, Trash2 } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { SectionNav } from '@/components/shared/SectionNav'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { toastApiError } from '@/shared/services/errorHandler'
import { toast } from 'sonner'
import { formatCurrency, formatDate } from '@/lib/utils'
import { fetchActiveFleetsLookup } from '../api/fleetApi'
import { deleteDriverCommissionRate, fetchDriverCommissionRates } from '../api/driverCommissionRateApi'
import { DriverCommissionRateFormDialog } from '../components/DriverCommissionRateFormDialog'
import type { DriverCommissionRate } from '../types'

export function DriverCommissionRateListPage() {
  const canCreate = useHasPermission('master.driver_commission_rates.create')
  const canDelete = useHasPermission('master.driver_commission_rates.delete')
  const queryClient = useQueryClient()

  const [fleetId, setFleetId] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [deletingRate, setDeletingRate] = useState<DriverCommissionRate | null>(null)

  const fleets = useQuery({ queryKey: ['fleets-lookup-active'], queryFn: fetchActiveFleetsLookup })

  const ratesQuery = useQuery({
    queryKey: ['driver-commission-rates', fleetId],
    queryFn: () => fetchDriverCommissionRates(fleetId),
    enabled: !!fleetId,
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteDriverCommissionRate(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['driver-commission-rates', fleetId] })
      toast.success('Commission rate deleted.')
      setDeletingRate(null)
    },
    onError: (error) => toastApiError(error),
  })

  const columns: DataTableColumn<DriverCommissionRate>[] = [
    { header: 'Item', accessor: (row) => row.item_name ?? '—' },
    { header: 'Effective Date', accessor: (row) => formatDate(row.effective_date) },
    { header: 'Rate / Zak', accessor: (row) => formatCurrency(row.rate_per_zak), className: 'text-right' },
    {
      header: '',
      className: 'text-right',
      accessor: (row) =>
        canDelete ? (
          <button
            type="button"
            className="inline-flex items-center gap-1 text-sm text-destructive hover:underline"
            onClick={(event) => {
              event.stopPropagation()
              setDeletingRate(row)
            }}
          >
            <Trash2 className="size-3.5" />
            Delete
          </button>
        ) : null,
    },
  ]

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="master" />

      <PageHeader
        title="Driver Commission Rates"
        description="Per-fleet, per-item commission tariff history. Adding a new rate never overwrites past history — see the effective date of each entry."
        actions={
          <ActionBar
            actions={[{ label: 'Refresh', icon: RotateCw, onClick: () => ratesQuery.refetch(), disabled: ratesQuery.isFetching || !fleetId }]}
            primary={canCreate && fleetId ? { label: 'Add Rate', icon: Plus, onClick: () => setFormOpen(true) } : undefined}
          />
        }
      />

      <div className="flex flex-col gap-1.5 sm:max-w-xs">
        <span className="text-xs text-muted-foreground">Fleet</span>
        <SearchableSelect
          options={(fleets.data ?? []).map((fleet) => ({ value: fleet.id, label: fleet.plat_number }))}
          value={fleetId || undefined}
          onChange={(value) => setFleetId(value ?? '')}
          loading={fleets.isLoading}
          placeholder="Select a fleet to view its tariff history"
          aria-label="Fleet"
        />
      </div>

      {fleetId && (
        <DataTable
          columns={columns}
          data={ratesQuery.data ?? []}
          rowKey={(row) => row.id}
          isLoading={ratesQuery.isLoading}
          isError={ratesQuery.isError}
          onRetry={() => ratesQuery.refetch()}
          emptyMessage="No commission rates registered for this fleet yet."
        />
      )}

      {fleetId && <DriverCommissionRateFormDialog open={formOpen} onOpenChange={setFormOpen} fleetId={fleetId} />}

      <DeleteDialog
        open={!!deletingRate}
        onOpenChange={(open) => !open && setDeletingRate(null)}
        itemLabel={deletingRate?.item_name ?? undefined}
        onConfirm={() => {
          if (deletingRate) deleteMutation.mutate(deletingRate.id)
        }}
      />
    </div>
  )
}
