import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, Loader2, Upload } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { toast } from 'sonner'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { toastApiError } from '@/shared/services/errorHandler'
import { downloadImportBatchFailedRows } from '@/shared/lib/downloadImportBatchFailedRows'
import { confirmSkybizLedgerImport, fetchSkybizLedgerImportBatch, importSkybizLedger } from '../api/skybizLedgerReconciliationApi'

const MODULE = 'skybiz-ledger-reconciliation'
const TERMINAL_STATUSES = ['previewed', 'completed', 'failed']

interface SkybizLedgerReconciliationDialogProps {
  open: boolean
  onClose: () => void
  onImported: () => void
}

const FLAG_LABELS: Record<string, string> = {
  unmatched_unparseable_ref: 'Referensi tidak bisa dibaca',
  unmatched_ambiguous: 'Nomor urut ambigu (tidak ditebak)',
  unmatched_not_found_in_scope: 'Ada di Skybiz, tidak ditemukan di KE',
  ke_higher_than_skybiz_conflict: 'paid_amount KE lebih tinggi — cek manual',
  reversal_rows_needs_review: 'Baris reversal/retur — cek manual',
  already_imported: 'Sudah pernah diimpor sebelumnya',
}

/**
 * Mandatory preview-then-confirm flow (ticket's own non-negotiable requirement — this rewrites
 * payment history across ~16k invoices) — unlike LedgerImportReportDialog (Payment Voucher/
 * Official Receipt's own one-click imports), every phase here is a queued, polled ImportBatch
 * since this file (55k+ rows) is an order of magnitude bigger than those. Upload always only
 * previews; "Jalankan Import" is the separate, explicit commit step.
 */
