import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { formatQty, parseLocaleQty, qtyDecimalPlaces } from '@/shared/lib/qty'
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

  // Same PO item can span multiple rows (different truck loads) — the over-remaining warning
  // for a Weight-category item is about the combined total, not any single row alone. Mirrors
  // goodsReceiptFormSchema's own grouping.
  const receivedTotalByPoItemId = new Map<string, number>()
  watchedItems?.forEach((line) => {
    if (!line?.purchase_order_item_id) return
    const value = parseLocaleQty(line.receiveNow || '0')
    receivedTotalByPoItemId.set(line.purchase_order_item_id, (receivedTotalByPoItemId.get(line.purchase_order_item_id) ?? 0) + value)
  })

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
      setValue(`items.${index}.qtyCategory`, selected.item_qty_category ?? 'unit', { shouldValidate: true })
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
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={6} className="p-0">
                  <EmptyState message="No line items yet." description="Use Add Row to start receiving against this Purchase Order." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => {
                const line = watchedItems?.[index]
                const remaining = Number(line?.remaining ?? 0)
                const allowOverReceipt = line?.allowOverReceipt ?? false
                const hasItem = !!line?.purchase_order_item_id
                const qtyCategory = line?.qtyCategory ?? 'unit'
                const decimalPlaces = qtyDecimalPlaces(qtyCategory)
                const uom = purchaseOrderItems.find((poItem) => poItem.id === line?.purchase_order_item_id)?.item_uom
                const isWeight = qtyCategory === 'weight'
                const groupTotal = line?.purchase_order_item_id ? (receivedTotalByPoItemId.get(line.purchase_order_item_id) ?? 0) : 0
                const overBy = isWeight ? groupTotal - remaining : 0

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
                    <TableCell className="text-right">{formatQty(line?.ordered ?? 0, qtyCategory)}</TableCell>
                    <TableCell className="text-right">{formatQty(line?.alreadyReceived ?? 0, qtyCategory)}</TableCell>
                    <TableCell className="text-right">{formatQty(remaining, qtyCategory)}</TableCell>
                    <TableCell>
                      <FormField
                        control={control}
                        name={`items.${index}.receiveNow`}
                        render={({ field: receiveNowField }) => (
                          <FormItem className="gap-0">
                            <div className="flex items-center gap-1.5">
                              <Input
                                type="number"
                                min={0}
                                max={isWeight || allowOverReceipt ? undefined : remaining}
                                step={decimalPlaces > 0 ? (10 ** -decimalPlaces).toFixed(decimalPlaces) : '1'}
                                disabled={disabled || !hasItem || (remaining === 0 && !isWeight && !allowOverReceipt)}
                                {...receiveNowField}
                              />
                              {uom && <span className="text-xs text-muted-foreground">{uom}</span>}
                            </div>
                            {overBy > 0 && (
                              <p className="mt-1 text-xs text-amber-600 dark:text-amber-500">
                                Melebihi sisa PO sebesar {formatQty(overBy, qtyCategory)} {uom ?? ''} — hasil timbangan aktual.
                              </p>
                            )}
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
            qtyCategory: 'unit',
            receiveNow: '0',
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
