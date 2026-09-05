import { Loader2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Checkbox } from '@/components/ui/checkbox'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { EmptyState } from '@/components/shared/EmptyState'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatCurrency, formatDate } from '@/lib/utils'
import type { AccountsReceivable } from '../types'

interface OutstandingInvoicesTableProps {
  receivables: AccountsReceivable[]
  isLoading: boolean
  allocations: Map<string, number>
  onToggle: (id: string, checked: boolean) => void
  onAllocationChange: (id: string, amount: number) => void
}

/**
 * Sprint 1 (Invoice Allocation): shown once a Customer is picked on the
 * Incoming Payment form, listing every Unpaid/Partially Paid invoice for
 * them. Checking a row defaults its "To Allocate" to that invoice's full
 * outstanding balance, editable from there — "Amount Received" above is
 * derived as the sum of these rows, not the other way around. The actual
 * allocation only happens server-side, via the existing allocateBatch()
 * call, once the payment is confirmed.
 */
export function OutstandingInvoicesTable({
  receivables,
  isLoading,
  allocations,
  onToggle,
  onAllocationChange,
}: OutstandingInvoicesTableProps) {
  if (isLoading) {
    return (
      <div className="flex justify-center py-8">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (receivables.length === 0) {
    return <EmptyState message="No outstanding invoices" description="This customer has no unpaid or partially paid invoices." />
  }

  const totalAllocated = Array.from(allocations.values()).reduce((sum, amt) => sum + amt, 0)
  const allChecked = receivables.length > 0 && receivables.every((ar) => allocations.has(ar.id))

  return (
    <div className="flex flex-col gap-2">
      <div className="overflow-x-auto rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-10">
                <Checkbox
                  checked={allChecked}
                  onCheckedChange={(checked) => receivables.forEach((ar) => onToggle(ar.id, checked === true))}
                  aria-label="Select all outstanding invoices"
                />
              </TableHead>
              <TableHead>Invoice Number</TableHead>
              <TableHead>Invoice Date</TableHead>
              <TableHead className="text-right">Total Invoice</TableHead>
              <TableHead className="text-right">Amount Paid</TableHead>
              <TableHead className="text-right">Outstanding</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="text-right">To Allocate</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {receivables.map((ar) => {
              const checked = allocations.has(ar.id)
              const amount = allocations.get(ar.id) ?? 0
              const outstanding = Number(ar.outstanding_amount)

              return (
                <TableRow key={ar.id} data-state={checked ? 'selected' : undefined}>
                  <TableCell>
                    <Checkbox
                      checked={checked}
                      onCheckedChange={(value) => onToggle(ar.id, value === true)}
                      aria-label={`Select ${ar.invoice?.document_number ?? ar.reference_number}`}
                    />
                  </TableCell>
                  <TableCell className="font-medium">{ar.invoice?.document_number ?? ar.reference_number}</TableCell>
                  <TableCell>{formatDate(ar.invoice?.invoice_date)}</TableCell>
                  <TableCell className="text-right">{formatCurrency(ar.amount)}</TableCell>
                  <TableCell className="text-right">{formatCurrency(ar.paid_amount)}</TableCell>
                  <TableCell className="text-right">{formatCurrency(ar.outstanding_amount)}</TableCell>
                  <TableCell>
                    <StatusBadge status={ar.status} />
                  </TableCell>
                  <TableCell className="text-right">
                    {checked ? (
                      <RupiahInput
                        value={String(amount)}
                        className="ml-auto w-32 text-right"
                        aria-label={`To allocate for ${ar.invoice?.document_number ?? ar.reference_number}`}
                        onChange={(v) => {
                          const raw = v === '' ? 0 : Number(v)
                          // ponytail: hard-clamp on every keystroke instead of a separate over-limit
                          // error state, mirrors backend assertWithinOutstanding so the invariant can
                          // never be violated even mid-edit. Soften to type-freely+clamp-on-blur only
                          // if users complain the hard clamp fights their typing.
                          onAllocationChange(ar.id, Math.min(Math.max(raw, 0), outstanding))
                        }}
                      />
                    ) : (
                      '—'
                    )}
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </div>

      {allocations.size > 0 && (
        <div className="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2 text-sm">
          <span className="text-muted-foreground">Total to allocate</span>
          <span className="font-medium">{formatCurrency(totalAllocated)}</span>
        </div>
      )}
    </div>
  )
}
