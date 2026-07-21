import { z } from 'zod'

/**
 * outstandingAmount is a client-side snapshot taken when the source
 * document is picked (not sent to the API) — used only so `amount` can be
 * refined against it here, same "editable string field + read-only
 * snapshot number" shape as goodsReceiptFormSchema's `remaining`.
 */
export const paymentEntryFormSchema = z
  .object({
    supplier_id: z.string().min(1, 'Supplier is required'),
    accounts_payable_id: z.string().min(1, 'Select a source document'),
    outstandingAmount: z.number(),
    amount: z.string(),
    payment_date: z.string().min(1, 'Payment date is required'),
    payment_method: z.string().min(1, 'Payment method is required'),
    reference_number: z.string().optional().or(z.literal('')),
    remarks: z.string().optional().or(z.literal('')),
  })
  .superRefine((values, ctx) => {
    const amount = Number(values.amount)

    if (values.amount.trim() === '' || Number.isNaN(amount) || amount <= 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Amount must be greater than zero', path: ['amount'] })
      return
    }

    if (amount > values.outstandingAmount) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: `Cannot exceed outstanding (${values.outstandingAmount})`,
        path: ['amount'],
      })
    }
  })

export type PaymentEntryEditorValues = z.infer<typeof paymentEntryFormSchema>
