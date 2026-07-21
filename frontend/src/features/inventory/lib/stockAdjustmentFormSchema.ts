import { z } from 'zod'

/**
 * One row per item added to the count — free-form (Add/Remove Row), not
 * derived from a parent document, since a physical count can include any
 * item in the warehouse. `countedQty` is kept as a string and converted
 * to a number only when building the API payload — same string-then-
 * convert pattern as every other numeric form field in this codebase.
 * No upper bound tied to systemQty: unlike Delivery's "Remaining Qty"
 * ceiling, a physical count can legitimately be higher OR lower than
 * what the system currently says — that disagreement is the entire point
 * of an adjustment.
 */
export const stockAdjustmentLineRowSchema = z.object({
  item_id: z.string().min(1, 'Item is required'),
  item_code: z.string(),
  item_name: z.string(),
  systemQty: z.number(),
  countedQty: z
    .string()
    .min(1, 'Physical qty is required')
    .refine((value) => Number.isInteger(Number(value)) && Number(value) >= 0, 'Must be zero or a positive whole number'),
  reason: z.string().min(1, 'Reason is required'),
})

export const stockAdjustmentFormSchema = z.object({
  warehouse_id: z.string().min(1, 'Warehouse is required'),
  adjustment_date: z.string().min(1, 'Adjustment date is required'),
  remarks: z.string().optional().or(z.literal('')),
  items: z.array(stockAdjustmentLineRowSchema).min(1, 'Add at least one line item'),
})

export type StockAdjustmentEditorValues = z.infer<typeof stockAdjustmentFormSchema>
export type StockAdjustmentLineRow = z.infer<typeof stockAdjustmentLineRowSchema>

export const emptyStockAdjustmentEditorValues: StockAdjustmentEditorValues = {
  warehouse_id: '',
  adjustment_date: '',
  remarks: '',
  items: [],
}
