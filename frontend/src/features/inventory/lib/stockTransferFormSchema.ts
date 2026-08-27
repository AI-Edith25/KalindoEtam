import { z } from 'zod'
import { isValidQtyForCategory, parseLocaleQty, qtyErrorMessage } from '@/shared/lib/qty'

/**
 * One row per item added to the transfer — free-form (Add/Remove Row), same
 * as Stock Adjustment, since a transfer can move any item in the source
 * warehouse. `availableQty` is a read-only snapshot from the bulk
 * stock-balance lookup for the source warehouse (same lookup Delivery/Stock
 * Adjustment already use) — qty can't exceed it, mirroring Delivery's
 * "Cannot exceed available stock" ceiling. `qtyCategory` is a snapshot
 * populated when the item is selected — decides whether `qty` must be a
 * whole number or may carry up to 2 decimals (see @/shared/lib/qty).
 */
export const stockTransferLineRowSchema = z
  .object({
    item_id: z.string().min(1, 'Item is required'),
    item_code: z.string(),
    item_name: z.string(),
    qtyCategory: z.enum(['unit', 'weight']),
    availableQty: z.number(),
    qty: z.string().min(1, 'Qty is required'),
  })
  .superRefine((line, ctx) => {
    const value = parseLocaleQty(line.qty)

    if (!isValidQtyForCategory(line.qty, line.qtyCategory) || value <= 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: qtyErrorMessage(line.qtyCategory), path: ['qty'] })
      return
    }

    if (value > line.availableQty) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: `Cannot exceed available stock (${line.availableQty})`,
        path: ['qty'],
      })
    }
  })

export const stockTransferFormSchema = z
  .object({
    source_warehouse_id: z.string().min(1, 'Source warehouse is required'),
    destination_warehouse_id: z.string().min(1, 'Destination warehouse is required'),
    transfer_date: z.string().min(1, 'Transfer date is required'),
    remarks: z.string().optional().or(z.literal('')),
    items: z.array(stockTransferLineRowSchema).min(1, 'Add at least one line item'),
  })
  .refine((values) => values.source_warehouse_id !== values.destination_warehouse_id, {
    message: 'Destination warehouse must be different from the source warehouse',
    path: ['destination_warehouse_id'],
  })

export type StockTransferEditorValues = z.infer<typeof stockTransferFormSchema>
export type StockTransferLineRow = z.infer<typeof stockTransferLineRowSchema>

export const emptyStockTransferEditorValues: StockTransferEditorValues = {
  source_warehouse_id: '',
  destination_warehouse_id: '',
  transfer_date: '',
  remarks: '',
  items: [],
}
