import { z } from 'zod'

/**
 * Qty/rate are kept as strings and converted to numbers only when
 * building the API payload (see SalesOrderEditorPage's onSubmit) — same
 * string-then-convert pattern as Purchase Order, avoiding the
 * z.coerce.number() + zodResolver type mismatch documented in
 * docs/ERP_DESIGN_SYSTEM.md §6. No stock check here — Sales Order may
 * legitimately exceed current inventory; that validation belongs to
 * Delivery.
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

export const salesOrderFormSchema = z.object({
  customer_id: z.string().min(1, 'Customer is required'),
  order_date: z.string().min(1, 'Order date is required'),
  expected_delivery_date: z.string().optional().or(z.literal('')),
  remarks: z.string().optional().or(z.literal('')),
  items: z.array(lineItemFormSchema).min(1, 'Add at least one line item'),
})

export type SalesOrderEditorValues = z.infer<typeof salesOrderFormSchema>

export const emptySalesOrderEditorValues: SalesOrderEditorValues = {
  customer_id: '',
  order_date: '',
  expected_delivery_date: '',
  remarks: '',
  items: [],
}
