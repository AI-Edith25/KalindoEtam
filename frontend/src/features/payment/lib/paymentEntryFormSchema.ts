import { z } from 'zod'

/**
 * Paying money only — payment_type-specific header fields and an amount.
 * Payable selection/allocation (mirrors receiptEntryFormSchema.ts's Invoice
 * selection) lives as separate, un-validated component state on
 * OutgoingPaymentEditorPage, not here — it's optional and doesn't shape
 * what a valid Payment Entry itself is. Mixed's own allocation LINES are the
 * same story (separate component state, validated by mixedVoucherLinesSchema
 * below) — this schema only covers the header.
 *
 * One schema, three branches by payment_type — Supplier requires
 * supplier_id + amount; General Expense requires Category + Description +
 * amount; Mixed requires only amount (its lines carry their own
 * supplier/category per row, validated separately).
 */
export const paymentEntryFormSchema = z
  .object({
    payment_type: z.enum(['supplier', 'general_expense', 'mixed']),
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
    } else if (values.payment_type === 'general_expense') {
      if (!values.expense_account_id) {
        ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Category is required', path: ['expense_account_id'] })
      }
      if (values.description.trim() === '') {
        ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Description is required', path: ['description'] })
      }
    }
  })

export type PaymentEntryEditorValues = z.infer<typeof paymentEntryFormSchema>

/** One row of OutgoingPaymentEditorPage's own mixed-mode `lines` state — not yet the
    PaymentVoucherLineInput the API expects (that's built at submit time), since a
    partially-filled row (e.g. Purpose Type picked but nothing else yet) has to render without
    throwing. `client_id` is a local-only React key, never sent to the server. */
export interface MixedVoucherLineDraft {
  client_id: string
  type: 'supplier' | 'expense'
  /** Which supplier this row is currently browsing bills for — a row-local concern (each row
      can pick a different supplier), not sent to the server; only accounts_payable_id is. */
  accounts_payable_supplier_id: string
  accounts_payable_id: string
  accounts_payable_reference: string | null
  accounts_payable_outstanding: number | null
  expense_account_id: string
  description: string
  branch_id: string
  amount: string
  notes: string
}

/**
 * Validates a mixed voucher's lines against the rules from the ticket: at least one line,
 * every amount > 0, no duplicate bill, every expense line has a Category, and the lines'
 * total doesn't exceed the header's own Amount Paid (a shortfall is allowed — it's recorded as
 * unapplied, same concept the supplier flow already has — but never an overflow). Returns
 * plain messages rather than a zod schema since these rows live in component state, not
 * react-hook-form (mirrors how supplier's own `allocations` Map is validated ad hoc today).
 */
export function validateMixedVoucherLines(lines: MixedVoucherLineDraft[], headerAmount: number): string[] {
  const errors: string[] = []

  if (lines.length === 0) {
    errors.push('At least one payment allocation line is required.')
    return errors
  }

  const seenPayables = new Set<string>()
  let total = 0

  for (const line of lines) {
    const amount = Number(line.amount)

    if (line.amount.trim() === '' || Number.isNaN(amount) || amount <= 0) {
      errors.push('Every allocation line needs an amount greater than zero.')
      continue
    }

    total += amount

    if (line.type === 'supplier') {
      if (!line.accounts_payable_id) {
        errors.push('Every Supplier Bill line needs a bill selected.')
        continue
      }
      if (seenPayables.has(line.accounts_payable_id)) {
        errors.push(`${line.accounts_payable_reference ?? 'This bill'} is already added — combine it into one line instead of adding it twice.`)
        continue
      }
      seenPayables.add(line.accounts_payable_id)

      if (line.accounts_payable_outstanding != null && amount > line.accounts_payable_outstanding) {
        errors.push(`Amount for ${line.accounts_payable_reference ?? 'this bill'} exceeds its outstanding balance.`)
      }
    } else {
      if (!line.expense_account_id) {
        errors.push('Every General Expense line needs a Category.')
      }
    }
  }

  const rounded = Math.round(total * 100) / 100
  if (rounded > headerAmount) {
    errors.push(`Allocation lines total (${rounded}) exceeds Amount Paid (${headerAmount}).`)
  }

  return errors
}
