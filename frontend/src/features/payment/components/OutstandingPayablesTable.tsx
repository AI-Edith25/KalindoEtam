import { useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { EmptyState } from '@/components/shared/EmptyState'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatCurrency, formatDate } from '@/lib/utils'
import { fetchPurchaseOrders } from '@/features/purchase/api/purchaseOrderApi'
import type { AccountsPayable } from '../types'

interface OutstandingPayablesTableProps {
  payables: AccountsPayable[]
  isLoading: boolean
  allocations: Map<string, number>
  onToggle: (id: string, checked: boolean) => void
  onAllocationChange: (id: string, amount: number) => void
}

/**
 * AP mirror of OutstandingInvoicesTable — shown once a Supplier is picked
 * on the Payment Voucher form, listing every Unpaid/Partially Paid bill for
 * them. Checking a row defaults its "To Allocate" to that bill's full
 * outstanding balance, editable from there — "Amount Paid" above is
 * derived as the sum of these rows, not the other way around. The actual
 * allocation only happens server-side, via allocateBatch(), once the
 * payment is confirmed.
 */
export function OutstandingPayablesTable({
  payables,
  isLoading,
  allocations,
  onToggle,
  onAllocationChange,
}: OutstandingPayablesTableProps) {
  // AccountsPayable exposes purchase_order_id only, not a nested object — same lookup-join pattern as OutstandingPayableSelect.
  const purchaseOrdersLookup = useQuery({
    queryKey: ['purchase-orders-lookup'],
    queryFn: () => fetchPurchaseOrders({ page: 1, per_page: 100 }),
  })
  const purchaseOrderNumber = (purchaseOrderId: string) =>
    purchaseOrdersLookup.data?.data.find((po) => po.id === purchaseOrderId)?.document_number ?? '—'

  if (isLoading) {
    return (
      <div className="flex justify-center py-8">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (payables.length === 0) {
    return <EmptyState message="No outstanding payables" description="This supplier has no unpaid or partially paid bills." />
  }

  const totalAllocated = Array.from(allocations.values()).reduce((sum, amt) => sum + amt, 0)
  const allChecked = payables.length > 0 && payables.every((ap) => allocations.has(ap.id))

  return (
    <div className="flex flex-col gap-2">
      <div className="overflow-x-auto rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-10">
                <Checkbox
                  checked={allChecked}
                  onCheckedChange={(checked) => payables.forEach((ap) => onToggle(ap.id, checked === true))}
                  aria-label="Select all outstanding payables"
                />
              </TableHead>
              <TableHead>Purchase Order</TableHead>
              <TableHead>Due Date</TableHead>
              <TableHead className="text-right">Total Bill</TableHead>
              <TableHead className="text-right">Amount Paid</TableHead>
              <TableHead className="text-right">Outstanding</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="text-right">To Allocate</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {payables.map((ap) => {
              const checked = allocations.has(ap.id)
              const amount = allocations.get(ap.id) ?? 0
              const outstanding = Number(ap.outstanding_amount)

              return (
                <TableRow key={ap.id} data-state={checked ? 'selected' : undefined}>
                  <TableCell>
                    <Checkbox
                      checked={checked}
                      onCheckedChange={(value) => onToggle(ap.id, value === true)}
                      aria-label={`Select ${purchaseOrderNumber(ap.purchase_order_id)}`}
                    />
                  </TableCell>
                  <TableCell className="font-medium">{purchaseOrderNumber(ap.purchase_order_id)}</TableCell>
                  <TableCell>{formatDate(ap.due_date)}</TableCell>
                  <TableCell className="text-right">{formatCurrency(ap.amount)}</TableCell>
                  <TableCell className="text-right">{formatCurrency(ap.paid_amount)}</TableCell>
                  <TableCell className="text-right">{formatCurrency(ap.outstanding_amount)}</TableCell>
                  <TableCell>
                    <StatusBadge status={ap.status} />
                  </TableCell>
                  <TableCell className="text-right">
                    {checked ? (
                      <Input
                        type="number"
                        step="0.01"
                        min={0}
                        max={outstanding}
                        value={amount}
                        className="ml-auto w-32 text-right"
                        aria-label={`To allocate for ${purchaseOrderNumber(ap.purchase_order_id)}`}
                        onChange={(e) => {
                          const raw = e.target.value === '' ? 0 : Number(e.target.value)
                          onAllocationChange(ap.id, Math.min(Math.max(raw, 0), outstanding))
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
