import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate } from '@/lib/utils'
import { fetchAccountsReceivables } from '../api/accountsReceivableApi'
import { allocatePayment } from '../api/paymentAllocationApi'
import type { ReceiptEntry } from '../types'

interface PaymentAllocationDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  receiptEntry: ReceiptEntry
}

/**
 * Applies an already-received Payment to one or more outstanding Invoices'
 * receivables for the same customer. One amount input per outstanding
 * invoice, each capped at min(invoice outstanding, payment's remaining
 * unallocated balance) — submits every non-zero line as a single
 * allocateBatch() call, atomic under the hood.
 */
export function PaymentAllocationDrawer({ open, onOpenChange, receiptEntry }: PaymentAllocationDrawerProps) {
  const queryClient = useQueryClient()
  const [amounts, setAmounts] = useState<Record<string, string>>({})

  const unallocated = Number(receiptEntry.unallocated_amount)

  const receivablesQuery = useQuery({
    queryKey: ['accounts-receivables', receiptEntry.customer_id],
    queryFn: () => fetchAccountsReceivables({ customer_id: receiptEntry.customer_id, per_page: 100 }),
    enabled: open,
  })

  const outstanding = useMemo(
    () => (receivablesQuery.data?.data ?? []).filter((ar) => ar.status !== 'paid' && ar.invoice_id !== null),
    [receivablesQuery.data],
  )

  useEffect(() => {
    if (open) setAmounts({})
  }, [open])

  const enteredTotal = Object.values(amounts).reduce((sum, value) => sum + (Number(value) || 0), 0)
  const remaining = unallocated - enteredTotal

  const lineError = (arId: string, cap: number): string | null => {
    const value = Number(amounts[arId] ?? 0)
    if (value < 0) return 'Cannot be negative'
    if (value > cap) return `Cannot exceed ${formatCurrency(cap)}`
    return null
  }

  const hasErrors = outstanding.some((ar) => lineError(ar.id, Math.min(Number(ar.outstanding_amount), unallocated)) !== null)
  const canSubmit = enteredTotal > 0 && remaining >= 0 && !hasErrors

  const mutation = useMutation({
    mutationFn: () =>
      allocatePayment(
        receiptEntry.id,
        Object.entries(amounts)
          .filter(([, value]) => Number(value) > 0)
          .map(([accounts_receivable_id, value]) => ({ accounts_receivable_id, amount: Number(value) })),
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
            {receiptEntry.document_number} — unallocated balance: {formatCurrency(unallocated)}
          </SheetDescription>
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
              const cap = Math.min(Number(ar.outstanding_amount), unallocated)
              const error = lineError(ar.id, cap)

              return (
                <div key={ar.id} className="flex flex-col gap-1.5 rounded-md border p-3">
                  <div className="flex items-center justify-between text-sm">
                    <span className="font-medium">{ar.invoice?.document_number ?? ar.reference_number}</span>
                    <span className="text-muted-foreground">{formatDate(ar.invoice?.invoice_date)}</span>
                  </div>
                  <p className="text-xs text-muted-foreground">Outstanding: {formatCurrency(ar.outstanding_amount)}</p>
                  <Label htmlFor={`allocation-${ar.id}`} className="sr-only">
                    Amount to allocate to {ar.invoice?.document_number}
                  </Label>
                  <Input
                    id={`allocation-${ar.id}`}
                    type="number"
                    step="0.01"
                    placeholder="0"
                    value={amounts[ar.id] ?? ''}
                    onChange={(event) => setAmounts((prev) => ({ ...prev, [ar.id]: event.target.value }))}
                  />
                  {error && <p className="text-xs text-destructive">{error}</p>}
                </div>
              )
            })
          )}
        </div>

        <SheetFooter>
          <div className="flex items-center justify-between text-sm">
            <span className="text-muted-foreground">Remaining to allocate</span>
            <span className={remaining < 0 ? 'font-medium text-destructive' : 'font-medium'}>{formatCurrency(remaining)}</span>
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
