import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Input } from '@/components/ui/input'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { formatNumber } from '@/lib/utils'
import type { GoodsReceiptEditorValues } from '../lib/goodsReceiptFormSchema'

interface GoodsReceiptLineItemTableProps {
  form: UseFormReturn<GoodsReceiptEditorValues>
  disabled?: boolean
}

/**
 * Fixed rows, one per Purchase Order line — no Add Row, no item lookup,
 * unlike PurchaseOrderLineItemTable. Ordered/Already Received/Remaining
 * are a read-only snapshot from the selected PO; only Receive Now is
 * editable, bounded to [0, remaining] by the zod schema.
 */
export function GoodsReceiptLineItemTable({ form, disabled }: GoodsReceiptLineItemTableProps) {
  const { control } = form
  const { fields } = useFieldArray({ control, name: 'items' })
  const watchedItems = useWatch({ control, name: 'items' })

  if (fields.length === 0) {
    return <EmptyState message="No line items." description="Select a Purchase Order to load its outstanding lines." />
  }

  return (
    <div className="overflow-x-auto rounded-md border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Item</TableHead>
            <TableHead className="text-right">Ordered Qty</TableHead>
            <TableHead className="text-right">Already Received</TableHead>
            <TableHead className="text-right">Remaining</TableHead>
            <TableHead className="w-36 text-right">Receive Now</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {fields.map((field, index) => {
            const receiveNow = Number(watchedItems?.[index]?.receiveNow || 0)
            const overReceived = field.allowOverReceipt && receiveNow > field.remaining ? receiveNow - field.remaining : 0

            return (
              <TableRow key={field.id}>
                <TableCell>
                  <div className="font-medium">{field.item_code}</div>
                  <div className="text-xs text-muted-foreground">{field.item_name}</div>
                </TableCell>
                <TableCell className="text-right">{formatNumber(field.ordered)}</TableCell>
                <TableCell className="text-right">{formatNumber(field.alreadyReceived)}</TableCell>
                <TableCell className="text-right">{formatNumber(field.remaining)}</TableCell>
                <TableCell>
                  <FormField
                    control={control}
                    name={`items.${index}.receiveNow`}
                    render={({ field: receiveNowField }) => (
                      <FormItem className="gap-0">
                        <Input
                          type="number"
                          min={0}
                          max={field.allowOverReceipt ? undefined : field.remaining}
                          step="0.01"
                          disabled={disabled || (field.remaining === 0 && !field.allowOverReceipt)}
                          {...receiveNowField}
                        />
                        {overReceived > 0 && (
                          <p className="text-xs text-amber-600">Over-received +{formatNumber(overReceived)}</p>
                        )}
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
