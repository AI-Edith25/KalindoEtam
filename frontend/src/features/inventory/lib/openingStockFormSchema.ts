import { z } from 'zod'
import { isValidQtyForCategory, qtyErrorMessage } from '@/shared/lib/qty'

/**
 * One row per (item, cost) pair — free-form (Add/Remove Row). The same item can appear on more
 * than one row at a different cost: that's the entire point of FIFO layers, so this is never
 * deduplicated by item_id the way a typical line-item form might be.
 */
export const openingStockLineRowSchema = z
  .object({
    item_id: z.string().min(1, 'Item is required'),
    item_code: z.string(),
    item_name: z.string(),
    qtyCategory: z.enum(['unit', 'weight']),
    qty: z.string().min(1, 'Qty is required'),
    unitCost: z
      .string()
      .min(1, 'Unit Cost is required')
      .refine((value) => !Number.isNaN(Number(value.replace(',', '.'))) && Number(value.replace(',', '.')) >= 0, 'Must be zero or greater'),
  })
  .superRefine((line, ctx) => {
    if (!isValidQtyForCategory(line.qty, line.qtyCategory) || Number(line.qty.replace(',', '.')) <= 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: qtyErrorMessage(line.qtyCategory), path: ['qty'] })
    }
  })

export const openingStockFormSchema = z.object({
  warehouse_id: z.string().min(1, 'Warehouse is required'),
  cutoff_date: z.string().min(1, 'Cutoff date is required'),
  remarks: z.string().optional().or(z.literal('')),
  items: z.array(openingStockLineRowSchema).min(1, 'Add at least one line item'),
})

export type OpeningStockEditorValues = z.infer<typeof openingStockFormSchema>
export type OpeningStockLineRow = z.infer<typeof openingStockLineRowSchema>

export const emptyOpeningStockEditorValues: OpeningStockEditorValues = {
  warehouse_id: '',
  cutoff_date: '',
  remarks: '',
  items: [],
}
