import { useEffect, useMemo, useRef, useState, type ChangeEvent, type KeyboardEvent, type ClipboardEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Check, Download, Loader2, RotateCw, Upload, X } from 'lucide-react'
import { PageHeader } from '@/components/shared/PageHeader'
import { ActionBar } from '@/components/shared/ActionBar'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { Pagination } from '@/components/shared/Pagination'
import { SectionNav } from '@/components/shared/SectionNav'
import { SearchBox } from '@/components/shared/SearchBox'
import { Input } from '@/components/ui/input'
import { Checkbox } from '@/components/ui/checkbox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency } from '@/lib/utils'
import { fetchItemsForPriceMatrix } from '../api/itemApi'
import { fetchPriceZonesLookup, fetchWarehousesLookup, fetchItemGroups } from '../api/lookupsApi'
import { createItemPrice, deleteItemPrice, downloadItemPricesExport, fetchItemPrices, importItemPrices, updateItemPrice } from '../api/itemPriceApi'
import {
  bulkSetSyncToMainWh,
  bulkUpdateItemWarehousePrices,
  commitItemWarehousePricesImport,
  downloadItemWarehousePricesExport,
  fetchItemWarehousePrices,
  previewItemWarehousePricesImport,
  type ItemWarehousePriceImportPreview,
} from '../api/itemWarehousePriceApi'
import type { Item, ItemPrice, ItemWarehousePrice, ItemWarehousePriceCell, PriceZone, Warehouse } from '../types'

const PER_PAGE = 50

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

type CellStatus = 'saving' | 'saved' | 'error'

interface WarehousePriceCellProps {
  item: Item
  warehouse: Warehouse
  rowIndex: number
  colIndex: number
  displayValue: string
  status?: CellStatus
  readOnlyResolvedValue?: string
  onChange: (itemId: string, warehouseId: string, raw: string) => void
  onPasteBlock: (rowIndex: number, colIndex: number, text: string) => void
  focusCell: (rowIndex: number, colIndex: number) => void
}

/** One item x warehouse cell. Blank falls back to Standard Rate (placeholder). When the item's "Sync to Main WH" is on and this isn't the Main column, the cell is disabled and shows the Main warehouse's own resolved price — never physically copied here. */
function WarehousePriceCell({
  item,
  warehouse,
  rowIndex,
  colIndex,
  displayValue,
  status,
  readOnlyResolvedValue,
  onChange,
  onPasteBlock,
  focusCell,
}: WarehousePriceCellProps) {
  const disabled = readOnlyResolvedValue !== undefined

  const handleKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    const input = e.currentTarget
    if (e.key === 'ArrowDown' || (e.key === 'Enter' && !e.shiftKey)) {
      e.preventDefault()
      focusCell(rowIndex + 1, colIndex)
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      focusCell(rowIndex - 1, colIndex)
    } else if (e.key === 'ArrowLeft' && input.selectionStart === 0) {
      e.preventDefault()
      focusCell(rowIndex, colIndex - 1)
    } else if (e.key === 'ArrowRight' && input.selectionEnd === input.value.length) {
      e.preventDefault()
      focusCell(rowIndex, colIndex + 1)
    }
  }

  const handlePaste = (e: ClipboardEvent<HTMLInputElement>) => {
    const text = e.clipboardData.getData('text/plain')
    if (!text.includes('\t') && !text.includes('\n')) return // single value — let the normal paste + onChange handle it
    e.preventDefault()
    onPasteBlock(rowIndex, colIndex, text)
  }

  return (
    <div className="flex items-center gap-1.5">
      <Input
        id={`whcell-${rowIndex}-${colIndex}`}
        type={disabled ? 'text' : 'number'}
        min={0}
        step="0.01"
        placeholder={String(item.standard_rate)}
        value={disabled ? (readOnlyResolvedValue ?? '') : displayValue}
        onChange={(e) => onChange(item.id, warehouse.id, e.target.value)}
        onKeyDown={handleKeyDown}
        onPaste={handlePaste}
        disabled={disabled}
        className="w-28"
      />
      {status === 'saving' && <Loader2 className="size-3.5 shrink-0 animate-spin text-muted-foreground" />}
      {status === 'saved' && <Check className="size-3.5 shrink-0 text-green-600" />}
      {status === 'error' && <X className="size-3.5 shrink-0 text-destructive" />}
    </div>
  )
}

