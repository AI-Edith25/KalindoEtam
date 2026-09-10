import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { LineItemTableScroll } from '@/components/shared/LineItemTableScroll'
import { SearchableSelect, type SearchableSelectOption } from '@/components/shared/SearchableSelect'
import { cn } from '@/lib/utils'
import { formatQty, qtyDecimalPlaces } from '@/shared/lib/qty'
import { searchItemsLookup } from '@/features/master/api/lookupsApi'
import type { StockAdjustmentEditorValues } from '../lib/stockAdjustmentFormSchema'
import type { Item } from '@/features/master/types'

function itemLabel(item: Pick<Item, 'item_code' | 'item_name'>) {
  return `${item.item_code} — ${item.item_name}`
}

interface StockAdjustmentLineItemTableProps {
  form: UseFormReturn<StockAdjustmentEditorValues>
  disabled?: boolean
}

/**
 * Free-form field array (Add/Remove Row, item lookup) — not derived from
 * a parent document, since a physical count can include any item in the
 * warehouse. System Qty is read via useWatch, not useFieldArray's own
 * `fields` snapshot: the Editor patches it in asynchronously once a
 * warehouse is chosen and the bulk stock-balance query resolves, and
 * `fields` doesn't reactively pick up plain setValue() calls on a path
 * that isn't otherwise registered — the same bug class already caught
 * and fixed once in DeliveryLineItemTable.
 */
export function StockAdjustmentLineItemTable({ form, disabled }: StockAdjustmentLineItemTableProps) {
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
              <TableHead className="text-right">System Qty</TableHead>
              <TableHead className="w-32 text-right">Physical Qty</TableHead>
              <TableHead className="text-right">Difference</TableHead>
              <TableHead className="w-32 text-right">Unit Cost</TableHead>
              <TableHead>Reason</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="p-0">
                  <EmptyState message="No line items yet." description="Use Add Row to start recording a physical count." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => {
                const row = watchedItems?.[index]
                const systemQty = row?.systemQty ?? field.systemQty
                const countedQty = Number(row?.countedQty || 0)
                const difference = countedQty - systemQty
                const qtyCategory = row?.qtyCategory ?? 'unit'
                const decimalPlaces = qtyDecimalPlaces(qtyCategory)
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
                    <TableCell className="text-right tabular-nums text-muted-foreground">{formatQty(systemQty, qtyCategory)}</TableCell>
                    <TableCell className="min-w-32">
                      <FormField
                        control={control}
                        name={`items.${index}.countedQty`}
                        render={({ field: countedField }) => (
                          <FormItem className="gap-0">
                            <Input
                              type="number"
                              min={0}
                              step={decimalPlaces > 0 ? (10 ** -decimalPlaces).toFixed(decimalPlaces) : '1'}
                              disabled={disabled}
                              {...countedField}
                            />
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </TableCell>
                    <TableCell
                      className={cn(
                        'text-right tabular-nums font-medium',
                        difference > 0 && 'text-green-600 dark:text-green-400',
                        difference < 0 && 'text-destructive',
                      )}
                    >
                      {difference > 0 ? '+' : ''}
                      {formatQty(difference, qtyCategory)}
                    </TableCell>
                    <TableCell className="min-w-32">
                      {difference > 0 && (
                        <FormField
                          control={control}
                          name={`items.${index}.unitCost`}
                          render={({ field: unitCostField }) => (
                            <FormItem className="gap-0">
                              <Input type="number" min={0} step="0.01" placeholder="Required" disabled={disabled} {...unitCostField} />
                              <FormMessage />
                            </FormItem>
                          )}
                        />
                      )}
                    </TableCell>
                    <TableCell>
                      <FormField
                        control={control}
                        name={`items.${index}.reason`}
                        render={({ field: reasonField }) => (
                          <FormItem className="gap-0">
                            <Input placeholder="Why does this differ?" disabled={disabled} {...reasonField} />
                            <FormMessage />
                          </FormItem>
                        )}
                      />
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
        </Table>
      </LineItemTableScroll>

      <Button
        type="button"
        variant="outline"
        size="sm"
        className="self-start"
        onClick={() => append({ item_id: '', item_code: '', item_name: '', qtyCategory: 'unit', systemQty: 0, countedQty: '0', unitCost: '', reason: '' })}
        disabled={disabled}
      >
        <Plus className="size-4" />
        Add Row
      </Button>
    </div>
  )
}
