import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { cn, formatNumber } from '@/lib/utils'
import type { StockAdjustmentEditorValues } from '../lib/stockAdjustmentFormSchema'
import type { Item } from '@/features/master/types'

interface StockAdjustmentLineItemTableProps {
  form: UseFormReturn<StockAdjustmentEditorValues>
  items: Item[]
  itemsLoading: boolean
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
export function StockAdjustmentLineItemTable({ form, items, itemsLoading, disabled }: StockAdjustmentLineItemTableProps) {
  const { control, setValue } = form
  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const watchedItems = useWatch({ control, name: 'items' })

  const handleItemChange = (index: number, itemId: string) => {
    setValue(`items.${index}.item_id`, itemId, { shouldValidate: true })

    const selected = items.find((item) => item.id === itemId)
    if (selected) {
      setValue(`items.${index}.item_code`, selected.item_code)
      setValue(`items.${index}.item_name`, selected.item_name)
    }
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="overflow-x-auto rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Item</TableHead>
              <TableHead className="text-right">System Qty</TableHead>
              <TableHead className="w-32 text-right">Physical Qty</TableHead>
              <TableHead className="text-right">Difference</TableHead>
              <TableHead>Reason</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={6} className="p-0">
                  <EmptyState message="No line items yet." description="Use Add Row to start recording a physical count." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => {
                const systemQty = watchedItems?.[index]?.systemQty ?? field.systemQty
                const countedQty = Number(watchedItems?.[index]?.countedQty || 0)
                const difference = countedQty - systemQty

                return (
                  <TableRow key={field.id}>
                    <TableCell>
                      <FormField
                        control={control}
                        name={`items.${index}.item_id`}
                        render={({ field: itemField }) => (
                          <FormItem className="gap-0">
                            <Select value={itemField.value} onValueChange={(value) => handleItemChange(index, value)} disabled={disabled}>
                              <SelectTrigger className="w-full">
                                <SelectValue placeholder={itemsLoading ? 'Loading…' : 'Select item'} />
                              </SelectTrigger>
                              <SelectContent>
                                {items.map((item) => (
                                  <SelectItem key={item.id} value={item.id}>
                                    {item.item_code} — {item.item_name}
                                  </SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-muted-foreground">{formatNumber(systemQty)}</TableCell>
                    <TableCell>
                      <FormField
                        control={control}
                        name={`items.${index}.countedQty`}
                        render={({ field: countedField }) => (
                          <FormItem className="gap-0">
                            <Input type="number" min={0} step="1" disabled={disabled} {...countedField} />
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
                      {formatNumber(difference)}
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
      </div>

      <Button
        type="button"
        variant="outline"
        size="sm"
        className="self-start"
        onClick={() => append({ item_id: '', item_code: '', item_name: '', systemQty: 0, countedQty: '0', reason: '' })}
        disabled={disabled}
      >
        <Plus className="size-4" />
        Add Row
      </Button>
    </div>
  )
}
