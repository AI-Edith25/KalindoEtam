import { X } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { FilterPanel } from './FilterPanel'
import { SearchableSelect, type SearchableSelectOption } from './SearchableSelect'
import { DATE_RANGE_PRESET_LABELS, dateRangeForPreset, type DateRangePreset } from '@/shared/lib/dateRangePresets'

export interface FilterOption {
  value: string
  label: string
}

const ALL_REASONS = '__all__'

export interface AdvancedFilterValue {
  search: string
  date_from: string
  date_to: string
  preset: DateRangePreset
  status: string[]
  customer_id: string
  sales_person_id: string
  warehouse_id: string
  reason: string
  sales_order_number: string
  min_amount: string
  max_amount: string
}

export interface FilterChip {
  key: string
  label: string
  onRemove: () => void
}

interface AdvancedFilterToolbarProps {
  value: AdvancedFilterValue
  onChange: (patch: Partial<AdvancedFilterValue>) => void
  onApply: () => void
  onReset: () => void
  hasActiveFilters: boolean
  chips: FilterChip[]
  statusOptions: FilterOption[]
  customerOptions: SearchableSelectOption[]
  customerLoading?: boolean
  /** Omit to hide the field entirely — not every module has a Salesperson concept on screen. */
  salesPersonOptions?: SearchableSelectOption[]
  /** Omit to hide the field entirely — only Deliveries has a Warehouse today. */
  warehouseOptions?: SearchableSelectOption[]
  /** Omit to hide the field entirely — only Credit/Debit Notes have a Reason concept today. */
  reasonOptions?: FilterOption[]
  /** Omit to hide the field entirely — only Deliveries filter by their originating Sales Order's number (no bounded lookup exists to back a select, so this is a plain LIKE-match text field). */
  showSalesOrderReference?: boolean
  /** Omit to hide the field entirely — only Credit/Debit Notes have a "Rentang Nominal" filter today. */
  showAmountRange?: boolean
}

/**
 * One reusable filter toolbar for all 5 Sales list pages (Orders,
 * Deliveries, Invoices, Credit Notes, Debit Notes) — date range + presets,
 * Customer/Salesperson/Warehouse (each shown only when its options are
 * supplied), multi-select Status, free search, Apply/Reset, and removable
 * chips for the active filters. Purely controlled — the caller owns both
 * the "draft" (this component's `value`) and "applied" (URL-synced via
 * useUrlFilters, what the list query actually uses) states; `onApply`
 * commits draft -> applied, matching the ticket's explicit Terapkan/Reset
 * buttons instead of filtering on every keystroke.
 */
