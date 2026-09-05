import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { LineItemTableScroll } from '@/components/shared/LineItemTableScroll'
import { formatQty, qtyDecimalPlaces } from '@/shared/lib/qty'
import type { StockTransferEditorValues } from '../lib/stockTransferFormSchema'
import type { Item } from '@/features/master/types'

interface StockTransferLineItemTableProps {
  form: UseFormReturn<StockTransferEditorValues>
  items: Item[]
  itemsLoading: boolean
  disabled?: boolean
}

/**
 * Free-form field array (Add/Remove Row) — meniru StockAdjustmentLineItemTable.
 * Available Qty dibaca via useWatch, dipatch async dari bulk stock-balance
 * query gudang asal (lihat StockTransferEditorPage) sekali resolve — bukan
 * dari `fields` snapshot, sama kelas isu yang sudah ditangani di
 * DeliveryLineItemTable/StockAdjustmentLineItemTable.
 */
export function StockTransferLineItemTable({ form, items, itemsLoading, disabled }: StockTransferLineItemTableProps) {
  const { control, setValue } = form
  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const watchedItems = useWatch({ control, name: 'items' })

  const handleItemChange = (index: number, itemId: string) => {
    setValue(`items.${index}.item_id`, itemId, { shouldValidate: true })

    const selected = items.find((item) => item.id === itemId)
    if (selected) {
      setValue(`items.${index}.item_code`, selected.item_code)
      setValue(`items.${index}.item_name`, selected.item_name)
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
              <TableHead className="text-right">Available Qty</TableHead>
              <TableHead className="w-32 text-right">Qty to Transfer</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={4} className="p-0">
                  <EmptyState message="No line items yet." description="Use Add Row to start recording a transfer." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => {
                const availableQty = watchedItems?.[index]?.availableQty ?? field.availableQty
                const qtyCategory = watchedItems?.[index]?.qtyCategory ?? 'unit'
                const decimalPlaces = qtyDecimalPlaces(qtyCategory)

                return (
                  <TableRow key={field.id}>
                    <TableCell className="sticky left-0 z-10 bg-background">
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
                    <TableCell className="text-right tabular-nums text-muted-foreground">{formatQty(availableQty, qtyCategory)}</TableCell>
                    <TableCell>
                      <FormField
                        control={control}
                        name={`items.${index}.qty`}
                        render={({ field: qtyField }) => (
                          <FormItem className="gap-0">
                            <Input
                              type="number"
                              min={decimalPlaces > 0 ? 0.01 : 1}
                              step={decimalPlaces > 0 ? (10 ** -decimalPlaces).toFixed(decimalPlaces) : '1'}
                              disabled={disabled}
                              {...qtyField}
                            />
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
        onClick={() => append({ item_id: '', item_code: '', item_name: '', qtyCategory: 'unit', availableQty: 0, qty: '1' })}
        disabled={disabled}
      >
        <Plus className="size-4" />
        Add Row
      </Button>
    </div>
  )
}
