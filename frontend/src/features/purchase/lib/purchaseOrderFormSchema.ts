import { z } from 'zod'

/**
 * Qty/rate are kept as strings and converted to numbers only when
 * building the API payload (see PurchaseOrderEditorPage's onSubmit) —
 * same string-then-convert pattern as every other numeric form field
 * in this codebase, avoiding the z.coerce.number() + zodResolver type
 * mismatch documented in docs/ERP_DESIGN_SYSTEM.md §6.
 */
export const lineItemFormSchema = z.object({
  item_id: z.string().min(1, 'Item is required'),
  qty: z
    .string()
    .min(1, 'Qty is required')
    .refine((value) => Number.isInteger(Number(value)) && Number(value) >= 1, 'Must be at least 1'),
  rate: z
    .string()
    .min(1, 'Rate is required')
    .refine((value) => !Number.isNaN(Number(value)) && Number(value) >= 0, 'Must be zero or greater'),
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