export function AdvancedFilterToolbar({
  value,
  onChange,
  onApply,
  onReset,
  hasActiveFilters,
  chips,
  statusOptions,
  customerOptions,
  customerLoading,
  salesPersonOptions,
  warehouseOptions,
  reasonOptions,
  showSalesOrderReference,
  showAmountRange,
}: AdvancedFilterToolbarProps) {
  const handlePresetChange = (preset: string) => {
    if (preset === 'custom') {
      onChange({ preset: 'custom' })
      return
    }

    onChange({ preset: preset as DateRangePreset, ...dateRangeForPreset(preset as Exclude<DateRangePreset, 'custom'>) })
  }

  const toggleStatus = (status: string) => {
    onChange({
      status: value.status.includes(status) ? value.status.filter((s) => s !== status) : [...value.status, status],
    })
  }

  return (
    <div className="flex flex-col gap-2">
      <FilterPanel onClear={onReset} hasActiveFilters={hasActiveFilters}>
        <div className="flex w-48 flex-col gap-1">
          <label className="text-xs text-muted-foreground">Search</label>
          <Input
            value={value.search}
            onChange={(event) => onChange({ search: event.target.value })}
            placeholder="No. dokumen, referensi, customer…"
          />
        </div>

        <div className="flex w-40 flex-col gap-1">
          <label className="text-xs text-muted-foreground">Periode</label>
          <Select value={value.preset} onValueChange={handlePresetChange}>
            <SelectTrigger className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {Object.entries(DATE_RANGE_PRESET_LABELS).map(([preset, label]) => (
                <SelectItem key={preset} value={preset}>
                  {label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="flex w-36 flex-col gap-1">
          <label className="text-xs text-muted-foreground">Dari Tanggal</label>
          <Input
            type="date"
            value={value.date_from}
            onChange={(event) => onChange({ preset: 'custom', date_from: event.target.value })}
          />
        </div>
        <div className="flex w-36 flex-col gap-1">
          <label className="text-xs text-muted-foreground">Sampai Tanggal</label>
          <Input
            type="date"
            value={value.date_to}
            onChange={(event) => onChange({ preset: 'custom', date_to: event.target.value })}
          />
        </div>

        <div className="flex w-44 flex-col gap-1">
          <label className="text-xs text-muted-foreground">Status</label>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button type="button" variant="outline" className="w-full justify-start font-normal">
                {value.status.length === 0 ? 'Semua Status' : `${value.status.length} status dipilih`}
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
              {statusOptions.map((option) => (
                <DropdownMenuCheckboxItem
                  key={option.value}
                  checked={value.status.includes(option.value)}
                  onSelect={(event) => event.preventDefault()}
                  onCheckedChange={() => toggleStatus(option.value)}
                >
                  {option.label}
                </DropdownMenuCheckboxItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>

        <div className="flex w-52 flex-col gap-1">
          <label className="text-xs text-muted-foreground">Customer</label>
          <SearchableSelect
            options={customerOptions}
            loading={customerLoading}
            value={value.customer_id || undefined}
            onChange={(customerId) => onChange({ customer_id: customerId ?? '' })}
            placeholder="Semua Customer"
          />
        </div>

        {salesPersonOptions && (
          <div className="flex w-44 flex-col gap-1">
            <label className="text-xs text-muted-foreground">Salesperson</label>
            <SearchableSelect
              options={salesPersonOptions}
              value={value.sales_person_id || undefined}
              onChange={(salesPersonId) => onChange({ sales_person_id: salesPersonId ?? '' })}
              placeholder="Semua Salesperson"
            />
          </div>
        )}

        {warehouseOptions && (
          <div className="flex w-44 flex-col gap-1">
            <label className="text-xs text-muted-foreground">Gudang</label>
            <SearchableSelect
              options={warehouseOptions}
              value={value.warehouse_id || undefined}
              onChange={(warehouseId) => onChange({ warehouse_id: warehouseId ?? '' })}
              placeholder="Semua Gudang"
            />
          </div>
        )}

        {reasonOptions && (
          <div className="flex w-44 flex-col gap-1">
            <label className="text-xs text-muted-foreground">Alasan</label>
            <Select value={value.reason || ALL_REASONS} onValueChange={(next) => onChange({ reason: next === ALL_REASONS ? '' : next })}>
              <SelectTrigger className="w-full">
                <SelectValue placeholder="Semua Alasan" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL_REASONS}>Semua Alasan</SelectItem>
                {reasonOptions.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}

        {showSalesOrderReference && (
          <div className="flex w-40 flex-col gap-1">
            <label className="text-xs text-muted-foreground">No. Sales Order</label>
            <Input
              value={value.sales_order_number}
              onChange={(event) => onChange({ sales_order_number: event.target.value })}
              placeholder="Nomor SO…"
            />
          </div>
        )}

        {showAmountRange && (
          <>
            <div className="flex w-32 flex-col gap-1">
              <label className="text-xs text-muted-foreground">Nominal Min</label>
              <Input
                type="number"
                value={value.min_amount}
                onChange={(event) => onChange({ min_amount: event.target.value })}
                placeholder="0"
              />
            </div>
            <div className="flex w-32 flex-col gap-1">
              <label className="text-xs text-muted-foreground">Nominal Maks</label>
              <Input
                type="number"
                value={value.max_amount}
                onChange={(event) => onChange({ max_amount: event.target.value })}
                placeholder="—"
              />
            </div>
          </>
        )}

        <Button type="button" onClick={onApply} className="mb-0.5">
          Terapkan
        </Button>
      </FilterPanel>

      {chips.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {chips.map((chip) => (
            <Badge key={chip.key} variant="secondary" className="gap-1 pr-1">
              {chip.label}
              <button type="button" onClick={chip.onRemove} className="rounded-full p-0.5 hover:bg-muted-foreground/20">
                <X className="size-3" />
              </button>
            </Badge>
          ))}
        </div>
      )}
    </div>
  )
}
