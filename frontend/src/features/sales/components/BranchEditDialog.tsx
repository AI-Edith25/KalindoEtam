import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Pencil } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { fetchBranches } from '@/features/master/api/lookupsApi'
import { toastApiError } from '@/shared/services/errorHandler'
import { updateInvoiceBranch } from '../api/invoiceApi'
import type { Invoice } from '../types'

interface BranchEditDialogProps {
  invoice: Invoice
}

/**
 * Transportation only — Branch has no other write path once an Invoice is
 * Submitted (InvoiceService::update() stays Draft-only for every other
 * field). This is metadata correction, not a financial change, so it's a
 * small direct edit — no approval ceremony, unlike NominalChangeRequestPanel.
 */
export function BranchEditDialog({ invoice }: BranchEditDialogProps) {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [branchId, setBranchId] = useState(invoice.branch_id ?? '')

  const branchesQuery = useQuery({ queryKey: ['branches-lookup'], queryFn: fetchBranches, enabled: open })

  const saveMutation = useMutation({
    mutationFn: () => updateInvoiceBranch(invoice.id, branchId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoices', invoice.id] })
      queryClient.invalidateQueries({ queryKey: ['accounts-receivables'] })
      toast.success('Branch updated.')
      setOpen(false)
    },
    onError: (error) => toastApiError(error),
  })

  if (invoice.invoice_type !== 'transportation') return null

  return (
    <>
      <Button
        variant="ghost"
        size="icon-xs"
        className="text-muted-foreground hover:text-foreground"
        onClick={() => {
          setBranchId(invoice.branch_id ?? '')
          setOpen(true)
        }}
      >
        <Pencil />
      </Button>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit Branch</DialogTitle>
            <DialogDescription>Corrects which Branch this Invoice belongs to — reporting metadata only, no effect on amounts or the ledger.</DialogDescription>
          </DialogHeader>
          <Select value={branchId} onValueChange={setBranchId}>
            <SelectTrigger className="w-full">
              <SelectValue placeholder={branchesQuery.isLoading ? 'Loading…' : 'Select branch'} />
            </SelectTrigger>
            <SelectContent>
              {branchesQuery.data?.map((branch) => (
                <SelectItem key={branch.id} value={branch.id}>
                  {branch.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button onClick={() => saveMutation.mutate()} disabled={!branchId || saveMutation.isPending}>
              {saveMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Save
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
