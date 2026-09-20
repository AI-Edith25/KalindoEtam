import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Loader2, Upload } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { toast } from 'sonner'
import { toastApiError } from '@/shared/services/errorHandler'
import { storeCustomerOutstandingSnapshot } from '../api/customerOutstandingArchiveApi'
import type { CustomerOutstandingSnapshot } from '../types'

interface CustomerOutstandingArchiveImportDialogProps {
  open: boolean
  onClose: () => void
  onImported: (snapshot: CustomerOutstandingSnapshot) => void
}

/**
 * Direct upload -> parse -> new snapshot, no preview/confirm step -- the import itself already
 * validates every per-customer subtotal and the file's own Grand Total against what it parsed,
 * and refuses (with a clear reason) rather than committing anything if they disagree. A re-import
 * always creates an additional snapshot, never overwrites a prior one.
 */
export function CustomerOutstandingArchiveImportDialog({ open, onClose, onImported }: CustomerOutstandingArchiveImportDialogProps) {
  const queryClient = useQueryClient()
  const [file, setFile] = useState<File | null>(null)

  const handleClose = () => {
    setFile(null)
    onClose()
  }

  const importMutation = useMutation({
    mutationFn: () => storeCustomerOutstandingSnapshot(file as File),
    onSuccess: (snapshot) => {
      toast.success(`Snapshot berhasil diimpor: ${snapshot.total_customers} customer, ${snapshot.total_rows} baris.`)
      queryClient.invalidateQueries({ queryKey: ['customer-outstanding-snapshots'] })
      onImported(snapshot)
      handleClose()
    },
    onError: (error) => toastApiError(error),
  })

  return (
    <Dialog open={open} onOpenChange={(next) => !next && handleClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Import Data — Piutang Customer</DialogTitle>
          <DialogDescription>
            Upload file export &quot;Customer Unpaid Bills With Overdue Advice&quot; (.xlsx) apa adanya. Hasil import menjadi snapshot baru,
            snapshot sebelumnya tidak berubah.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-1.5">
          <Label>File</Label>
          <input type="file" accept=".csv,.xlsx,.xls" onChange={(event) => setFile(event.target.files?.[0] ?? null)} className="text-sm" />
        </div>

        <DialogFooter>
          <Button type="button" onClick={() => importMutation.mutate()} disabled={!file || importMutation.isPending}>
            {importMutation.isPending && <Loader2 className="size-4 animate-spin" />}
            <Upload className="size-4" />
            Import
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
