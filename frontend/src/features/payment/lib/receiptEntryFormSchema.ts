import { z } from 'zod'

/**
 * Sprint 12: receiving payment only — customer, date, method, and a total
 * amount. Applying it to specific invoices is a separate step (see
 * PaymentAllocationDrawer), so no invoice/receivable field lives here
 * anymore.
 */
export const receiptEntryFormSchema = z
  .object({
    customer_id: z.string().min(1, 'Customer is required'),
    total_amount: z.string(),
    receipt_date: z.string().min(1, 'Receipt date is required'),
    payment_method: z.string().min(1, 'Payment method is required'),
    reference_number: z.string().optional().or(z.literal('')),
    remarks: z.string().optional().or(z.literal('')),
  })
  .superRefine((values, ctx) => {
    const amount = Number(values.total_amount)

    if (values.total_amount.trim() === '' || Number.isNaN(amount) || amount <= 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Amount must be greater than zero', path: ['total_amount'] })
    }
  })

export type ReceiptEntryEditorValues = z.infer<typeof receiptEntryFormSchema>
