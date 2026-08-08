import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Check, Loader2, LockKeyhole, Save, X } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { EmptyState } from '@/components/shared/EmptyState'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import {
  applyInvoiceNominalChange,
  approveInvoiceChangeRequest,
  fetchInvoiceChangeRequests,
  rejectInvoiceChangeRequest,
  requestInvoiceChange,
} from '../api/invoiceChangeRequestApi'
import type { Invoice } from '../types'

interface NominalChangeRequestPanelProps {
  invoice: Invoice
  onChanged: () => void
}

/**
 * A Submitted Transportation Invoice's Rate/Amount/Grand Total have no write
 * path at all otherwise — this is the sole, audited, approval-gated
 * exception: request -> approve/reject -> (if approved) edit once -> auto
 * re-locks. See InvoiceChangeRequestService on the backend.
 */
export function NominalChangeRequestPanel({ invoice, onChanged }: NominalChangeRequestPanelProps) {
  const queryClient = useQueryClient()
  const [requesting, setRequesting] = useState(false)
  const [rejecting, setRejecting] = useState(false)
  const [requestReason, setRequestReason] = useState('')
  const [rejectRemarks, setRejectRemarks] = useState('')
  const [rates, setRates] = useState<Record<string, string>>({})

  const canApprove = useHasPermission('sales.invoices.approve')
  const canEdit = useHasPermission('sales.invoices.update')

  const historyQuery = useQuery({
    queryKey: ['invoice-change-requests', invoice.id],
    queryFn: () => fetchInvoiceChangeRequests(invoice.id),
    enabled: invoice.invoice_type === 'transportation' && invoice.status === 'submitted',
  })

  const history = historyQuery.data ?? []
  const latest = history[0] ?? null
  const unlocked = latest?.status === 'approved' && !latest.consumed_at
  // Terminal states only: rejected, or approved-and-already-consumed (a completed prior
  // correction). Pending and approved-unconsumed are active — no new request while one's live.
  const canRequestNew = !latest || latest.status === 'rejected' || (latest.status === 'approved' && !!latest.consumed_at)

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['invoice-change-requests', invoice.id] })
    queryClient.invalidateQueries({ queryKey: ['invoices', invoice.id] })
    onChanged()
  }

  const requestMutation = useMutation({
    mutationFn: () => requestInvoiceChange(invoice.id, requestReason),
    onSuccess: () => {
      toast.success('Change request submitted.')
      setRequestReason('')
      setRequesting(false)
      invalidate()
    },
    onError: (error) => toastApiError(error),
  })

  const approveMutation = useMutation({
    mutationFn: () => approveInvoiceChangeRequest(latest!.id),
    onSuccess: () => {
      toast.success('Change request approved — nominal is unlocked for one edit.')
      invalidate()
    },
    onError: (error) => toastApiError(error),
  })

  const rejectMutation = useMutation({
    mutationFn: () => rejectInvoiceChangeRequest(latest!.id, rejectRemarks),
    onSuccess: () => {
      toast.success('Change request rejected.')
      setRejectRemarks('')
      setRejecting(false)
      invalidate()
    },
    onError: (error) => toastApiError(error),
  })

  const applyMutation = useMutation({
    mutationFn: () =>
      applyInvoiceNominalChange(
        latest!.id,
        invoice.items.map((item) => ({ id: item.id, rate: rates[item.id] !== undefined ? Number(rates[item.id]) : Number(item.rate) })),
      ),
    onSuccess: () => {
      toast.success('Invoice nominal updated — locked again.')
      setRates({})
      invalidate()
    },
    onError: (error) => toastApiError(error),
  })

  if (invoice.invoice_type !== 'transportation' || invoice.status !== 'submitted') {
    return null
  }

  const previewGrandTotal = invoice.items.reduce((sum, item) => {
    const rate = rates[item.id] !== undefined ? Number(rates[item.id]) : Number(item.rate)
    return sum + rate * item.qty
  }, 0) - Number(invoice.discount_amount) + Number(invoice.tax_amount)

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="flex items-center gap-2">
          <LockKeyhole className="size-4 text-primary" />
          Nominal Lock
        </CardTitle>
        {latest && <StatusBadge status={latest.status} />}
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {canRequestNew && canEdit && (
          <div>
            <Button variant="outline" onClick={() => setRequesting(true)}>
              Request Change
            </Button>
          </div>
        )}

        {latest?.status === 'pending' && (
          <div className="flex items-center justify-between gap-2">
            <p className="text-sm text-muted-foreground">{latest.request_reason}</p>
            {canApprove && (
              <div className="flex shrink-0 items-center gap-2">
                <Button variant="outline" onClick={() => setRejecting(true)}>
                  <X className="size-4" />
                  Reject
                </Button>
                <Button onClick={() => approveMutation.mutate()} disabled={approveMutation.isPending}>
                  {approveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Check className="size-4" />}
                  Approve
                </Button>
              </div>
            )}
          </div>
        )}

        {unlocked && (
          <div className="flex flex-col gap-3 rounded-md border p-3">
            <p className="text-sm text-muted-foreground">Nominal is unlocked for one edit — saving will lock it again.</p>
            <div className="flex flex-col gap-2">
              {invoice.items.map((item) => (
                <div key={item.id} className="flex items-center gap-3">
                  <span className="flex-1 text-sm">{item.item_name}</span>
                  <span className="w-16 text-right text-sm text-muted-foreground">{formatNumber(item.qty)}</span>
                  <Input
                    type="number"
                    className="w-32"
                    value={rates[item.id] ?? String(item.rate)}
                    onChange={(event) => setRates((prev) => ({ ...prev, [item.id]: event.target.value }))}
                  />
                  <span className="w-32 text-right text-sm">
                    {formatCurrency((rates[item.id] !== undefined ? Number(rates[item.id]) : Number(item.rate)) * item.qty)}
                  </span>
                </div>
              ))}
            </div>
            <div className="flex items-center justify-between border-t pt-2 text-sm font-medium">
              <span>New Grand Total</span>
              <span>{formatCurrency(previewGrandTotal)}</span>
            </div>
            {canEdit && (
              <Button className="self-end" onClick={() => applyMutation.mutate()} disabled={applyMutation.isPending}>
                {applyMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
                Save
              </Button>
            )}
          </div>
        )}

        <div className="flex flex-col gap-2">
          <p className="text-xs font-medium text-muted-foreground">Change Request History</p>
          {history.length === 0 ? (
            <EmptyState message="No nominal change has been requested for this Invoice." />
          ) : (
            <ul className="flex flex-col gap-2">
              {history.map((request) => (
                <li key={request.id} className="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                  <div className="flex flex-col">
                    <span>
                      <StatusBadge status={request.status} /> — {request.request_reason}
                    </span>
                    {request.decision_remarks && <span className="text-xs text-muted-foreground">{request.decision_remarks}</span>}
                  </div>
                  <div className="text-right text-xs text-muted-foreground">
                    <div>{request.requested_by?.name ?? 'Unknown'}</div>
                    <div>{request.decided_at ? formatDate(request.decided_at) : `Requested ${formatDate(request.created_at)}`}</div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </CardContent>

      <Dialog open={requesting} onOpenChange={setRequesting}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Request Nominal Change</DialogTitle>
            <DialogDescription>Explain why this Invoice's Rate/Amount needs correcting — an approver will review this before it's unlocked.</DialogDescription>
          </DialogHeader>

          <div className="flex flex-col gap-2">
            <Label htmlFor="change-request-reason">Reason</Label>
            <Textarea id="change-request-reason" value={requestReason} onChange={(event) => setRequestReason(event.target.value)} placeholder="e.g. Rate was entered incorrectly at invoicing." />
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setRequesting(false)} disabled={requestMutation.isPending}>
              Cancel
            </Button>
            <Button onClick={() => requestMutation.mutate()} disabled={!requestReason.trim() || requestMutation.isPending}>
              {requestMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Submit Request
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={rejecting} onOpenChange={setRejecting}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Reject Change Request</DialogTitle>
            <DialogDescription>Explain why this nominal change is being rejected — the Invoice stays locked.</DialogDescription>
          </DialogHeader>

          <div className="flex flex-col gap-2">
            <Label htmlFor="reject-remarks">Reason</Label>
            <Textarea id="reject-remarks" value={rejectRemarks} onChange={(event) => setRejectRemarks(event.target.value)} placeholder="e.g. Not enough justification for the correction." />
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setRejecting(false)} disabled={rejectMutation.isPending}>
              Cancel
            </Button>
            <Button variant="destructive" onClick={() => rejectMutation.mutate()} disabled={!rejectRemarks.trim() || rejectMutation.isPending}>
              {rejectMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Reject
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  )
}
