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
import { formatNumber } from '@/lib/utils'
import { toastApiError } from '@/shared/services/errorHandler'
import { resolveSmartOpeningStockImport, storeSmartOpeningStockImport } from '../api/smartOpeningStockImportApi'
import type { SmartOpeningStockCommitResult, SmartOpeningStockImportBatch, SmartOpeningStockPreviewSummary } from '../types'

type Step = 'setup' | 'preview' | 'result'

interface SmartOpeningStockImportDialogProps {
  open: boolean
  onClose: () => void
}

/**
 * "Smart Import" for raw legacy exports (FIFO Opening Quantity, Stock Balance, ...) -- accepts
 * the file as-is, no manual cleaning/column-mapping. Separate dialog from the strict-template
 * Quick Import page, matching this app's own established "smart import" pattern
 * (PurchaseHistoryImportDialog) rather than a shared component with the working strict flow.
 *
 * Only 2 real steps, unlike PurchaseHistoryImportDialog: there's no per-row resolution here --
 * an unmatched item/warehouse or a price conflict is simply excluded from commit and reported,
 * never something the user maps inline. resolve() also runs synchronously on the server (these
 * files are small) and returns the finished result immediately, so there's no progress-polling
 * step either.
 */
export function SmartOpeningStockImportDialog({ open, onClose }: SmartOpeningStockImportDialogProps) {
  const queryClient = useQueryClient()

  const [step, setStep] = useState<Step>('setup')
  const [file, setFile] = useState<File | null>(null)
  const [metadataCutoffDate, setMetadataCutoffDate] = useState('')
  const [batch, setBatch] = useState<SmartOpeningStockImportBatch | null>(null)

  const reset = () => {
    setStep('setup')
    setFile(null)
    setMetadataCutoffDate('')
    setBatch(null)
  }

  const handleClose = () => {
    reset()
    onClose()
  }

  const uploadMutation = useMutation({
    mutationFn: () => storeSmartOpeningStockImport(file as File, metadataCutoffDate || undefined),
    onSuccess: (result) => {
      setBatch(result)
      setStep('preview')
    },
    onError: (error) => toastApiError(error),
  })

  const resolveMutation = useMutation({
    mutationFn: () => resolveSmartOpeningStockImport(batch?.id ?? ''),
    onSuccess: (result) => {
      setBatch(result)
      setStep('result')
      queryClient.invalidateQueries({ queryKey: ['opening-stocks'] })
    },
    onError: (error) => toastApiError(error),
  })

  const preview = step === 'preview' ? (batch?.preview_summary as SmartOpeningStockPreviewSummary | null) : null
  const result = step === 'result' ? (batch?.preview_summary as SmartOpeningStockCommitResult | null) : null
  const hasIssues = !!preview && (preview.unmatched_items.length > 0 || preview.unmatched_warehouses.length > 0 || preview.price_conflicts.length > 0 || preview.skipped_rows.length > 0)

  return (
    <Dialog open={open} onOpenChange={(next) => !next && handleClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Smart Import — Opening Stock</DialogTitle>
          <DialogDescription>
            {step === 'setup' && 'Upload file export lama apa adanya — header, kolom, dan warehouse terdeteksi otomatis.'}
            {step === 'preview' && 'Ringkasan sebelum import — periksa dulu sebelum melanjutkan.'}
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
              <Label>Cutoff Date default (opsional)</Label>
              <p className="text-xs text-muted-foreground">
                Dipakai untuk baris yang tidak punya tanggalnya sendiri di file (mis. file Stock Balance yang hanya punya
                &quot;Date From&quot;/&quot;Date To&quot; di metadata, bukan per baris).
              </p>
              <Input type="date" value={metadataCutoffDate} onChange={(event) => setMetadataCutoffDate(event.target.value)} className="w-48" />
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
                <span>Akan diimpor menjadi</span>
                <span>{preview.groups.length} dokumen ({Object.keys(preview.warehouses_detected).length} warehouse)</span>
              </div>
            </div>

            <div className="overflow-x-auto rounded-md border">
              <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                  <tr>
                    <th className="p-2">Warehouse</th>
                    <th className="p-2">Cutoff Date</th>
                    <th className="p-2 text-right">Baris</th>
                    <th className="p-2 text-right">Total Qty</th>
                    <th className="p-2 text-right">Total Nilai</th>
                  </tr>
                </thead>
                <tbody>
                  {preview.groups.map((group, index) => (
                    <tr key={index} className="border-t">
                      <td className="p-2 font-medium">{group.warehouse_code}</td>
                      <td className="p-2">{group.cutoff_date}</td>
                      <td className="p-2 text-right">{group.lines.length}</td>
                      <td className="p-2 text-right">{formatNumber(group.total_qty)}</td>
                      <td className="p-2 text-right">{formatNumber(group.total_value)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

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
                        Warehouse tidak ditemukan: {preview.unmatched_warehouses.map((u) => u.warehouse_code).join(', ')}
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
          </div>
        )}

        {step === 'result' && (
          <div className="flex flex-col gap-4">
            {result && batch?.status === 'completed' && (
              <Alert>
                <CheckCircle2 className="size-4" />
                <AlertTitle>{result.documents_created} dokumen Opening Stock dibuat</AlertTitle>
                <AlertDescription>
                  Warehouse: {result.warehouses.join(', ') || '-'}. Total qty: {formatNumber(result.total_qty)}.
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
                      <p key={index}>{f.warehouse_code} ({f.cutoff_date}): {f.reason}</p>
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
            <Button type="button" onClick={() => resolveMutation.mutate()} disabled={preview?.groups.length === 0 || resolveMutation.isPending}>
              {resolveMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Konfirmasi & Import ({preview?.groups.length ?? 0} dokumen)
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
