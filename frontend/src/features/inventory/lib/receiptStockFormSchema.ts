import { z } from 'zod'
import { isValidQtyForCategory, qtyErrorMessage } from '@/shared/lib/qty'

export const receiptStockLineRowSchema = z
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

export const receiptStockFormSchema = z.object({
  warehouse_id: z.string().min(1, 'Warehouse is required'),
  receipt_date: z.string().min(1, 'Date is required'),
  remarks: z.string().optional().or(z.literal('')),
  items: z.array(receiptStockLineRowSchema).min(1, 'Add at least one line item'),
})

export type ReceiptStockEditorValues = z.infer<typeof receiptStockFormSchema>
export type ReceiptStockLineRow = z.infer<typeof receiptStockLineRowSchema>

export const emptyReceiptStockEditorValues: ReceiptStockEditorValues = {
  warehouse_id: '',
  receipt_date: '',
  remarks: '',
  items: [],
}
