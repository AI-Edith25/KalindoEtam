import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
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
import { resolveSalesInvoiceDetailBackfill, storeSalesInvoiceDetailBackfill } from '../api/invoiceDetailBackfillApi'
import type { SalesInvoiceDetailBackfillImportBatch, SalesInvoiceDetailBackfillSkippedRow } from '../types'

const MODULE = 'sales-invoice-detail-backfill'

function SkippedRowsList({ rows }: { rows: SalesInvoiceDetailBackfillSkippedRow[] }) {
  return (
    <ul className="mt-1 list-disc pl-5">
      {rows.slice(0, 10).map((row, i) => (
        <li key={i}>{row.document_number}</li>
      ))}
      {rows.length > 10 && (
        <li className="list-none text-xs italic">...dan {formatNumber(rows.length - 10)} baris lainnya — unduh CSV untuk daftar lengkap.</li>
      )}
    </ul>
  )
}

type Step = 'setup' | 'preview'

interface SalesInvoiceDetailBackfillImportDialogProps {
  open: boolean
  onClose: () => void
  onImported: () => void
}

/**
 * Upload -> preview -> confirm. Only fills in real item detail on Invoices already created by
 * the Customer Outstanding Bills import — never creates a new Invoice. See
 * SalesInvoiceDetailBackfillService's own docblock for the matching/validation rules.
 */
export function SalesInvoiceDetailBackfillImportDialog({ open, onClose, onImported }: SalesInvoiceDetailBackfillImportDialogProps) {
  const queryClient = useQueryClient()
  const [step, setStep] = useState<Step>('setup')
  const [file, setFile] = useState<File | null>(null)
  const [batch, setBatch] = useState<SalesInvoiceDetailBackfillImportBatch | null>(null)

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
    mutationFn: () => storeSalesInvoiceDetailBackfill(file as File),
    onSuccess: (result) => {
      setBatch(result)
      setStep('preview')
    },
    onError: (error) => toastApiError(error),
  })

  const resolveMutation = useMutation({
    mutationFn: () => resolveSalesInvoiceDetailBackfill(batch?.id ?? ''),
    onSuccess: (result) => {
      toast.success(`Detail invoice berhasil disinkronkan: ${result.updated} Invoice diperbarui.`)
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      onImported()
      handleClose()
    },
    onError: (error) => toastApiError(error),
  })

  const downloadMutation = useMutation({
    mutationFn: () => downloadImportBatchFailedRows(batch?.id ?? '', MODULE),
    onError: (error) => toastApiError(error),
  })

  const preview = batch?.preview_summary ?? null
  const hasSkipped =
    !!preview &&
    (preview.skipped_not_found.length > 0 ||
      preview.skipped_customer_mismatch.length > 0 ||
      preview.skipped_type_mismatch.length > 0 ||
      preview.skipped_item_unresolved.length > 0 ||
      preview.skipped_amount_mismatch.length > 0)

  return (
    <Dialog open={open} onOpenChange={(next) => !next && handleClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Import Detail — Sales Invoice Listing</DialogTitle>
          <DialogDescription>
            {step === 'setup' &&
              'Upload file export "Sales Invoice Listing - Detail" (.xlsx) apa adanya. Hanya invoice yang sudah ada di sistem ini (hasil import Customer Outstanding Bills) dan nomor dokumennya cocok akan diperbarui detailnya — tidak ada invoice baru yang dibuat.'}
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
                <span className="font-medium">Akan diperbarui</span>
                <Badge variant="secondary">
                  {formatNumber(preview.will_update)} dari {formatNumber(preview.total_rows)} invoice di file
                </Badge>
              </div>
            </div>

            {hasSkipped && (
              <Alert variant="destructive">
                <AlertTitle>Dilewati</AlertTitle>
                <AlertDescription>
                  <div className="flex flex-col gap-2 text-sm">
                    {preview.skipped_not_found.length > 0 && (
                      <div>
                        <Badge variant="secondary" className="mr-1.5">{preview.skipped_not_found.length}</Badge>
                        Nomor dokumen tidak ditemukan pada Invoice hasil import Customer Outstanding Bills:
                        <SkippedRowsList rows={preview.skipped_not_found} />
                      </div>
                    )}
                    {preview.skipped_customer_mismatch.length > 0 && (
                      <div>
                        <Badge variant="secondary" className="mr-1.5">{preview.skipped_customer_mismatch.length}</Badge>
                        Customer pada file tidak cocok dengan Invoice yang ditemukan:
                        <SkippedRowsList rows={preview.skipped_customer_mismatch} />
                      </div>
                    )}
                    {preview.skipped_type_mismatch.length > 0 && (
                      <div>
                        <Badge variant="secondary" className="mr-1.5">{preview.skipped_type_mismatch.length}</Badge>
                        Jenis dokumen tidak cocok dengan Invoice yang ditemukan:
                        <SkippedRowsList rows={preview.skipped_type_mismatch} />
                      </div>
                    )}
                    {preview.skipped_item_unresolved.length > 0 && (
                      <div>
                        <Badge variant="secondary" className="mr-1.5">{preview.skipped_item_unresolved.length}</Badge>
                        Ada kode ITEM yang tidak ditemukan di master Item maupun Miscellaneous Item:
                        <SkippedRowsList rows={preview.skipped_item_unresolved} />
                      </div>
                    )}
                    {preview.skipped_amount_mismatch.length > 0 && (
                      <div>
                        <Badge variant="secondary" className="mr-1.5">{preview.skipped_amount_mismatch.length}</Badge>
                        Total hasil hitung ulang berbeda dari grand_total Invoice yang sudah ada:
                        <ul className="mt-1 list-disc pl-5">
                          {preview.skipped_amount_mismatch.slice(0, 10).map((row, i) => (
                            <li key={i}>
                              {row.document_number}: file {formatCurrency(row.file_amount ?? 0)}, hasil hitung {formatCurrency(row.computed_amount ?? 0)}
                            </li>
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
          {step === 'preview' && batch?.has_failed_rows && (
            <Button type="button" variant="outline" onClick={() => downloadMutation.mutate()} disabled={downloadMutation.isPending}>
              {downloadMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
              Unduh Baris Dilewati
            </Button>
          )}
          {step === 'preview' && (
            <Button type="button" onClick={() => resolveMutation.mutate()} disabled={resolveMutation.isPending}>
              {resolveMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Konfirmasi & Sinkronkan
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
