import { z } from 'zod'

/**
 * outstandingAmount is a client-side snapshot taken when the source
 * document is picked (not sent to the API) — used only so `amount` can be
 * refined against it here, same "editable string field + read-only
 * snapshot number" shape as goodsReceiptFormSchema's `remaining`.
 *
 * One schema, two branches by payment_type — Supplier keeps every existing
 * rule unchanged; General Expense (Category + Description, no
 * Supplier/Source Document) is new.
 */
export const paymentEntryFormSchema = z
  .object({
    payment_type: z.enum(['supplier', 'general_expense']),
    supplier_id: z.string(),
    accounts_payable_id: z.string(),
    outstandingAmount: z.number(),
    expense_account_id: z.string(),
    description: z.string(),
    amount: z.string(),
    payment_date: z.string().min(1, 'Payment date is required'),
    cash_account_id: z.string().min(1, 'Cash/Bank account is required'),
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
      if (!values.accounts_payable_id) {
        ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Select a source document', path: ['accounts_payable_id'] })
      }
      if (amount > values.outstandingAmount) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          message: `Cannot exceed outstanding (${values.outstandingAmount})`,
          path: ['amount'],
        })
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
