import { z } from 'zod'

/**
 * Receiving payment only — payment_type-specific header fields, date, method, and a total
 * amount. Invoice selection (Sprint 1: Invoice Allocation) lives as separate, un-validated
 * component state on IncomingPaymentEditorPage, not here — it's optional and doesn't shape
 * what a valid Receipt Entry itself is, and only applies to payment_type=customer anyway.
 *
 * Two branches by payment_type, mirroring paymentEntryFormSchema.ts's own supplier/
 * general_expense split: Customer requires customer_id; Other Income requires an Income
 * Account + Description (no customer, never allocated).
 */
export const receiptEntryFormSchema = z
  .object({
    payment_type: z.enum(['customer', 'other_income']),
    customer_id: z.string(),
    income_account_id: z.string(),
    description: z.string(),
    total_amount: z.string(),
    receipt_date: z.string().min(1, 'Receipt date is required'),
    cash_account_id: z.string().min(1, 'Cash/Bank account is required'),
    branch_id: z.string().optional().or(z.literal('')),
    reference_number: z.string().optional().or(z.literal('')),
    remarks: z.string().optional().or(z.literal('')),
    payment_method: z.string().min(1, 'Payment Method is required'),
    giro_number: z.string().optional().or(z.literal('')),
    giro_due_date: z.string().optional().or(z.literal('')),
  })
  .superRefine((values, ctx) => {
    const amount = Number(values.total_amount)

    if (values.total_amount.trim() === '' || Number.isNaN(amount) || amount <= 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Amount must be greater than zero', path: ['total_amount'] })
    }

    if (values.payment_type === 'customer') {
      if (!values.customer_id) {
        ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Customer is required', path: ['customer_id'] })
      }
    } else {
      if (!values.income_account_id) {
        ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Income Account is required', path: ['income_account_id'] })
      }
      if (values.description.trim() === '') {
        ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Description is required', path: ['description'] })
      }
    }

    if (values.payment_method === 'giro' || values.payment_method === 'cheque') {
      if (!values.giro_number?.trim()) {
        ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Number is required for Giro/Cek', path: ['giro_number'] })
      }
      if (!values.giro_due_date?.trim()) {
        ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Due date is required for Giro/Cek', path: ['giro_due_date'] })
      }
    }
  })

export type ReceiptEntryEditorValues = z.infer<typeof receiptEntryFormSchema>
