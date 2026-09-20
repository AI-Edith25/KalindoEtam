import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Loader2, Upload } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { toast } from 'sonner'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { toastApiError } from '@/shared/services/errorHandler'
import { resolveSupplierOutstandingImport, storeSupplierOutstandingSnapshot } from '../api/supplierOutstandingArchiveApi'
import type { SupplierOutstandingArchiveImportBatch, SupplierOutstandingSnapshot } from '../types'

type Step = 'setup' | 'preview'

interface SupplierOutstandingArchiveImportDialogProps {
  open: boolean
  onClose: () => void
  onImported: (snapshot: SupplierOutstandingSnapshot) => void
}

/** AP mirror of CustomerOutstandingArchiveImportDialog -- see that component's own docblock. */
export function SupplierOutstandingArchiveImportDialog({ open, onClose, onImported }: SupplierOutstandingArchiveImportDialogProps) {
  const queryClient = useQueryClient()
  const [step, setStep] = useState<Step>('setup')
  const [file, setFile] = useState<File | null>(null)
  const [batch, setBatch] = useState<SupplierOutstandingArchiveImportBatch | null>(null)

  const reset = () => {
    setStep('setup')
    setFile(null)
    setBatch(null)
  }

  const handleClose = () => {
    reset()
    onClose()
  }

  const uploadMutation = useMutation({
    mutationFn: () => storeSupplierOutstandingSnapshot(file as File),
    onSuccess: (result) => {
      setBatch(result)
      setStep('preview')
    },
    onError: (error) => toastApiError(error),
  })

  const resolveMutation = useMutation({
    mutationFn: () => resolveSupplierOutstandingImport(batch?.id ?? ''),
    onSuccess: (snapshot) => {
      toast.success(`Snapshot berhasil diimpor: ${snapshot.total_suppliers} supplier, ${snapshot.total_rows} baris.`)
      queryClient.invalidateQueries({ queryKey: ['supplier-outstanding-snapshots'] })
      onImported(snapshot)
      handleClose()
    },
    onError: (error) => toastApiError(error),
  })

  const preview = batch?.preview_summary ?? null
  const hasIssues = !!preview && (preview.failed_rows.length > 0 || preview.subtotal_mismatches.length > 0 || !!preview.grand_total_mismatch)

  return (
    <Dialog open={open} onOpenChange={(next) => !next && handleClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Import Data — Supplier Outstanding Bills</DialogTitle>
          <DialogDescription>
            {step === 'setup' && 'Upload file export Supplier Outstanding Bills (.xlsx) apa adanya. File data piutang customer akan ditolak.'}
            {step === 'preview' && 'Ringkasan sebelum import — periksa dulu sebelum melanjutkan.'}
          </DialogDescription>
        </DialogHeader>

        {step === 'setup' && (
          <div className="flex flex-col gap-1.5">
            <Label>File</Label>
            <input type="file" accept=".csv,.xlsx,.xls" onChange={(event) => setFile(event.target.files?.[0] ?? null)} className="text-sm" />
          </div>
        )}

        {step === 'preview' && preview && (
          <div className="flex max-h-[28rem] flex-col gap-4 overflow-y-auto">
            <div className="flex flex-col gap-2 rounded-md border p-3 text-sm">
              <div className="flex items-center justify-between gap-2">
                <span className="font-medium">Snapshot per</span>
                <span>{formatDate(preview.snapshot_as_of_date)}</span>
              </div>
              <div className="flex items-center justify-between gap-2 text-muted-foreground">
                <span>Baris terbaca / supplier</span>
                <span>{formatNumber(preview.total_rows)} baris, {formatNumber(preview.total_suppliers)} supplier</span>
              </div>
              <div className="flex items-center justify-between gap-2 text-muted-foreground">
                <span>Total Outstanding</span>
                <span>{formatCurrency(preview.total_unpaid)}</span>
              </div>
              <div className="flex items-center justify-between gap-2 text-muted-foreground">
                <span>Total Overdue</span>
                <span>{formatCurrency(preview.total_overdue)}</span>
              </div>
            </div>

            {hasIssues && (
              <Alert variant="destructive">
                <AlertTitle>Perlu direview</AlertTitle>
                <AlertDescription>
                  <div className="flex flex-col gap-2 text-sm">
                    {preview.grand_total_mismatch && (
                      <p>
                        <Badge variant="destructive" className="mr-1.5">Grand Total tidak cocok</Badge>
                        File: Unpaid {formatCurrency(preview.grand_total_mismatch.file_unpaid)}, Overdue{' '}
                        {formatCurrency(preview.grand_total_mismatch.file_overdue)} — Hasil parsing: Unpaid{' '}
                        {formatCurrency(preview.grand_total_mismatch.computed_unpaid)}, Overdue{' '}
                        {formatCurrency(preview.grand_total_mismatch.computed_overdue)}.
                      </p>
                    )}
                    {preview.subtotal_mismatches.length > 0 && (
                      <div>
                        <Badge variant="secondary" className="mr-1.5">{preview.subtotal_mismatches.length}</Badge>
                        Subtotal supplier tidak cocok:
                        <ul className="mt-1 list-disc pl-5">
                          {preview.subtotal_mismatches.slice(0, 10).map((m, i) => (
                            <li key={i}>
                              Baris {m.row} — {m.supplier_name}: file {m.file_unpaid !== null ? formatCurrency(m.file_unpaid) : '—'}, hasil parsing{' '}
                              {formatCurrency(m.computed_unpaid)}
                            </li>
                          ))}
                        </ul>
                      </div>
                    )}
                    {preview.failed_rows.length > 0 && (
                      <div>
                        <Badge variant="secondary" className="mr-1.5">{preview.failed_rows.length}</Badge>
                        Baris gagal diparse (dilewati):
                        <ul className="mt-1 list-disc pl-5">
                          {preview.failed_rows.slice(0, 10).map((r, i) => (
                            <li key={i}>Baris {r.row}: {r.reason}</li>
                          ))}
                        </ul>
                      </div>
                    )}
                  </div>
                </AlertDescription>
              </Alert>
            )}
          </div>
        )}

        <DialogFooter>
          {step === 'setup' && (
            <Button type="button" onClick={() => uploadMutation.mutate()} disabled={!file || uploadMutation.isPending}>
              {uploadMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              <Upload className="size-4" />
              Upload
            </Button>
          )}
          {step === 'preview' && (
            <Button type="button" onClick={() => resolveMutation.mutate()} disabled={resolveMutation.isPending}>
              {resolveMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Konfirmasi & Import
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
