import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Input } from '@/components/ui/input'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { cn, formatNumber } from '@/lib/utils'
import type { DeliveryEditorValues } from '../lib/deliveryFormSchema'

interface DeliveryLineItemTableProps {
  form: UseFormReturn<DeliveryEditorValues>
  disabled?: boolean
}

/**
 * Fixed rows, one per Sales Order line — no Add Row, no item lookup,
 * same shape as GoodsReceiptLineItemTable. Two read-only ceilings shown
 * side by side (Remaining, Available Stock) so it's visible *before*
 * typing which one is tighter — Available Stock is highlighted when it's
 * the binding constraint. Deliver Now is bounded to
 * [0, min(remaining, availableStock)] by the zod schema, which reports
 * a distinct message for whichever ceiling is actually exceeded.
 *
 * Available Stock is read from useWatch, not from useFieldArray's own
 * `fields` snapshot: the Editor updates it asynchronously via
 * form.setValue() once the warehouse-scoped balance lookup resolves, and
 * useFieldArray's fields don't re-derive from plain setValue calls on a
 * path that isn't otherwise registered — only useWatch subscribes to that.
 */
export function DeliveryLineItemTable({ form, disabled }: DeliveryLineItemTableProps) {
  const { control } = form
  const { fields } = useFieldArray({ control, name: 'items' })
  const watchedItems = useWatch({ control, name: 'items' })

  if (fields.length === 0) {
    return <EmptyState message="No line items." description="Select a Sales Order to load its outstanding lines." />
  }

  return (
    <div className="overflow-x-auto rounded-md border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Item</TableHead>
            <TableHead className="text-right">Ordered Qty</TableHead>
            <TableHead className="text-right">Already Delivered</TableHead>
            <TableHead className="text-right">Remaining</TableHead>
            <TableHead className="text-right">Available Stock</TableHead>
            <TableHead className="w-36 text-right">Deliver Now</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {fields.map((field, index) => {
            const availableStock = watchedItems?.[index]?.availableStock ?? field.availableStock
            const cap = Math.min(field.remaining, availableStock)
            const stockIsBindingLimit = availableStock < field.remaining

            return (
              <TableRow key={field.id}>
                <TableCell>
                  <div className="font-medium">{field.item_code}</div>
                  <div className="text-xs text-muted-foreground">{field.item_name}</div>
                </TableCell>
                <TableCell className="text-right">{formatNumber(field.ordered)}</TableCell>
                <TableCell className="text-right">{formatNumber(field.alreadyDelivered)}</TableCell>
                <TableCell className="text-right">{formatNumber(field.remaining)}</TableCell>
                <TableCell className={cn('text-right', stockIsBindingLimit && 'font-medium text-destructive')}>
                  {formatNumber(availableStock)}
                </TableCell>
                <TableCell>
                  <FormField
                    control={control}
                    name={`items.${index}.deliverNow`}
                    render={({ field: deliverNowField }) => (
                      <FormItem className="gap-0">
                        <Input
                          type="number"
                          min={0}
                          max={cap}
                          step="1"
                          disabled={disabled || cap === 0}
                          {...deliverNowField}
                        />
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
    </div>
  )
}
