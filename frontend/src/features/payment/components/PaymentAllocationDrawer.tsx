import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatDate } from '@/lib/utils'
import { fetchAccountsReceivables } from '../api/accountsReceivableApi'
import { allocatePayment } from '../api/paymentAllocationApi'
import type { ReceiptEntry } from '../types'

interface PaymentAllocationDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  receiptEntry: ReceiptEntry
}

/** Rupiah transactions are essentially never fractional, so the app-wide formatCurrency() rounds
    to whole Rupiah — but this dialog's outstanding figures genuinely carry cents (tax-rounding
    remainders), and hiding them here is what made this dialog un-usable for a sub-rupiah balance
    (see round2()'s own docblock). Used only in this component. */
const preciseCurrencyFormatter = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})
function formatCurrencyPrecise(value: number | string): string {
  return preciseCurrencyFormatter.format(Number(value))
}

/** Mirrors the backend's round($x, 2) fix (AccountsReceivableService::assertWithinOutstanding()) —
    every outstanding/remaining figure here is a clean 2-decimal Rupiah value; JS float subtraction
    of two such values (e.g. 7000001.86 - 7000000.00) can leave the same binary-float noise past the
    2nd decimal that broke the backend, so every comparison/display here goes through this first. */
function round2(value: number): number {
  const rounded = Math.round(value * 100) / 100
  return rounded === 0 ? 0 : rounded // normalizes -0 so it never renders as "-Rp 0"
}

/**
 * Applies an already-received Payment to one or more outstanding Invoices'
 * receivables. Each row has a checkbox and an amount input, each capped at
 * min(invoice outstanding, payment's remaining unallocated balance) — only
 * checked rows count toward the total and get submitted as a single
 * allocateBatch() call, atomic under the hood.
 */
export function PaymentAllocationDrawer({ open, onOpenChange, receiptEntry }: PaymentAllocationDrawerProps) {
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState<Record<string, boolean>>({})
  const [amounts, setAmounts] = useState<Record<string, string>>({})

  const unallocated = round2(Number(receiptEntry.unallocated_amount))

  const receivablesQuery = useQuery({
    queryKey: ['accounts-receivables', receiptEntry.customer_id],
    // Only ever opened for payment_type=customer (see IncomingPaymentDetailPage's own guard) — customer_id is always real here.
    queryFn: () => fetchAccountsReceivables({ customer_id: receiptEntry.customer_id ?? undefined, per_page: 100 }),
    enabled: open,
  })

  const outstanding = useMemo(
    () => (receivablesQuery.data?.data ?? []).filter((ar) => ar.status !== 'paid' && ar.invoice_id !== null),
    [receivablesQuery.data],
  )

  useEffect(() => {
    if (open) {
      setAmounts({})
      setSelected({})
    }
  }, [open])

  const enteredTotal = round2(
    outstanding.reduce((sum, ar) => (selected[ar.id] ? sum + (Number(amounts[ar.id]) || 0) : sum), 0),
  )
  const remaining = round2(unallocated - enteredTotal)

  const lineError = (arId: string, cap: number): string | null => {
    const value = round2(Number(amounts[arId] ?? 0))
    if (value < 0) return 'Cannot be negative'
    if (value > cap) return `Cannot exceed ${formatCurrencyPrecise(cap)}`
    return null
  }

  const hasErrors = outstanding.some(
    (ar) => selected[ar.id] && lineError(ar.id, round2(Math.min(Number(ar.outstanding_amount), unallocated))) !== null,
  )
  const canSubmit = enteredTotal > 0 && remaining >= 0 && !hasErrors

  const mutation = useMutation({
    mutationFn: () =>
      allocatePayment(
        receiptEntry.id,
        outstanding
          .filter((ar) => selected[ar.id] && Number(amounts[ar.id]) > 0)
          .map((ar) => ({ accounts_receivable_id: ar.id, amount: round2(Number(amounts[ar.id])) })),
      ),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['receipt-entries'] })
      queryClient.invalidateQueries({ queryKey: ['accounts-receivables'] })
      toast.success('Payment allocated.')
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-lg">
        <SheetHeader>
          <SheetTitle>Allocate Payment</SheetTitle>
          <SheetDescription>
            {receiptEntry.document_number} — unallocated balance: {formatCurrencyPrecise(unallocated)}
          </SheetDescription>
          <div className="flex items-center justify-between text-sm">
            <span className="text-muted-foreground">Amount to Allocate</span>
            <span className="font-medium">{formatCurrencyPrecise(enteredTotal)}</span>
          </div>
        </SheetHeader>

        <div className="flex flex-col gap-4 overflow-y-auto px-4">
          {receivablesQuery.isLoading ? (
            <div className="flex justify-center py-8">
              <Loader2 className="size-6 animate-spin text-muted-foreground" />
            </div>
          ) : outstanding.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">No outstanding invoices for this customer.</p>
          ) : (
            outstanding.map((ar) => {
              const cap = round2(Math.min(Number(ar.outstanding_amount), unallocated))
              const error = lineError(ar.id, cap)

              return (
                <div key={ar.id} className="flex flex-col gap-1.5 rounded-md border p-3">
                  <div className="flex items-center gap-2 text-sm">
                    <Checkbox
                      id={`select-${ar.id}`}
                      checked={!!selected[ar.id]}
                      onCheckedChange={(checked) => setSelected((prev) => ({ ...prev, [ar.id]: checked === true }))}
                    />
                    <Label htmlFor={`select-${ar.id}`} className="flex flex-1 items-center justify-between font-medium">
                      <span>{ar.invoice?.document_number ?? ar.reference_number}</span>
                      <span className="text-muted-foreground">{formatDate(ar.invoice?.invoice_date)}</span>
                    </Label>
                  </div>
                  <p className="text-xs text-muted-foreground">Outstanding: {formatCurrencyPrecise(ar.outstanding_amount)}</p>
                  <div className="flex items-center gap-2">
                    <RupiahInput
                      aria-label={`Amount to allocate to ${ar.invoice?.document_number ?? ar.reference_number}`}
                      decimals={2}
                      value={amounts[ar.id] ?? ''}
                      onChange={(value) => setAmounts((prev) => ({ ...prev, [ar.id]: value }))}
                    />
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setSelected((prev) => ({ ...prev, [ar.id]: true }))
                        setAmounts((prev) => ({ ...prev, [ar.id]: String(cap) }))
                      }}
                    >
                      Full
                    </Button>
                  </div>
                  {error && <p className="text-xs text-destructive">{error}</p>}
                </div>
              )
            })
          )}
        </div>

        <SheetFooter>
          <div className="flex items-center justify-between text-sm">
            <span className="text-muted-foreground">Remaining to allocate</span>
            <span className={remaining < 0 ? 'font-medium text-destructive' : 'font-medium'}>{formatCurrencyPrecise(remaining)}</span>
          </div>
          <Button onClick={() => mutation.mutate()} disabled={!canSubmit || mutation.isPending}>
            {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
            Confirm Allocation
          </Button>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={mutation.isPending}>
            Cancel
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}
