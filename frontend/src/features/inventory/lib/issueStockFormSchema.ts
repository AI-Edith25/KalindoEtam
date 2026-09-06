import { z } from 'zod'
import { isValidQtyForCategory, qtyErrorMessage } from '@/shared/lib/qty'

/**
 * One row per item — no Unit Cost input, unlike OpeningStock/ReceiptStock's line schema: the
 * ticket's whole point is that this column is system-computed (FifoLayerService::consume()'s
 * weighted-average result), never typed by the user.
 */
export const issueStockLineRowSchema = z
  .object({
    item_id: z.string().min(1, 'Item is required'),
    item_code: z.string(),
    item_name: z.string(),
    qtyCategory: z.enum(['unit', 'weight']),
    qty: z.string().min(1, 'Qty is required'),
  })
  .superRefine((line, ctx) => {
    if (!isValidQtyForCategory(line.qty, line.qtyCategory) || Number(line.qty.replace(',', '.')) <= 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: qtyErrorMessage(line.qtyCategory), path: ['qty'] })
    }
  })

export const issueStockFormSchema = z.object({
  warehouse_id: z.string().min(1, 'Warehouse is required'),
  issue_date: z.string().min(1, 'Date is required'),
  remarks: z.string().optional().or(z.literal('')),
  items: z.array(issueStockLineRowSchema).min(1, 'Add at least one line item'),
})

export type IssueStockEditorValues = z.infer<typeof issueStockFormSchema>
export type IssueStockLineRow = z.infer<typeof issueStockLineRowSchema>

export const emptyIssueStockEditorValues: IssueStockEditorValues = {
  warehouse_id: '',
  issue_date: '',
  remarks: '',
  items: [],
}
