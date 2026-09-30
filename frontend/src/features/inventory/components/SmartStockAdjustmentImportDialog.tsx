import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Loader2, Upload, XCircle } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { cn, formatNumber } from '@/lib/utils'
import { toastApiError } from '@/shared/services/errorHandler'
import { resolveSmartStockAdjustmentImport, storeSmartStockAdjustmentImport } from '../api/smartStockAdjustmentImportApi'
import type { SmartStockAdjustmentCommitResult, SmartStockAdjustmentImportBatch, SmartStockAdjustmentPreviewSummary } from '../types'

type Step = 'setup' | 'preview' | 'result'

interface SmartStockAdjustmentImportDialogProps {
  open: boolean
  onClose: () => void
}

/**
 * "Smart Import" for the same raw legacy stock-snapshot exports SmartOpeningStockImportDialog
 * reads, for the case Opening Stock can't cover: an item that already has stock activity, where
 * the file's Qty/Balance is read as a COUNTED balance to reconcile to (a Stock Adjustment),
 * never an opening balance or a delta to add. Only lines that actually differ from the live
 * system balance show up — a 0-difference line is nothing to review, and re-running the same
 * file after a successful import is safe (every difference comes out 0, nothing posts again).
 */
export function SmartStockAdjustmentImportDialog({ open, onClose }: SmartStockAdjustmentImportDialogProps) {
  const queryClient = useQueryClient()

  const [step, setStep] = useState<Step>('setup')
  const [file, setFile] = useState<File | null>(null)
  const [metadataAdjustmentDate, setMetadataAdjustmentDate] = useState('')
  const [batch, setBatch] = useState<SmartStockAdjustmentImportBatch | null>(null)

  const reset = () => {
    setStep('setup')
    setFile(null)
    setMetadataAdjustmentDate('')
    setBatch(null)
  }

  const handleClose = () => {
    reset()
    onClose()
  }

  const uploadMutation = useMutation({
    mutationFn: () => storeSmartStockAdjustmentImport(file as File, metadataAdjustmentDate || undefined),
    onSuccess: (result) => {
      setBatch(result)
      setStep('preview')
    },
    onError: (error) => toastApiError(error),
  })

  const resolveMutation = useMutation({
    mutationFn: () => resolveSmartStockAdjustmentImport(batch?.id ?? ''),
    onSuccess: (result) => {
      setBatch(result)
      setStep('result')
      queryClient.invalidateQueries({ queryKey: ['stock-adjustments'] })
      queryClient.invalidateQueries({ queryKey: ['stock-balances-report'] })
      queryClient.invalidateQueries({ queryKey: ['stock-ledger-entries'] })
    },
    onError: (error) => toastApiError(error),
  })

  const preview = step === 'preview' ? (batch?.preview_summary as SmartStockAdjustmentPreviewSummary | null) : null
  const result = step === 'result' ? (batch?.preview_summary as SmartStockAdjustmentCommitResult | null) : null
  const hasIssues = !!preview && (preview.unmatched_items.length > 0 || preview.unmatched_warehouses.length > 0 || preview.price_conflicts.length > 0 || preview.skipped_rows.length > 0)
  const changedGroups = preview?.groups.filter((g) => g.changed_line_count > 0) ?? []
  const totalChangedLines = changedGroups.reduce((sum, g) => sum + g.changed_line_count, 0)

  return (
    <Dialog open={open} onOpenChange={(next) => !next && handleClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Smart Import — Stock Adjustment</DialogTitle>
          <DialogDescription>
            {step === 'setup' && 'Upload file export lama apa adanya — sistem cocokkan ke saldo stok berjalan, bukan bikin saldo baru.'}
            {step === 'preview' && 'Ringkasan sebelum import — hanya item yang angkanya beda dari sistem yang akan disesuaikan.'}
            {step === 'result' && (batch?.status === 'completed' ? 'Import selesai.' : 'Import gagal.')}
          </DialogDescription>
        </DialogHeader>

        {step === 'setup' && (
          <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
              <Label>File</Label>
              <input
                type="file"
                accept=".csv,.xlsx,.xls"
                onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                className="text-sm"
              />
            </div>

            <div className="flex flex-col gap-1.5">
              <Label>Adjustment Date default (opsional)</Label>
              <p className="text-xs text-muted-foreground">
                Dipakai untuk baris yang tidak punya tanggalnya sendiri di file (mis. file Stock Balance yang hanya punya
                &quot;Date From&quot;/&quot;Date To&quot; di metadata, bukan per baris).
              </p>
              <Input type="date" value={metadataAdjustmentDate} onChange={(event) => setMetadataAdjustmentDate(event.target.value)} className="w-48" />
            </div>
          </div>
        )}

        {step === 'preview' && preview && (
          <div className="flex max-h-[28rem] flex-col gap-4 overflow-y-auto">
            <div className="flex flex-col gap-2 rounded-md border p-3 text-sm">
              <div className="flex items-center justify-between gap-2">
                <span className="font-medium">Baris ditemukan</span>
                <span>{formatNumber(preview.total_rows)}</span>
              </div>
              <div className="flex items-center justify-between gap-2 text-muted-foreground">
                <span>Akan disesuaikan</span>
                <span>{formatNumber(totalChangedLines)} item di {changedGroups.length} dokumen ({Object.keys(preview.warehouses_detected).length} lokasi dicek)</span>
              </div>
            </div>

            {changedGroups.length > 0 && (
              <div className="overflow-x-auto rounded-md border">
                <table className="w-full text-sm">
                  <thead className="bg-muted/50 text-left">
                    <tr>
                      <th className="p-2">Location</th>
                      <th className="p-2">Item</th>
                      <th className="p-2 text-right">Sistem</th>
                      <th className="p-2 text-right">File</th>
                      <th className="p-2 text-right">Selisih</th>
                    </tr>
                  </thead>
                  <tbody>
                    {changedGroups.flatMap((group) =>
                      group.lines
                        .filter((line) => Math.abs(line.difference_qty) > 0.0001)
                        .map((line) => (
                          <tr key={`${group.warehouse_code}-${line.item_id}`} className="border-t">
                            <td className="p-2 font-medium">{group.warehouse_code}</td>
                            <td className="p-2">{line.item_code}</td>
                            <td className="p-2 text-right">{formatNumber(line.system_qty)}</td>
                            <td className="p-2 text-right">{formatNumber(line.qty)}</td>
                            <td className={cn('p-2 text-right font-medium', line.difference_qty > 0 ? 'text-green-600 dark:text-green-400' : 'text-destructive')}>
                              {line.difference_qty > 0 ? '+' : ''}
                              {formatNumber(line.difference_qty)}
                            </td>
                          </tr>
                        )),
                    )}
                  </tbody>
                </table>
              </div>
            )}

            {preview.cost_missing_items.length > 0 && (
              <Alert>
                <AlertTitle>Item naik stok tanpa Unit Cost (dilewati, bukan error)</AlertTitle>
                <AlertDescription>
                  <div className="flex flex-col gap-1.5 text-sm">
                    <p>
                      <Badge variant="secondary" className="mr-1.5">{preview.cost_missing_items.length}</Badge>
                      Qty di file lebih besar dari sistem tapi harga satuannya 0/kosong di file — Stock Adjustment butuh Unit Cost untuk buka layer FIFO baru, jadi item ini dilewati, bukan digagalkan satu dokumen:{' '}
                      {preview.cost_missing_items.map((u) => `${u.item_code} (${u.warehouse_code})`).join(', ')}
                    </p>
                  </div>
                </AlertDescription>
              </Alert>
            )}

            {hasIssues && (
              <Alert variant="destructive">
                <AlertTitle>Baris yang dilewati (tidak akan diimpor)</AlertTitle>
                <AlertDescription>
                  <div className="flex flex-col gap-1.5 text-sm">
                    {preview.unmatched_items.length > 0 && (
                      <p>
                        <Badge variant="secondary" className="mr-1.5">{preview.unmatched_items.length}</Badge>
                        Item Code tidak ditemukan di Item Master: {preview.unmatched_items.map((u) => u.item_code).join(', ')}
                      </p>
                    )}
                    {preview.unmatched_warehouses.length > 0 && (
                      <p>
                        <Badge variant="secondary" className="mr-1.5">{preview.unmatched_warehouses.length}</Badge>
                        Lokasi tidak ditemukan: {preview.unmatched_warehouses.map((u) => u.warehouse_code).join(', ')}
                      </p>
                    )}
                    {preview.price_conflicts.length > 0 && (
                      <p>
                        <Badge variant="secondary" className="mr-1.5">{preview.price_conflicts.length}</Badge>
                        Harga tidak konsisten pada baris duplikat — perlu direview manual, tidak digabung otomatis.
                      </p>
                    )}
                    {preview.skipped_rows.length > 0 && (
                      <p>
                        <Badge variant="secondary" className="mr-1.5">{preview.skipped_rows.length}</Badge>
                        Baris dilewati karena data tidak valid (lihat detail baris di file sumber).
                      </p>
                    )}
                  </div>
                </AlertDescription>
              </Alert>
            )}

            {changedGroups.length === 0 && preview.cost_missing_items.length === 0 && (
              <Alert>
                <CheckCircle2 className="size-4" />
                <AlertTitle>Tidak ada yang perlu disesuaikan</AlertTitle>
                <AlertDescription>Semua item yang cocok sudah sama dengan saldo di sistem.</AlertDescription>
              </Alert>
            )}
          </div>
        )}

        {step === 'result' && (
          <div className="flex flex-col gap-4">
            {result && batch?.status === 'completed' && (
              <Alert>
                <CheckCircle2 className="size-4" />
                <AlertTitle>{result.documents_created} dokumen Stock Adjustment dibuat</AlertTitle>
                <AlertDescription>
                  Lokasi: {result.warehouses.join(', ') || '-'}. {result.lines_adjusted} item disesuaikan, {result.unchanged_count} item sudah sama dengan sistem (dilewati).
                  {result.cost_missing_count > 0 && ` ${result.cost_missing_count} item dilewati karena naik stok tanpa Unit Cost.`}
                </AlertDescription>
              </Alert>
            )}
            {result && result.failures.length > 0 && (
              <Alert variant="destructive">
                <XCircle className="size-4" />
                <AlertTitle>{result.failures.length} grup gagal dibuat</AlertTitle>
                <AlertDescription>
                  <div className="flex flex-col gap-1">
                    {result.failures.map((f, index) => (
                      <p key={index}>{f.warehouse_code} ({f.adjustment_date}): {f.reason}</p>
                    ))}
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
            <Button type="button" onClick={() => resolveMutation.mutate()} disabled={changedGroups.length === 0 || resolveMutation.isPending}>
              {resolveMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Konfirmasi & Sesuaikan ({changedGroups.length} dokumen)
            </Button>
          )}
          {step === 'result' && (
            <Button type="button" onClick={handleClose}>Selesai</Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
