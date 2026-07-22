import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Check, Loader2, ShieldCheck, X } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { EmptyState } from '@/components/shared/EmptyState'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatDate } from '@/lib/utils'
import { approveFlow, fetchApprovalHistory, requestApproval } from '../api/approvalApi'
import { RejectApprovalDialog } from './RejectApprovalDialog'
import type { ApprovableModule } from '../types'

interface ApprovalPanelProps {
  approvableType: string
  approvableId: string
  module: ApprovableModule
  documentStatus: 'draft' | 'submitted' | 'cancelled'
  documentLabel: string
  /** Invalidates the parent document's own query — its `latest_approval`/status may now be stale after a decision. */
  onChanged: () => void
}

/**
 * The one panel every approval-enabled document type renders — Sales Order,
 * Purchase Order, and manual Journal Entry all use this unchanged (only the
 * `module`/`approvableType` props differ). See docs/APPROVAL_WORKFLOW_DESIGN.md §1.
 */
export function ApprovalPanel({ approvableType, approvableId, module, documentStatus, documentLabel, onChanged }: ApprovalPanelProps) {
  const queryClient = useQueryClient()
  const [rejecting, setRejecting] = useState(false)
  const canApprove = useHasPermission(`${module}.approve`)

  const historyQuery = useQuery({
    queryKey: ['approval-history', approvableType, approvableId],
    queryFn: () => fetchApprovalHistory(approvableType, approvableId),
  })

  const history = historyQuery.data ?? []
  const latest = history[0] ?? null

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['approval-history', approvableType, approvableId] })
    queryClient.invalidateQueries({ queryKey: ['dashboard', 'pending-tasks'] })
    onChanged()
  }

  const requestMutation = useMutation({
    mutationFn: () => requestApproval(module, approvableId),
    onSuccess: () => {
      toast.success('Approval requested.')
      invalidate()
    },
    onError: (error) => toastApiError(error),
  })

  const approveMutation = useMutation({
    mutationFn: () => approveFlow(latest!.id),
    onSuccess: () => {
      toast.success('Approved.')
      invalidate()
    },
    onError: (error) => toastApiError(error),
  })

  if (documentStatus !== 'draft' && history.length === 0) {
    return null
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="flex items-center gap-2">
          <ShieldCheck className="size-4 text-primary" />
          Approval
        </CardTitle>
        {latest && <StatusBadge status={latest.status} />}
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {documentStatus === 'draft' && (
          <div className="flex items-center gap-2">
            {(!latest || latest.status === 'rejected') && (
              <Button onClick={() => requestMutation.mutate()} disabled={requestMutation.isPending}>
                {requestMutation.isPending && <Loader2 className="size-4 animate-spin" />}
                Submit for Approval
              </Button>
            )}
            {latest?.status === 'pending' && (
              <>
                <p className="text-sm text-muted-foreground">Awaiting a decision before this can be submitted.</p>
                {canApprove && (
                  <div className="ml-auto flex items-center gap-2">
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
              </>
            )}
            {latest?.status === 'approved' && <p className="text-sm text-muted-foreground">Approved — ready to submit.</p>}
          </div>
        )}

        <div className="flex flex-col gap-2">
          <p className="text-xs font-medium text-muted-foreground">Approval History</p>
          {history.length === 0 ? (
            <EmptyState message="No approval has been requested for this document." />
          ) : (
            <ul className="flex flex-col gap-2">
              {history.map((flow) => (
                <li key={flow.id} className="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                  <div className="flex flex-col">
                    <span>
                      Step {flow.step} — <StatusBadge status={flow.status} />
                    </span>
                    {flow.remarks && <span className="text-xs text-muted-foreground">{flow.remarks}</span>}
                  </div>
                  <div className="text-right text-xs text-muted-foreground">
                    {flow.approver ? <div>{flow.approver.name}</div> : <div>Unassigned</div>}
                    <div>{flow.decided_at ? formatDate(flow.decided_at) : `Requested ${formatDate(flow.created_at)}`}</div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </CardContent>

      {latest?.status === 'pending' && (
        <RejectApprovalDialog
          open={rejecting}
          onOpenChange={setRejecting}
          approvalFlowId={latest.id}
          documentLabel={documentLabel}
          onRejected={invalidate}
        />
      )}
    </Card>
  )
}
