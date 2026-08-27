import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { formatCurrency } from '@/lib/utils'
import { lineAmount } from '@/shared/lib/documentTotals'
import type { DirectGoodsReceiptEditorValues } from '../lib/goodsReceiptFormSchema'
import type { Item } from '@/features/master/types'

interface DirectGoodsReceiptLineItemTableProps {
  form: UseFormReturn<DirectGoodsReceiptEditorValues>
  items: Item[]
  itemsLoading: boolean
  disabled?: boolean
}

/**
 * Standalone/direct receipt (no source Purchase Order) — Add/Remove row,
 * item lookup autofilling Unit Price from standard_rate. Mirrors
 * PurchaseOrderLineItemTable minus the Tax column (GoodsReceiptItem
 * carries no tax fields).
 */
export function DirectGoodsReceiptLineItemTable({ form, items, itemsLoading, disabled }: DirectGoodsReceiptLineItemTableProps) {
  const { control, setValue } = form
  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const watchedItems = useWatch({ control, name: 'items' })

  const handleItemChange = (index: number, itemId: string) => {
    setValue(`items.${index}.item_id`, itemId, { shouldValidate: true })

    const selected = items.find((item) => item.id === itemId)
    if (selected) {
      setValue(`items.${index}.item_code`, selected.item_code)
      setValue(`items.${index}.item_name`, selected.item_name)
      setValue(`items.${index}.rate`, String(selected.standard_rate), { shouldValidate: true })
    }
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="overflow-x-auto rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Item</TableHead>
              <TableHead className="w-28">Qty</TableHead>
              <TableHead className="w-36">Rate</TableHead>
              <TableHead className="w-36 text-right">Amount</TableHead>
              <TableHead className="w-48">Actual Weight</TableHead>
              <TableHead className="w-36">Weighbridge Ref</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="p-0">
                  <EmptyState message="No line items yet." description="Use Add Row to start building this receipt." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => (
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
                  <TableCell>
                    <FormField
                      control={control}
                      name={`items.${index}.qty`}
                      render={({ field: qtyField }) => (
                        <FormItem className="gap-0">
                          <Input type="number" min={1} step="1" disabled={disabled} {...qtyField} />
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </TableCell>
                  <TableCell>
                    <FormField
                      control={control}
                      name={`items.${index}.rate`}
                      render={({ field: rateField }) => (
                        <FormItem className="gap-0">
                          <Input type="number" min={0} step="0.01" disabled={disabled} {...rateField} />
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </TableCell>
                  <TableCell className="text-right font-medium">
                    {formatCurrency(lineAmount(watchedItems?.[index] ?? { qty: 0, rate: 0 }))}
                  </TableCell>
                  <TableCell>
                    <div className="flex gap-1.5">
                      <FormField
                        control={control}
                        name={`items.${index}.actual_weight`}
                        render={({ field: weightField }) => (
                          <FormItem className="gap-0">
                            <Input type="number" min={0} step="0.01" placeholder="Optional" disabled={disabled} {...weightField} />
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                      <FormField
                        control={control}
                        name={`items.${index}.weight_unit`}
                        render={({ field: unitField }) => (
                          <Select value={unitField.value || 'ton'} onValueChange={unitField.onChange} disabled={disabled}>
                            <SelectTrigger className="w-20">
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="ton">ton</SelectItem>
                              <SelectItem value="kg">kg</SelectItem>
                            </SelectContent>
                          </Select>
                        )}
                      />
                    </div>
                  </TableCell>
                  <TableCell>
                    <FormField
                      control={control}
                      name={`items.${index}.weighbridge_ref`}
                      render={({ field: refField }) => (
                        <FormItem className="gap-0">
                          <Input placeholder="Optional" disabled={disabled} {...refField} />
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
              ))
            )}
          </TableBody>
        </Table>
      </div>

      <Button
        type="button"
        variant="outline"
        size="sm"
        className="self-start"
        onClick={() =>
          append({ item_id: '', item_code: '', item_name: '', qty: '1', rate: '0', actual_weight: '', weight_unit: 'ton', weighbridge_ref: '' })
        }
        disabled={disabled}
      >
        <Plus className="size-4" />
        Add Row
      </Button>
    </div>
  )
}
