import { useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { fetchPaymentVoucherImportBatch } from '../api/paymentEntryApi'

const TERMINAL_STATUSES = ['completed', 'failed']

interface PaymentVoucherImportDialogProps {
  batchId: string | null
  onClose: () => void
}

/**
 * Post-import report — the ticket's own requirement: no per-row preview
 * before import, just a one-click run followed by a summary of what
 * succeeded, what needs manual review (created but Unallocated/Draft/
 * partially applied), and what failed outright (with why), per voucher.
 */
export function PaymentVoucherImportDialog({ batchId, onClose }: PaymentVoucherImportDialogProps) {
  const batchQuery = useQuery({
    queryKey: ['payment-voucher-import-batch', batchId],
    queryFn: () => fetchPaymentVoucherImportBatch(batchId as string),
    enabled: batchId !== null,
    refetchInterval: (query) => (query.state.data && TERMINAL_STATUSES.includes(query.state.data.status) ? false : 1000),
  })

  const batch = batchQuery.data
  const isDone = !!batch && TERMINAL_STATUSES.includes(batch.status)
  const progress = batch && batch.total_rows > 0 ? Math.round((batch.processed_rows / batch.total_rows) * 100) : 0
  const vouchers = batch?.preview_summary?.vouchers ?? []
  const needsReviewCount = batch?.preview_summary?.needs_review_rows ?? 0

  return (
    <Dialog open={batchId !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Import Payment Voucher</DialogTitle>
          <DialogDescription>
            {!isDone && 'Memproses file — mengelompokkan baris per voucher dan mencocokkan ke data master…'}
            {isDone && batch?.status === 'completed' && 'Import selesai. Berikut ringkasan hasilnya.'}
            {isDone && batch?.status === 'failed' && 'Import tidak bisa diproses.'}
          </DialogDescription>
        </DialogHeader>

        {!batch && (
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Loader2 className="size-4 animate-spin" />
            Mengunggah file…
          </div>
        )}

        {batch && !isDone && (
          <div className="flex flex-col gap-2">
            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
              <div className="h-full bg-primary transition-all" style={{ width: `${progress}%` }} />
            </div>
            <p className="text-sm text-muted-foreground">
              {batch.processed_rows} / {batch.total_rows} voucher diproses
            </p>
          </div>
        )}

        {batch && isDone && batch.status === 'failed' && (
          <Alert variant="destructive">
            <AlertTitle>Tidak ada voucher yang diimpor</AlertTitle>
            <AlertDescription>{batch.failure_reason}</AlertDescription>
          </Alert>
        )}

        {batch && isDone && batch.status === 'completed' && (
          <div className="flex flex-col gap-4">
            <div className="flex gap-4 text-sm">
              <span className="text-success-foreground">Berhasil: {batch.success_rows}</span>
              <span className="text-warning-foreground">Perlu review: {needsReviewCount}</span>
              <span className="text-destructive">Gagal: {batch.failed_rows}</span>
            </div>

            <div className="flex max-h-80 flex-col gap-2 overflow-y-auto rounded-md border p-2">
              {vouchers.map((voucher) => (
                <div key={voucher.document_number} className="flex flex-col gap-1 border-b pb-2 last:border-b-0 last:pb-0">
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-medium">{voucher.document_number}</span>
                    <StatusBadge status={voucher.status} />
                  </div>
                  {voucher.reason && <p className="text-sm text-muted-foreground">{voucher.reason}</p>}
                </div>
              ))}
            </div>
          </div>
        )}

        <DialogFooter>
          <Button type="button" onClick={onClose} disabled={!isDone}>
            {isDone ? 'Selesai' : <Loader2 className="size-4 animate-spin" />}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
