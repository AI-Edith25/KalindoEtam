import { useEffect, useMemo, useRef, useState, type ChangeEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, RotateCw, Upload } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { Pagination } from '@/components/shared/Pagination'
import { SectionNav } from '@/components/shared/SectionNav'
import { Input } from '@/components/ui/input'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency } from '@/lib/utils'
import { fetchItems } from '../api/itemApi'
import { fetchPriceZonesLookup } from '../api/lookupsApi'
import { createItemPrice, deleteItemPrice, downloadItemPricesExport, fetchItemPrices, importItemPrices, updateItemPrice } from '../api/itemPriceApi'
import type { Item, ItemPrice, PriceZone } from '../types'

interface PriceCellProps {
  item: Item
  zone: PriceZone
  override?: ItemPrice
  onSaved: () => void
}

/** One item x zone cell — blank means "use Standard Rate" (shown as the placeholder), typing a value creates/updates the override, clearing it back to blank deletes it. */
function PriceCell({ item, zone, override, onSaved }: PriceCellProps) {
  const [value, setValue] = useState(override ? String(override.rate) : '')

  useEffect(() => {
    setValue(override ? String(override.rate) : '')
  }, [override?.rate])

  const saveMutation = useMutation({
    mutationFn: async () => {
      const trimmed = value.trim()

      if (trimmed === '') {
        if (override) await deleteItemPrice(override.id)
        return
      }

      const rate = Number(trimmed)
      if (override) {
        await updateItemPrice(override.id, rate)
      } else {
        await createItemPrice({ item_id: item.id, price_zone_id: zone.id, rate })
      }
    },
    onSuccess: onSaved,
    onError: (error) => {
      toastApiError(error)
      setValue(override ? String(override.rate) : '')
    },
  })

  const handleBlur = () => {
    const current = override ? String(override.rate) : ''
    if (value.trim() === current.trim()) return
    saveMutation.mutate()
  }

  return (
    <Input
      type="number"
      min={0}
      step="0.01"
      placeholder={String(item.standard_rate)}
      value={value}
      onChange={(e) => setValue(e.target.value)}
      onBlur={handleBlur}
      disabled={saveMutation.isPending}
      className="w-28"
    />
  )
}

export function ItemPriceMatrixPage() {
  const canUpdate = useHasPermission('master.item_prices.update')
  const canImport = useHasPermission('master.item_prices.import')
  const [page, setPage] = useState(1)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const queryClient = useQueryClient()

  const itemsQuery = useQuery({ queryKey: ['items-paged-for-prices', page], queryFn: () => fetchItems(page) })
  const zonesQuery = useQuery({ queryKey: ['price-zones-lookup'], queryFn: fetchPriceZonesLookup })
  const pricesQuery = useQuery({ queryKey: ['item-prices'], queryFn: fetchItemPrices })

  const priceMap = useMemo(() => {
    const map = new Map<string, ItemPrice>()
    for (const price of pricesQuery.data ?? []) {
      map.set(`${price.item_id}:${price.price_zone_id}`, price)
    }
    return map
  }, [pricesQuery.data])

  const invalidatePrices = () => queryClient.invalidateQueries({ queryKey: ['item-prices'] })

  const importMutation = useMutation({
    mutationFn: importItemPrices,
    onSuccess: (summary) => {
      invalidatePrices()
      toast.success(`Import finished: ${summary.created} created, ${summary.updated} updated.`)
      if (summary.skipped.length > 0) {
        toast.warning(`${summary.skipped.length} row(s) skipped — e.g. row ${summary.skipped[0].row}: ${summary.skipped[0].reason}`)
      }
    },
    onError: (error) => toastApiError(error),
  })

  const handleFileChange = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    if (file) importMutation.mutate(file)
    event.target.value = ''
  }

  const zones = zonesQuery.data ?? []
  const items = itemsQuery.data?.data ?? []

  const columns: DataTableColumn<Item>[] = [
    {
      header: 'Item',
      accessor: (row) => (
        <div>
          <div className="font-medium">{row.item_code}</div>
          <div className="text-xs text-muted-foreground">{row.item_name}</div>
        </div>
      ),
    },
    { header: 'Standard Rate', className: 'text-right', accessor: (row) => formatCurrency(Number(row.standard_rate)) },
    ...zones.map((zone) => ({
      header: zone.name,
      id: `zone-${zone.id}`,
      accessor: (row: Item) =>
        canUpdate ? (
          <PriceCell item={row} zone={zone} override={priceMap.get(`${row.id}:${zone.id}`)} onSaved={invalidatePrices} />
        ) : (
          (priceMap.get(`${row.id}:${zone.id}`)?.rate ?? '—')
        ),
    })),
  ]

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="master" />

      <PageHeader
        title="Item Prices"
        description="Per-zone sale price overrides — an empty cell falls back to the item's Standard Rate."
        actions={
          <ActionBar
            actions={[
              {
                label: 'Refresh',
                icon: RotateCw,
                onClick: () => {
                  itemsQuery.refetch()
                  pricesQuery.refetch()
                },
                disabled: itemsQuery.isFetching || pricesQuery.isFetching,
              },
              { label: 'Export', icon: Download, onClick: () => downloadItemPricesExport() },
              {
                label: 'Import',
                icon: Upload,
                disabled: !canImport || importMutation.isPending,
                onClick: () => fileInputRef.current?.click(),
              },
            ]}
          />
        }
      />

      <input ref={fileInputRef} type="file" accept=".csv,.xlsx,.xls" className="hidden" onChange={handleFileChange} />

      {zones.length === 0 && !zonesQuery.isLoading && (
        <p className="text-sm text-muted-foreground">No price zones yet — create one under Maintenance &gt; Price Zones first.</p>
      )}

      <DataTable
        columns={columns}
        data={items}
        rowKey={(row) => row.id}
        isLoading={itemsQuery.isLoading || zonesQuery.isLoading}
        isError={itemsQuery.isError}
        onRetry={() => itemsQuery.refetch()}
        emptyMessage="No items yet."
      />

      {itemsQuery.data?.meta && <Pagination meta={itemsQuery.data.meta} onPageChange={setPage} />}
    </div>
  )
}
