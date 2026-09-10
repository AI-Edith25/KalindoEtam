import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { useQuery } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { LineItemTableScroll } from '@/components/shared/LineItemTableScroll'
import { SearchableSelect, type SearchableSelectOption } from '@/components/shared/SearchableSelect'
import { formatCurrency } from '@/lib/utils'
import { qtyDecimalPlaces } from '@/shared/lib/qty'
import { searchItemsLookup } from '@/features/master/api/lookupsApi'
import { previewIssueStockCost } from '../api/issueStockApi'
import type { IssueStockEditorValues } from '../lib/issueStockFormSchema'
import type { Item } from '@/features/master/types'

function itemLabel(item: Pick<Item, 'item_code' | 'item_name'>) {
  return `${item.item_code} — ${item.item_name}`
}

interface IssueStockLineItemTableProps {
  form: UseFormReturn<IssueStockEditorValues>
  warehouseId: string
  disabled?: boolean
}

/**
 * Read-only FIFO-computed Unit Cost — the ticket's explicit requirement ("tampilkan Unit Cost
 * hasil FIFO, read-only, dihitung sistem"). Queries FifoLayerService::previewConsumption() (via
 * IssueStockController::previewCost()) per row as item/qty/warehouse change; never written back
 * into the form — the real cost is only ever decided by consume() at Submit time.
 */
function CostCell({ itemId, warehouseId, qty }: { itemId: string; warehouseId: string; qty: number }) {
  const enabled = !!itemId && !!warehouseId && qty > 0

  const preview = useQuery({
    queryKey: ['issue-stock-preview-cost', itemId, warehouseId, qty],
    queryFn: () => previewIssueStockCost(itemId, warehouseId, qty),
    enabled,
  })

  if (!enabled) return <span className="text-muted-foreground">—</span>
  if (preview.isLoading) return <span className="text-muted-foreground">…</span>
  if (!preview.data) return <span className="text-muted-foreground">—</span>

  const insufficient = qty > preview.data.available_qty
  return (
    <span className={insufficient ? 'text-destructive' : undefined}>
      {formatCurrency(preview.data.unit_cost)}
      {insufficient && <span className="block text-xs">Only {preview.data.available_qty} available</span>}
    </span>
  )
}

export function IssueStockLineItemTable({ form, warehouseId, disabled }: IssueStockLineItemTableProps) {
  const { control, setValue } = form
  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const watchedItems = useWatch({ control, name: 'items' })

  const loadItemOptions = async (query: string) => {
    const items = await searchItemsLookup(query)
    return items.map((item) => ({ value: item.id, label: itemLabel(item), data: item }))
  }

  const handleItemChange = (index: number, itemId: string, option?: SearchableSelectOption<Item>) => {
    setValue(`items.${index}.item_id`, itemId, { shouldValidate: true })

    const selected = option?.data
    setValue(`items.${index}.item_code`, selected?.item_code ?? '')
    setValue(`items.${index}.item_name`, selected?.item_name ?? '')
    if (selected) {
      setValue(`items.${index}.qtyCategory`, selected.qty_category, { shouldValidate: true })
    }
  }

  return (
    <div className="flex flex-col gap-3">
      <LineItemTableScroll>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="sticky left-0 z-10 bg-background">Item</TableHead>
              <TableHead className="w-32 text-right">Qty</TableHead>
              <TableHead className="w-40 text-right">Unit Cost (FIFO)</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={4} className="p-0">
                  <EmptyState message="No line items yet." description="Use Add Row to start recording issued stock." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => {
                const row = watchedItems?.[index]
                const qtyCategory = row?.qtyCategory ?? 'unit'
                const decimalPlaces = qtyDecimalPlaces(qtyCategory)
                const itemId = row?.item_id ?? ''
                const qty = Number(row?.qty?.replace(',', '.') || 0)
                const selectedOption: SearchableSelectOption<Item> | undefined =
                  row?.item_id && row.item_code ? { value: row.item_id, label: itemLabel({ item_code: row.item_code, item_name: row.item_name ?? '' }) } : undefined

                return (
                  <TableRow key={field.id}>
                    <TableCell className="sticky left-0 z-10 bg-background">
                      <FormField
                        control={control}
                        name={`items.${index}.item_id`}
                        render={({ field: itemField }) => (
                          <FormItem className="gap-0">
                            <SearchableSelect
                              loadOptions={loadItemOptions}
                              selectedOption={selectedOption}
                              value={itemField.value}
                              onChange={(value, option) => handleItemChange(index, value ?? '', option)}
                              disabled={disabled}
                              placeholder="Select item"
                              aria-label="Item"
                            />
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </TableCell>
                    <TableCell className="min-w-32">
                      <FormField
                        control={control}
                        name={`items.${index}.qty`}
                        render={({ field: qtyField }) => (
                          <FormItem className="gap-0">
                            <Input
                              type="number"
                              min={0}
                              step={decimalPlaces > 0 ? (10 ** -decimalPlaces).toFixed(decimalPlaces) : '1'}
                              disabled={disabled}
                              {...qtyField}
                            />
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      <CostCell itemId={itemId} warehouseId={warehouseId} qty={qty} />
                    </TableCell>
                    <TableCell>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8 text-destructive hover:text-destructive"
                        onClick={() => remove(index)}
                        disabled={disabled}
                      >
                        <Trash2 className="size-4" />
                        <span className="sr-only">Remove row</span>
                      </Button>
                    </TableCell>
                  </TableRow>
                )
              })
            )}
          </TableBody>
          {fields.length > 0 && (
            <TableFooter>
              <TableRow>
                <TableCell colSpan={2} className="text-right font-medium">
                  Total
                </TableCell>
                <TableCell colSpan={2} className="text-right text-xs text-muted-foreground">
                  Confirmed once Submitted
                </TableCell>
              </TableRow>
            </TableFooter>
          )}
        </Table>
      </LineItemTableScroll>

      <Button
        type="button"
        variant="outline"
        size="sm"
        className="self-start"
        onClick={() => append({ item_id: '', item_code: '', item_name: '', qtyCategory: 'unit', qty: '' })}
        disabled={disabled}
      >
        <Plus className="size-4" />
        Add Row
      </Button>
    </div>
  )
}
