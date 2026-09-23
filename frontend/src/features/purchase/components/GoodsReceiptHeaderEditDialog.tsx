import { useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { toastApiError } from '@/shared/services/errorHandler'
import { updateGoodsReceipt } from '../api/goodsReceiptApi'
import type { GoodsReceipt } from '../types'

interface GoodsReceiptHeaderEditDialogProps {
  receipt: GoodsReceipt | null
  onOpenChange: (open: boolean) => void
}

/**
 * Header-only edit for a confirmed Goods Receipt — items/qty/warehouse already moved stock,
 * so the backend (GoodsReceiptService::updateSubmittedHeader) only accepts these 3 fields.
 */
export function GoodsReceiptHeaderEditDialog({ receipt, onOpenChange }: GoodsReceiptHeaderEditDialogProps) {
  const queryClient = useQueryClient()
  const [receiptDate, setReceiptDate] = useState('')
  const [dueDate, setDueDate] = useState('')
  const [remarks, setRemarks] = useState('')

  useEffect(() => {
    if (!receipt) return
    setReceiptDate(receipt.receipt_date)
    setDueDate(receipt.due_date ?? '')
    setRemarks(receipt.remarks ?? '')
  }, [receipt])

  const mutation = useMutation({
    mutationFn: () => updateGoodsReceipt(receipt!.id, { receipt_date: receiptDate, due_date: dueDate || null, remarks: remarks || null }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['goods-receipts'] })
      toast.success('Goods Receipt updated.')
      onOpenChange(false)
    },
    onError: (error) => toastApiError(error),
  })

  return (
    <Dialog open={!!receipt} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit {receipt?.document_number}</DialogTitle>
          <DialogDescription>Receipt already confirmed — only dates and notes can be changed. Items and qty stay locked.</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-2">
            <Label htmlFor="gr-receipt-date">Receipt Date</Label>
            <Input id="gr-receipt-date" type="date" value={receiptDate} onChange={(e) => setReceiptDate(e.target.value)} />
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="gr-due-date">Due Date</Label>
            <Input id="gr-due-date" type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
            <p className="text-xs text-muted-foreground">Leave blank to use the Supplier's Terms of Payment.</p>
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="gr-remarks">Notes</Label>
            <Textarea id="gr-remarks" value={remarks} onChange={(e) => setRemarks(e.target.value)} placeholder="Optional" />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button onClick={() => mutation.mutate()} disabled={mutation.isPending || !receiptDate}>
            {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
            Save Changes
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
