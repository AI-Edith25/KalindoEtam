import { z } from 'zod'

/**
 * Paying money only — payment_type-specific header fields and an amount.
 * Payable selection/allocation (mirrors receiptEntryFormSchema.ts's Invoice
 * selection) lives as separate, un-validated component state on
 * OutgoingPaymentEditorPage, not here — it's optional and doesn't shape
 * what a valid Payment Entry itself is.
 *
 * One schema, two branches by payment_type — Supplier requires
 * supplier_id + amount; General Expense requires Category + Description +
 * amount.
 */
export const paymentEntryFormSchema = z
  .object({
    payment_type: z.enum(['supplier', 'general_expense']),
    supplier_id: z.string(),
    expense_account_id: z.string(),
    description: z.string(),
    amount: z.string(),
    payment_date: z.string().min(1, 'Payment date is required'),
    cash_account_id: z.string().min(1, 'Cash/Bank account is required'),
    branch_id: z.string().optional().or(z.literal('')),
    reference_number: z.string().optional().or(z.literal('')),
    remarks: z.string().optional().or(z.literal('')),
  })
  .superRefine((values, ctx) => {
    const amount = Number(values.amount)

    if (values.amount.trim() === '' || Number.isNaN(amount) || amount <= 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Amount must be greater than zero', path: ['amount'] })
      return
    }

    if (values.payment_type === 'supplier') {
      if (!values.supplier_id) {
        ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Supplier is required', path: ['supplier_id'] })
      }
    } else {
      if (!values.expense_account_id) {
        ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Category is required', path: ['expense_account_id'] })
      }
      if (values.description.trim() === '') {
        ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Description is required', path: ['description'] })
      }
    }
  })

export type PaymentEntryEditorValues = z.infer<typeof paymentEntryFormSchema>
