import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { formatNumber } from '@/lib/utils'
import type { GoodsReceiptEditorValues } from '../lib/goodsReceiptFormSchema'
import type { PurchaseOrderItem } from '../types'

interface GoodsReceiptLineItemTableProps {
  form: UseFormReturn<GoodsReceiptEditorValues>
  purchaseOrderItems: PurchaseOrderItem[]
  disabled?: boolean
}

/**
 * From-PO receipt lines — Add/Remove Row like DirectGoodsReceiptLineItemTable,
 * but the item Select is restricted to `purchaseOrderItems` (this PO's own
 * lines) instead of the full item master, and its value is
 * `purchase_order_item_id` — so the same PO line can be picked on more than
 * one row (different truck loads of the same item). Ordered/Already
 * Received/Remaining are a snapshot autofilled on selection, same pattern
 * as DirectGoodsReceiptLineItemTable's rate-from-standard_rate autofill.
 * Combined `receiveNow` per item is validated at the array level (see
 * goodsReceiptFormSchema), not per row here.
 */
export function GoodsReceiptLineItemTable({ form, purchaseOrderItems, disabled }: GoodsReceiptLineItemTableProps) {
  const { control, setValue } = form
  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const watchedItems = useWatch({ control, name: 'items' })

  const handleItemChange = (index: number, poItemId: string) => {
    setValue(`items.${index}.purchase_order_item_id`, poItemId, { shouldValidate: true })

    const selected = purchaseOrderItems.find((item) => item.id === poItemId)
    if (selected) {
      setValue(`items.${index}.item_code`, selected.item_code ?? '')
      setValue(`items.${index}.item_name`, selected.item_name ?? '')
      setValue(`items.${index}.rate`, Number(selected.rate))
      setValue(`items.${index}.ordered`, Number(selected.qty))
      setValue(`items.${index}.alreadyReceived`, Number(selected.received_qty))
      setValue(`items.${index}.remaining`, Number(selected.outstanding_qty))
      setValue(`items.${index}.allowOverReceipt`, selected.allow_over_receipt ?? false, { shouldValidate: true })
    }
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="overflow-x-auto rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Item</TableHead>
              <TableHead className="text-right">Ordered Qty</TableHead>
              <TableHead className="text-right">Already Received</TableHead>
              <TableHead className="text-right">Remaining</TableHead>
              <TableHead className="w-36 text-right">Receive Now</TableHead>
              <TableHead className="w-48">Actual Weight</TableHead>
              <TableHead className="w-36">Weighbridge Ref</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={8} className="p-0">
                  <EmptyState message="No line items yet." description="Use Add Row to start receiving against this Purchase Order." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => {
                const line = watchedItems?.[index]
                const remaining = Number(line?.remaining ?? 0)
                const allowOverReceipt = line?.allowOverReceipt ?? false
                const hasItem = !!line?.purchase_order_item_id

                return (
                  <TableRow key={field.id}>
                    <TableCell>
                      <FormField
                        control={control}
                        name={`items.${index}.purchase_order_item_id`}
                        render={({ field: itemField }) => (
                          <FormItem className="gap-0">
                            <Select value={itemField.value} onValueChange={(value) => handleItemChange(index, value)} disabled={disabled}>
                              <SelectTrigger className="w-full">
                                <SelectValue placeholder="Select item" />
                              </SelectTrigger>
                              <SelectContent>
                                {purchaseOrderItems.map((poItem) => (
                                  <SelectItem key={poItem.id} value={poItem.id}>
                                    {poItem.item_code} — {poItem.item_name}
                                  </SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </TableCell>
                    <TableCell className="text-right">{formatNumber(line?.ordered ?? 0)}</TableCell>
                    <TableCell className="text-right">{formatNumber(line?.alreadyReceived ?? 0)}</TableCell>
                    <TableCell className="text-right">{formatNumber(remaining)}</TableCell>
                    <TableCell>
                      <FormField
                        control={control}
                        name={`items.${index}.receiveNow`}
                        render={({ field: receiveNowField }) => (
                          <FormItem className="gap-0">
                            <Input
                              type="number"
                              min={0}
                              max={allowOverReceipt ? undefined : remaining}
                              step="1"
                              disabled={disabled || !hasItem || (remaining === 0 && !allowOverReceipt)}
                              {...receiveNowField}
                            />
                            <FormMessage />
                          </FormItem>
                        )}
                      />
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
        onClick={() =>
          append({
            purchase_order_item_id: '',
            item_code: '',
            item_name: '',
            rate: 0,
            ordered: 0,
            alreadyReceived: 0,
            remaining: 0,
            allowOverReceipt: false,
            receiveNow: '0',
            actual_weight: '',
            weight_unit: 'ton',
            weighbridge_ref: '',
          })
        }
        disabled={disabled}
      >
        <Plus className="size-4" />
        Add Row
      </Button>
    </div>
  )
}
