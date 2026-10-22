import { useWatch, type UseFormReturn } from 'react-hook-form'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { LineItemTableScroll, STICKY_FIRST_COL } from '@/components/shared/LineItemTableScroll'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { formatCurrency } from '@/lib/utils'
import { formatQty } from '@/shared/lib/qty'
import type { PurchaseInvoiceEditorValues } from '../lib/purchaseInvoiceFormSchema'

interface GoodsReceiptSourcedLineItemTableProps {
  form: UseFormReturn<PurchaseInvoiceEditorValues>
}

/**
 * Goods Receipt-sourced Purchase Invoice — Item/Qty stay locked to the source Goods Receipt
 * line (Qty always reflects what was actually received into stock), only Rate is editable.
 * No Add/Remove row — the line set itself is fixed, only Rate within each line can change.
 */
export function GoodsReceiptSourcedLineItemTable({ form }: GoodsReceiptSourcedLineItemTableProps) {
  const { control } = form
  const fields = useWatch({ control, name: 'items' }) ?? []

  return (
    <LineItemTableScroll>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className={STICKY_FIRST_COL}>Item Code</TableHead>
            <TableHead>Item Name</TableHead>
            <TableHead className="w-28 text-right">Qty</TableHead>
            <TableHead className="w-36">Rate</TableHead>
            <TableHead className="w-36 text-right">Amount</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {fields.length === 0 ? (
            <TableRow>
              <TableCell colSpan={5} className="p-0">
                <EmptyState message="No line items." />
              </TableCell>
            </TableRow>
          ) : (
            fields.map((line, index) => (
              <TableRow key={line.id}>
                <TableCell className={STICKY_FIRST_COL}>{line.item_code}</TableCell>
                <TableCell>{line.item_name}</TableCell>
                <TableCell className="text-right">{formatQty(line.qty, line.qty_category ?? 'unit')}</TableCell>
                <TableCell className="min-w-36">
                  <FormField
                    control={control}
                    name={`items.${index}.rate`}
                    render={({ field: rateField }) => (
                      <FormItem className="gap-0">
                        <RupiahInput value={rateField.value} onChange={rateField.onChange} decimals={2} aria-label="Rate" />
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </TableCell>
                <TableCell className="text-right font-medium">{formatCurrency(Number(line.qty) * Number(line.rate || 0))}</TableCell>
              </TableRow>
            ))
          )}
        </TableBody>
      </Table>
    </LineItemTableScroll>
  )
}
