import { z } from 'zod'
import { isValidQtyForCategory, qtyErrorMessage } from '@/shared/lib/qty'

/**
 * Qty/rate are kept as strings and converted to numbers only when
 * building the API payload (see PurchaseOrderEditorPage's onSubmit) —
 * same string-then-convert pattern as every other numeric form field
 * in this codebase, avoiding the z.coerce.number() + zodResolver type
 * mismatch documented in docs/ERP_DESIGN_SYSTEM.md §6. `qtyCategory` is a
 * snapshot populated when the item is selected — decides whether `qty`
 * must be a whole number or may carry up to 2 decimals (see @/shared/lib/qty).
 */
export const lineItemFormSchema = z
  .object({
    item_id: z.string().min(1, 'Item is required'),
    qtyCategory: z.enum(['unit', 'weight']),
    qty: z.string().min(1, 'Qty is required'),
    rate: z
      .string()
      .min(1, 'Rate is required')
      .refine((value) => !Number.isNaN(Number(value)) && Number(value) >= 0, 'Must be zero or greater'),
    // Defaults from the selected Item's purchase_tax_id when the line is first added,
    // editable per line thereafter — see PurchaseOrderLineItemTable's handleItemChange().
    tax_id: z.string(),
  })
  .superRefine((line, ctx) => {
    if (!isValidQtyForCategory(line.qty, line.qtyCategory) || Number(line.qty.replace(',', '.')) <= 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: qtyErrorMessage(line.qtyCategory), path: ['qty'] })
    }
  })

export const purchaseOrderFormSchema = z.object({
  supplier_id: z.string().min(1, 'Supplier is required'),
  order_date: z.string().min(1, 'Order date is required'),
  expected_delivery_date: z.string().optional().or(z.literal('')),
  tax_id: z.string(),
  remarks: z.string().optional().or(z.literal('')),
  items: z.array(lineItemFormSchema).min(1, 'Add at least one line item'),
})

export type PurchaseOrderEditorValues = z.infer<typeof purchaseOrderFormSchema>

export const emptyPurchaseOrderEditorValues: PurchaseOrderEditorValues = {
  supplier_id: '',
  order_date: '',
  expected_delivery_date: '',
  tax_id: '',
  remarks: '',
  items: [],
}
