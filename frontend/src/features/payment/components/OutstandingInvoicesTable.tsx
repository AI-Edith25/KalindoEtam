import { Loader2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Checkbox } from '@/components/ui/checkbox'
import { EmptyState } from '@/components/shared/EmptyState'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatCurrency, formatDate } from '@/lib/utils'
import { computeWaterfallAllocations } from '../lib/paymentAllocationWaterfall'
import type { AccountsReceivable } from '../types'

interface OutstandingInvoicesTableProps {
  receivables: AccountsReceivable[]
  isLoading: boolean
  selectedIds: Set<string>
  onToggle: (id: string, checked: boolean) => void
  paymentAmount: number
}

/**
 * Sprint 1 (Invoice Allocation): shown once a Customer is picked on the
 * Incoming Payment form, listing every Unpaid/Partially Paid invoice for
 * them. Checking rows previews how computeWaterfallAllocations() would
 * split the entered payment amount across them — the actual allocation
 * only happens server-side, via the existing allocateBatch() call, once
 * the payment is confirmed.
 */
export function OutstandingInvoicesTable({
  receivables,
  isLoading,
  selectedIds,
  onToggle,
  paymentAmount,
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

  const allocations = computeWaterfallAllocations(receivables, selectedIds, paymentAmount)
  const allocatedById = new Map(allocations.map((line) => [line.accounts_receivable_id, line.amount]))
  const totalAllocated = allocations.reduce((sum, line) => sum + line.amount, 0)
  const allChecked = receivables.length > 0 && receivables.every((ar) => selectedIds.has(ar.id))

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
              const checked = selectedIds.has(ar.id)
              const toAllocate = allocatedById.get(ar.id) ?? 0

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
                  <TableCell className="text-right">{checked ? formatCurrency(toAllocate) : '—'}</TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </div>

      {selectedIds.size > 0 && (
        <div className="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2 text-sm">
          <span className="text-muted-foreground">Total to allocate</span>
          <span className="font-medium">
            {formatCurrency(totalAllocated)}
            {paymentAmount > totalAllocated && (
              <span className="ml-2 text-xs text-muted-foreground">
                ({formatCurrency(paymentAmount - totalAllocated)} left unallocated)
              </span>
            )}
          </span>
        </div>
      )}
    </div>
  )
}
