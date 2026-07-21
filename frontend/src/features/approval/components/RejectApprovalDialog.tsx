import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { getErrorMessage } from '@/shared/services/errorHandler'
import { rejectFlow } from '../api/approvalApi'

interface RejectApprovalDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  approvalFlowId: string
  documentLabel: string
  onRejected: () => void
}

/** Rejection requires a reason (backend validates this too) — approval doesn't. See docs/APPROVAL_WORKFLOW_DESIGN.md §2. */
export function RejectApprovalDialog({ open, onOpenChange, approvalFlowId, documentLabel, onRejected }: RejectApprovalDialogProps) {
  const queryClient = useQueryClient()
  const [remarks, setRemarks] = useState('')

  const mutation = useMutation({
    mutationFn: () => rejectFlow(approvalFlowId, remarks),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['approval-history'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard', 'pending-tasks'] })
      toast.success('Rejected.')
      setRemarks('')
      onOpenChange(false)
      onRejected()
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Reject Approval</DialogTitle>
          <DialogDescription>Explain why {documentLabel} is being rejected — the requester will see this and can revise it.</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-2">
          <Label htmlFor="reject-remarks">Reason</Label>
          <Textarea id="reject-remarks" value={remarks} onChange={(event) => setRemarks(event.target.value)} placeholder="e.g. Pricing needs revisiting before this can proceed." />
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button variant="destructive" onClick={() => mutation.mutate()} disabled={!remarks.trim() || mutation.isPending}>
            {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
            Reject
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