export function SkybizLedgerReconciliationDialog({ open, onClose, onImported }: SkybizLedgerReconciliationDialogProps) {
  const queryClient = useQueryClient()
  const [file, setFile] = useState<File | null>(null)
  const [batchId, setBatchId] = useState<string | null>(null)
  const [confirmed, setConfirmed] = useState(false)

  const reset = () => {
    setFile(null)
    setBatchId(null)
    setConfirmed(false)
  }

  const handleClose = () => {
    reset()
    onClose()
  }

  const batchQuery = useQuery({
    queryKey: ['skybiz-ledger-import-batch', batchId],
    queryFn: () => fetchSkybizLedgerImportBatch(batchId as string),
    enabled: batchId !== null,
    refetchInterval: (query) => (query.state.data && TERMINAL_STATUSES.includes(query.state.data.status) ? false : 1000),
  })

  const uploadMutation = useMutation({
    mutationFn: () => importSkybizLedger(file as File),
    onSuccess: (batch) => setBatchId(batch.id),
    onError: (error) => toastApiError(error),
  })

  const confirmMutation = useMutation({
    mutationFn: () => confirmSkybizLedgerImport(batchId as string),
    onSuccess: () => {
      setConfirmed(true)
      queryClient.invalidateQueries({ queryKey: ['skybiz-ledger-import-batch', batchId] })
    },
    onError: (error) => toastApiError(error),
  })

  const downloadMutation = useMutation({
    mutationFn: () => downloadImportBatchFailedRows(batchId as string, MODULE),
    onError: (error) => toastApiError(error),
  })

  const batch = batchQuery.data
  const summary = batch?.preview_summary ?? null
  const progress = batch && batch.total_rows > 0 ? Math.round((batch.processed_rows / batch.total_rows) * 100) : 0
  const flaggedCount = summary
    ? summary.unmatched_unparseable_ref + summary.unmatched_ambiguous + summary.unmatched_not_found_in_scope
      + summary.ke_higher_than_skybiz_conflict + summary.reversal_rows_needs_review + summary.already_imported
    : 0

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) handleClose()
      }}
    >
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Rekonsiliasi Pelunasan (Skybiz)</DialogTitle>
          <DialogDescription>
            {!batch && 'Upload export "Customer Ledger" (Debtors Ledger) dari Skybiz untuk mencocokkan pelunasan yang belum masuk ke KE.'}
            {batch?.status === 'queued' || batch?.status === 'processing'
              ? (confirmed ? 'Memproses import — membuat Official Receipt…' : 'Memproses preview — mencocokkan ke invoice KE…')
              : null}
            {batch?.status === 'previewed' && 'Preview selesai — periksa ringkasan di bawah sebelum menjalankan import.'}
            {batch?.status === 'completed' && 'Import selesai.'}
            {batch?.status === 'failed' && 'Tidak bisa diproses.'}
          </DialogDescription>
        </DialogHeader>

        {!batch && (
          <div className="flex flex-col gap-1.5">
            <Label>File</Label>
            <input type="file" accept=".csv,.xlsx,.xls" onChange={(event) => setFile(event.target.files?.[0] ?? null)} className="text-sm" />
          </div>
        )}

        {batch && (batch.status === 'queued' || batch.status === 'processing') && (
          <div className="flex flex-col gap-2">
            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
              <div className="h-full bg-primary transition-all" style={{ width: `${progress}%` }} />
            </div>
            <p className="text-sm text-muted-foreground">{batch.processed_rows} / {batch.total_rows} blok customer diproses</p>
          </div>
        )}

        {batch?.status === 'failed' && (
          <Alert variant="destructive">
            <AlertTitle>Import gagal</AlertTitle>
            <AlertDescription>{batch.failure_reason}</AlertDescription>
          </Alert>
        )}

        {summary && (batch?.status === 'previewed' || batch?.status === 'completed') && (
          <div className="flex max-h-[28rem] flex-col gap-4 overflow-y-auto">
            <div className="flex flex-col gap-2 rounded-md border p-3 text-sm">
              <div className="flex items-center justify-between gap-2">
                <span className="font-medium">Customer diproses</span>
                <span>{formatNumber(summary.customers_processed)}</span>
              </div>
              <div className="flex items-center justify-between gap-2">
                <span className="font-medium">Invoice sudah sinkron</span>
                <span>{formatNumber(summary.invoices_matched_up_to_date)}</span>
              </div>
              <div className="flex items-center justify-between gap-2 text-amber-700">
                <span className="font-medium">Invoice perlu dikoreksi</span>
                <span>{formatNumber(summary.invoices_to_correct)}</span>
              </div>
              <div className="flex items-center justify-between gap-2 text-amber-700">
                <span className="font-medium">Total nominal yang akan diterapkan</span>
                <span>{formatCurrency(summary.total_amount_to_apply)}</span>
              </div>
              <div className="flex items-center justify-between gap-2">
                <span className="font-medium">{batch.status === 'completed' ? 'Official Receipt dibuat' : 'Official Receipt akan dibuat'}</span>
                <span>{formatNumber(batch.status === 'completed' ? batch.success_rows : summary.or_to_create)}</span>
              </div>
            </div>

            {flaggedCount > 0 && (
              <Alert variant="destructive">
                <AlertTitle>Perlu direview ({formatNumber(flaggedCount)} baris)</AlertTitle>
                <AlertDescription>
                  <div className="flex flex-col gap-1.5 text-sm">
                    {Object.entries(FLAG_LABELS).map(([key, label]) => {
                      const count = (summary as unknown as Record<string, number>)[key]
                      if (!count) return null
                      return (
                        <div key={key} className="flex items-center gap-2">
                          <Badge variant="secondary">{count}</Badge>
                          {label}
                        </div>
                      )
                    })}
                  </div>
                </AlertDescription>
              </Alert>
            )}

            {summary.pre_migration_out_of_scope > 0 && (
              <p className="text-sm text-muted-foreground">
                {formatNumber(summary.pre_migration_out_of_scope)} invoice lama (sebelum KE beroperasi) dilewati — memang di luar cakupan.
              </p>
            )}

            {batch.status === 'completed' && (
              <div className="flex gap-4 text-sm">
                <span className="text-success-foreground">Berhasil: {formatNumber(batch.success_rows)}</span>
                <span className="text-destructive">Gagal: {formatNumber(batch.failed_rows)}</span>
              </div>
            )}
          </div>
        )}

        <DialogFooter>
          {!batch && (
            <Button type="button" onClick={() => uploadMutation.mutate()} disabled={!file || uploadMutation.isPending}>
              {uploadMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              <Upload className="size-4" />
              Upload &amp; Preview
            </Button>
          )}
          {batch?.status === 'previewed' && (
            <Button type="button" onClick={() => confirmMutation.mutate()} disabled={confirmMutation.isPending}>
              {confirmMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Jalankan Import
            </Button>
          )}
          {batch?.status === 'completed' && (
            <>
              {batch.has_failed_rows && (
                <Button type="button" variant="outline" onClick={() => downloadMutation.mutate()} disabled={downloadMutation.isPending}>
                  <Download className="size-4" />
                  Unduh Baris Perlu Review
                </Button>
              )}
              <Button
                type="button"
                onClick={() => {
                  toast.success('Rekonsiliasi pelunasan Skybiz selesai.')
                  onImported()
                  handleClose()
                }}
              >
                Selesai
              </Button>
            </>
          )}
          {batch?.status === 'failed' && (
            <Button type="button" variant="outline" onClick={handleClose}>
              Tutup
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
