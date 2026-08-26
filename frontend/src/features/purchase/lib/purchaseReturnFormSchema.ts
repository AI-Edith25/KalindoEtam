import { z } from 'zod'

export const purchaseReturnFormSchema = z.object({
  return_date: z.string().min(1, 'Return date is required'),
  reason: z.string().min(1, 'Reason is required'),
  tax_amount: z.string().refine((value) => value === '' || (!Number.isNaN(Number(value)) && Number(value) >= 0), 'Must be zero or greater'),
  remarks: z.string().optional().or(z.literal('')),
})

export type PurchaseReturnEditorValues = z.infer<typeof purchaseReturnFormSchema>

export const emptyPurchaseReturnEditorValues: PurchaseReturnEditorValues = {
  return_date: '',
  reason: '',
  tax_amount: '',
  remarks: '',
}
