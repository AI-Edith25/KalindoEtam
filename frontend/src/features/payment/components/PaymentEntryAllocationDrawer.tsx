import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency } from '@/lib/utils'
import { fetchAccountsPayables } from '../api/accountsPayableApi'
import { allocatePaymentEntry } from '../api/paymentEntryAllocationApi'
import type { PaymentEntry } from '../types'

interface PaymentEntryAllocationDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  paymentEntry: PaymentEntry
}

/**
 * Applies an already-paid Payment to one or more outstanding supplier
 * bills' payables for the same supplier. Each row has a checkbox and an
 * amount input, each capped at min(bill outstanding, payment's remaining
 * unallocated balance) — only checked rows count toward the total and get
 * submitted as a single allocateBatch() call, atomic under the hood.
 * Mirrors PaymentAllocationDrawer (Official Receipt's AR side).
 */
export function PaymentEntryAllocationDrawer({ open, onOpenChange, paymentEntry }: PaymentEntryAllocationDrawerProps) {
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState<Record<string, boolean>>({})
  const [amounts, setAmounts] = useState<Record<string, string>>({})

  const unallocated = Number(paymentEntry.unallocated_amount)

  const payablesQuery = useQuery({
    queryKey: ['accounts-payables', paymentEntry.supplier_id],
    queryFn: () => fetchAccountsPayables({ supplier_id: paymentEntry.supplier_id!, per_page: 100 }),
    enabled: open && !!paymentEntry.supplier_id,
  })

  const outstanding = useMemo(
    () => (payablesQuery.data?.data ?? []).filter((ap) => ap.status !== 'paid'),
    [payablesQuery.data],
  )

  useEffect(() => {
    if (open) {
      setAmounts({})
      setSelected({})
    }
  }, [open])

  const enteredTotal = outstanding.reduce(
    (sum, ap) => (selected[ap.id] ? sum + (Number(amounts[ap.id]) || 0) : sum),
    0,
  )
  const remaining = unallocated - enteredTotal

  const lineError = (apId: string, cap: number): string | null => {
    const value = Number(amounts[apId] ?? 0)
    if (value < 0) return 'Cannot be negative'
    if (value > cap) return `Cannot exceed ${formatCurrency(cap)}`
    return null
  }

  const hasErrors = outstanding.some(
    (ap) => selected[ap.id] && lineError(ap.id, Math.min(Number(ap.outstanding_amount), unallocated)) !== null,
  )
  const canSubmit = enteredTotal > 0 && remaining >= 0 && !hasErrors

  const mutation = useMutation({
    mutationFn: () =>
      allocatePaymentEntry(
        paymentEntry.id,
        outstanding
          .filter((ap) => selected[ap.id] && Number(amounts[ap.id]) > 0)
          .map((ap) => ({ accounts_payable_id: ap.id, amount: Number(amounts[ap.id]) })),
      ),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payment-entries'] })
      queryClient.invalidateQueries({ queryKey: ['accounts-payables'] })
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
            {paymentEntry.document_number} — unallocated balance: {formatCurrency(unallocated)}
          </SheetDescription>
          <div className="flex items-center justify-between text-sm">
            <span className="text-muted-foreground">Amount to Allocate</span>
            <span className="font-medium">{formatCurrency(enteredTotal)}</span>
          </div>
        </SheetHeader>

        <div className="flex flex-col gap-4 overflow-y-auto px-4">
          {payablesQuery.isLoading ? (
            <div className="flex justify-center py-8">
              <Loader2 className="size-6 animate-spin text-muted-foreground" />
            </div>
          ) : outstanding.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">No outstanding bills for this supplier.</p>
          ) : (
            outstanding.map((ap) => {
              const cap = Math.min(Number(ap.outstanding_amount), unallocated)
              const error = lineError(ap.id, cap)

              return (
                <div key={ap.id} className="flex flex-col gap-1.5 rounded-md border p-3">
                  <div className="flex items-center gap-2 text-sm">
                    <Checkbox
                      id={`select-${ap.id}`}
                      checked={!!selected[ap.id]}
                      onCheckedChange={(checked) => setSelected((prev) => ({ ...prev, [ap.id]: checked === true }))}
                    />
                    <Label htmlFor={`select-${ap.id}`} className="flex flex-1 items-center justify-between font-medium">
                      <span>{ap.reference_number}</span>
                      <span className="text-muted-foreground">{formatCurrency(ap.outstanding_amount)} outstanding</span>
                    </Label>
                  </div>
                  <Label htmlFor={`allocation-${ap.id}`} className="sr-only">
                    Amount to allocate to {ap.reference_number}
                  </Label>
                  <Input
                    id={`allocation-${ap.id}`}
                    type="number"
                    step="0.01"
                    placeholder="0"
                    value={amounts[ap.id] ?? ''}
                    onChange={(event) => setAmounts((prev) => ({ ...prev, [ap.id]: event.target.value }))}
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