export function ItemPriceMatrixPage() {
  const canUpdate = useHasPermission('master.item_prices.update')
  const canImport = useHasPermission('master.item_prices.import')
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [itemGroupId, setItemGroupId] = useState('')
  const fileInputRef = useRef<HTMLInputElement>(null)
  const whFileInputRef = useRef<HTMLInputElement>(null)
  const queryClient = useQueryClient()

  const [cellValues, setCellValues] = useState<Map<string, string>>(new Map())
  const [cellStatus, setCellStatus] = useState<Map<string, CellStatus>>(new Map())
  const pendingRef = useRef<Map<string, ItemWarehousePriceCell>>(new Map())
  const flushTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)

  const [importFile, setImportFile] = useState<File | null>(null)
  const [importPreview, setImportPreview] = useState<ItemWarehousePriceImportPreview | null>(null)

  useEffect(() => setPage(1), [search, itemGroupId])

  const itemsQuery = useQuery({
    queryKey: ['items-for-price-matrix', page, search, itemGroupId],
    queryFn: () => fetchItemsForPriceMatrix({ page, per_page: PER_PAGE, search: search || undefined, item_group_id: itemGroupId || undefined }),
  })
  const zonesQuery = useQuery({ queryKey: ['price-zones-lookup'], queryFn: fetchPriceZonesLookup })
  const pricesQuery = useQuery({ queryKey: ['item-prices'], queryFn: fetchItemPrices })
  const warehousesQuery = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup })
  const warehousePricesQuery = useQuery({ queryKey: ['item-warehouse-prices'], queryFn: fetchItemWarehousePrices })
  const itemGroupsQuery = useQuery({ queryKey: ['item-groups-lookup'], queryFn: fetchItemGroups })

  const priceMap = useMemo(() => {
    const map = new Map<string, ItemPrice>()
    for (const price of pricesQuery.data ?? []) {
      map.set(`${price.item_id}:${price.price_zone_id}`, price)
    }
    return map
  }, [pricesQuery.data])

  const whMap = useMemo(() => {
    const map = new Map<string, ItemWarehousePrice>()
    for (const price of warehousePricesQuery.data ?? []) {
      map.set(`${price.item_id}:${price.warehouse_id}`, price)
    }
    return map
  }, [warehousePricesQuery.data])

  const zones = zonesQuery.data ?? []
  const warehouses = warehousesQuery.data ?? []
  const items = itemsQuery.data?.data ?? []
  const mainWarehouse = warehouses.find((w) => w.warehouse_type === 'main')

  const invalidatePrices = () => queryClient.invalidateQueries({ queryKey: ['item-prices'] })
  const invalidateWarehousePrices = () => queryClient.invalidateQueries({ queryKey: ['item-warehouse-prices'] })
  const invalidateItems = () => queryClient.invalidateQueries({ queryKey: ['items-for-price-matrix'] })

  const bulkMutation = useMutation({
    mutationFn: bulkUpdateItemWarehousePrices,
    onSuccess: (results) => {
      setCellStatus((prev) => {
        const next = new Map(prev)
        for (const r of results) next.set(`${r.item_id}:${r.warehouse_id}`, r.status)
        return next
      })
      invalidateWarehousePrices()
    },
    onError: (error, cells) => {
      toastApiError(error)
      setCellStatus((prev) => {
        const next = new Map(prev)
        for (const c of cells) next.set(`${c.item_id}:${c.warehouse_id}`, 'error')
        return next
      })
      // Revert to server truth for the cells that failed to save.
      setCellValues((prev) => {
        const next = new Map(prev)
        for (const c of cells) next.delete(`${c.item_id}:${c.warehouse_id}`)
        return next
      })
    },
  })

  const flush = () => {
    clearTimeout(flushTimer.current)
    const cells = Array.from(pendingRef.current.values())
    if (cells.length === 0) return
    pendingRef.current.clear()

    setCellStatus((prev) => {
      const next = new Map(prev)
      for (const c of cells) next.set(`${c.item_id}:${c.warehouse_id}`, 'saving')
      return next
    })
    bulkMutation.mutate(cells)
  }

  const queueChange = (itemId: string, warehouseId: string, raw: string) => {
    const key = `${itemId}:${warehouseId}`
    setCellValues((prev) => new Map(prev).set(key, raw))

    const trimmed = raw.trim()
    const rate = trimmed === '' ? null : Number(trimmed)
    if (rate !== null && (Number.isNaN(rate) || rate < 0)) return // invalid — don't queue, don't save

    pendingRef.current.set(key, { item_id: itemId, warehouse_id: warehouseId, rate })
    clearTimeout(flushTimer.current)
    flushTimer.current = setTimeout(flush, 700)
  }

  const getDisplayValue = (item: Item, warehouse: Warehouse): string => {
    const key = `${item.id}:${warehouse.id}`
    if (cellValues.has(key)) return cellValues.get(key)!
    const override = whMap.get(key)
    return override ? String(override.rate) : ''
  }

  const focusCell = (rowIndex: number, colIndex: number) => {
    if (rowIndex < 0 || rowIndex >= items.length || colIndex < 0 || colIndex >= warehouses.length) return
    document.getElementById(`whcell-${rowIndex}-${colIndex}`)?.focus()
  }

  const handlePasteBlock = (rowIndex: number, colIndex: number, text: string) => {
    const lines = text.replace(/\r/g, '').split('\n').filter((l, i, arr) => !(i === arr.length - 1 && l === ''))

    lines.forEach((line, rOffset) => {
      const cells = line.split('\t')
      cells.forEach((raw, cOffset) => {
        const targetRow = rowIndex + rOffset
        const targetCol = colIndex + cOffset
        if (targetRow >= items.length || targetCol >= warehouses.length) return

        const targetItem = items[targetRow]
        const targetWarehouse = warehouses[targetCol]
        if (targetItem.sync_to_main_wh && targetWarehouse.warehouse_type !== 'main') return // disabled cell — skip

        queueChange(targetItem.id, targetWarehouse.id, raw.trim())
      })
    })
  }

  const syncMutation = useMutation({
    mutationFn: ({ itemIds, value }: { itemIds: string[]; value: boolean }) => bulkSetSyncToMainWh(itemIds, value),
    onSuccess: invalidateItems,
    onError: (error) => toastApiError(error),
  })

  const invalidateAll = () => {
    itemsQuery.refetch()
    pricesQuery.refetch()
    warehousePricesQuery.refetch()
    warehousesQuery.refetch()
  }

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

  const previewMutation = useMutation({
    mutationFn: previewItemWarehousePricesImport,
    onSuccess: setImportPreview,
    onError: (error) => {
      toastApiError(error)
      setImportFile(null)
    },
  })

  const commitMutation = useMutation({
    mutationFn: commitItemWarehousePricesImport,
    onSuccess: (summary) => {
      invalidateWarehousePrices()
      invalidateItems()
      toast.success(`Import applied: ${summary.cells_applied} price(s), ${summary.sync_changes} sync flag(s) changed.`)
      setImportFile(null)
      setImportPreview(null)
    },
    onError: (error) => toastApiError(error),
  })

  const handleWhFileChange = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return
    setImportFile(file)
    previewMutation.mutate(file)
  }

  const allVisibleChecked = items.length > 0 && items.every((item) => item.sync_to_main_wh)
  const someVisibleChecked = items.some((item) => item.sync_to_main_wh)

  const columns: DataTableColumn<Item>[] = [
    {
      header: 'Item',
      className: 'sticky left-0 z-10 bg-background',
      accessor: (row) => (
        <div>
          <div className="font-medium">{row.item_code}</div>
          <div className="text-xs text-muted-foreground">{row.item_name}</div>
        </div>
      ),
    },
    {
      header: 'Standard Rate',
      className: 'sticky left-[180px] z-10 bg-background text-right',
      accessor: (row) => formatCurrency(Number(row.standard_rate)),
    },
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
    ...warehouses.map((warehouse, colIndex) => ({
      header: warehouse.code,
      id: `wh-${warehouse.id}`,
      accessor: (row: Item, rowIndex: number) => {
        const readOnly = row.sync_to_main_wh && warehouse.warehouse_type !== 'main'
        const resolvedValue = readOnly
          ? formatCurrency(Number(mainWarehouse ? (whMap.get(`${row.id}:${mainWarehouse.id}`)?.rate ?? row.standard_rate) : row.standard_rate))
          : undefined

        return canUpdate ? (
          <WarehousePriceCell
            item={row}
            warehouse={warehouse}
            rowIndex={rowIndex}
            colIndex={colIndex}
            displayValue={getDisplayValue(row, warehouse)}
            status={cellStatus.get(`${row.id}:${warehouse.id}`)}
            readOnlyResolvedValue={resolvedValue}
            onChange={queueChange}
            onPasteBlock={handlePasteBlock}
            focusCell={focusCell}
          />
        ) : (
          (whMap.get(`${row.id}:${warehouse.id}`)?.rate ?? '—')
        )
      },
    })),
    ...(canUpdate
      ? [
          {
            header: (
              <div className="flex items-center gap-2">
                <Checkbox
                  checked={allVisibleChecked ? true : someVisibleChecked ? 'indeterminate' : false}
                  onCheckedChange={(checked) => syncMutation.mutate({ itemIds: items.map((i) => i.id), value: checked === true })}
                  disabled={items.length === 0 || syncMutation.isPending}
                />
                <span>Samakan dengan Main WH</span>
              </div>
            ),
            id: 'sync-to-main-wh',
            accessor: (row: Item) => (
              <Checkbox
                checked={row.sync_to_main_wh}
                onCheckedChange={(checked) => syncMutation.mutate({ itemIds: [row.id], value: checked === true })}
                disabled={syncMutation.isPending}
              />
            ),
          },
        ]
      : []),
  ] as DataTableColumn<Item>[]

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="master" />

      <PageHeader
        title="Item Prices"
        description="Per-zone and per-warehouse sale price overrides — an empty cell falls back to the item's Standard Rate."
        actions={
          <ActionBar
            actions={[
              { label: 'Refresh', icon: RotateCw, onClick: invalidateAll, disabled: itemsQuery.isFetching },
              { label: 'Export Zones', icon: Download, onClick: () => downloadItemPricesExport() },
              { label: 'Import Zones', icon: Upload, disabled: !canImport || importMutation.isPending, onClick: () => fileInputRef.current?.click() },
              { label: 'Export', icon: Download, onClick: () => downloadItemWarehousePricesExport() },
              { label: 'Import', icon: Upload, disabled: !canImport || previewMutation.isPending, onClick: () => whFileInputRef.current?.click() },
            ]}
          />
        }
      />

      <input ref={fileInputRef} type="file" accept=".csv,.xlsx,.xls" className="hidden" onChange={handleFileChange} />
      <input ref={whFileInputRef} type="file" accept=".csv,.xlsx,.xls" className="hidden" onChange={handleWhFileChange} />

      <div className="flex flex-wrap items-center gap-2">
        <SearchBox value={search} onChange={setSearch} placeholder="Search item code or name..." />
        <Select value={itemGroupId || '__all__'} onValueChange={(v) => setItemGroupId(v === '__all__' ? '' : v)}>
          <SelectTrigger className="w-56">
            <SelectValue placeholder="All item groups" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="__all__">All item groups</SelectItem>
            {itemGroupsQuery.data?.map((group) => (
              <SelectItem key={group.id} value={group.id}>
                {group.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {zones.length === 0 && !zonesQuery.isLoading && (
        <p className="text-sm text-muted-foreground">No price zones yet — create one under Maintenance &gt; Price Zones first.</p>
      )}
      {warehouses.length === 0 && !warehousesQuery.isLoading && (
        <p className="text-sm text-muted-foreground">No warehouses yet — create one under Master &gt; Area first.</p>
      )}

      <div className="max-h-[70vh] overflow-auto rounded-md border">
        <DataTable
          columns={columns}
          data={items}
          rowKey={(row) => row.id}
          isLoading={itemsQuery.isLoading || zonesQuery.isLoading || warehousesQuery.isLoading}
          isError={itemsQuery.isError}
          onRetry={() => itemsQuery.refetch()}
          emptyMessage="No items yet."
          stickyHeader
        />
      </div>

      {itemsQuery.data?.meta && <Pagination meta={itemsQuery.data.meta} onPageChange={setPage} />}

      <Dialog
        open={importFile !== null}
        onOpenChange={(open) => {
          if (!open) {
            setImportFile(null)
            setImportPreview(null)
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Import Warehouse Prices</DialogTitle>
            <DialogDescription>Review the changes below before committing them.</DialogDescription>
          </DialogHeader>

          {previewMutation.isPending && <Loader2 className="mx-auto size-6 animate-spin text-muted-foreground" />}

          {importPreview && (
            <div className="flex flex-col gap-3 text-sm">
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <div>To create: <span className="font-medium">{importPreview.to_create}</span></div>
                <div>To update: <span className="font-medium">{importPreview.to_update}</span></div>
                <div>To delete: <span className="font-medium">{importPreview.to_delete}</span></div>
                <div>Sync flag changes: <span className="font-medium">{importPreview.sync_changes}</span></div>
                <div>Unchanged: <span className="font-medium">{importPreview.unchanged}</span></div>
                <div>Errors: <span className="font-medium">{importPreview.errors.length}</span></div>
              </div>

              {importPreview.errors.length > 0 && (
                <div className="max-h-40 overflow-auto rounded-md border p-2 text-xs text-destructive">
                  {importPreview.errors.map((err, i) => (
                    <div key={i}>Row {err.row}: {err.reason}</div>
                  ))}
                </div>
              )}
            </div>
          )}

          <DialogFooter>
            <Button variant="outline" onClick={() => { setImportFile(null); setImportPreview(null) }}>
              Cancel
            </Button>
            <Button
              disabled={!importFile || !importPreview || commitMutation.isPending}
              onClick={() => importFile && commitMutation.mutate(importFile)}
            >
              {commitMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Commit Import
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
