import { z } from 'zod'

/**
 * Receiving payment only — customer, date, method, and a total amount.
 * Invoice selection (Sprint 1: Invoice Allocation) lives as separate,
 * un-validated component state on IncomingPaymentEditorPage, not here —
 * it's optional and doesn't shape what a valid Receipt Entry itself is.
 */
export const receiptEntryFormSchema = z
  .object({
    customer_id: z.string().min(1, 'Customer is required'),
    total_amount: z.string(),
    receipt_date: z.string().min(1, 'Receipt date is required'),
    cash_account_id: z.string().min(1, 'Cash/Bank account is required'),
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
