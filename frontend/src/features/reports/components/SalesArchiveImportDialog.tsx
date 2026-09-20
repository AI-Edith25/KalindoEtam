import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Loader2, Upload } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { toast } from 'sonner'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import { toastApiError } from '@/shared/services/errorHandler'
import { resolveSalesArchiveImport, storeSalesArchiveSnapshot } from '../api/salesArchiveApi'
import type { SalesArchiveFileType, SalesArchiveImportBatch } from '../types'

type Step = 'setup' | 'preview'

interface SalesArchiveImportDialogProps {
  open: boolean
  onClose: () => void
  onImported: () => void
}

const FILE_TYPE_OPTIONS: { value: SalesArchiveFileType; label: string }[] = [
  { value: 'sales_listing', label: '01 Sales Listing' },
  { value: 'product_sales_detail', label: '13 Product Sales Report - Detail' },
]

/**
 * Upload -> preview -> confirm flow, same shape as SupplierOutstandingArchiveImportDialog, plus
 * a required "Jenis File" selector up front -- one button/modal for both file types (per the
 * ticket), the backend still independently checks the file's own row 1 against the selection.
 */
export function SalesArchiveImportDialog({ open, onClose, onImported }: SalesArchiveImportDialogProps) {
  const queryClient = useQueryClient()
  const [step, setStep] = useState<Step>('setup')
  const [fileType, setFileType] = useState<SalesArchiveFileType | null>(null)
  const [file, setFile] = useState<File | null>(null)
  const [batch, setBatch] = useState<SalesArchiveImportBatch | null>(null)

  const reset = () => {
    setStep('setup')
    setFileType(null)
    setFile(null)
    setBatch(null)
  }

  const handleClose = () => {
    reset()
    onClose()
  }

  const uploadMutation = useMutation({
    mutationFn: () => storeSalesArchiveSnapshot(file as File, fileType as SalesArchiveFileType),
    onSuccess: (result) => {
      setBatch(result)
      setStep('preview')
    },
    onError: (error) => toastApiError(error),
  })

  const resolveMutation = useMutation({
    mutationFn: () => resolveSalesArchiveImport(batch?.id ?? ''),
    onSuccess: () => {
      toast.success('Snapshot berhasil diimpor.')
      queryClient.invalidateQueries({ queryKey: ['sales-archive-meta'] })
      queryClient.invalidateQueries({ queryKey: ['sales-archive-history'] })
      onImported()
      handleClose()
    },
    onError: (error) => toastApiError(error),
  })

  const preview = batch?.preview_summary ?? null
  const hasIssues = !!preview && (
    preview.failed_rows.length > 0 || (preview.subtotal_mismatches?.length ?? 0) > 0 || !!preview.grand_total_mismatch
  )

  return (
    <Dialog open={open} onOpenChange={(next) => !next && handleClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Import Data — Sales Report</DialogTitle>
          <DialogDescription>
            {step === 'setup' && 'Pilih jenis file, lalu upload apa adanya. File yang tidak sesuai jenisnya akan ditolak.'}
            {step === 'preview' && 'Ringkasan sebelum import — periksa dulu sebelum melanjutkan.'}
          </DialogDescription>
        </DialogHeader>

        {step === 'setup' && (
          <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
              <Label>Jenis File</Label>
              <Select value={fileType ?? undefined} onValueChange={(next) => setFileType(next as SalesArchiveFileType)}>
                <SelectTrigger>
                  <SelectValue placeholder="Pilih jenis file…" />
                </SelectTrigger>
                <SelectContent>
                  {FILE_TYPE_OPTIONS.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>File</Label>
              <input type="file" accept=".csv,.xlsx,.xls" onChange={(event) => setFile(event.target.files?.[0] ?? null)} className="text-sm" disabled={!fileType} />
            </div>
          </div>
        )}

        {step === 'preview' && preview && (
          <div className="flex max-h-[28rem] flex-col gap-4 overflow-y-auto">
            <div className="flex flex-col gap-2 rounded-md border p-3 text-sm">
              <div className="flex items-center justify-between gap-2">
                <span className="font-medium">Periode</span>
                <span>{formatDate(preview.period_start)} – {formatDate(preview.period_end)}</span>
              </div>
              <div className="flex items-center justify-between gap-2 text-muted-foreground">
                <span>Baris terbaca</span>
                <span>
                  {formatNumber(preview.total_rows)} baris
                  {preview.total_documents !== undefined && `, ${formatNumber(preview.total_documents)} dokumen`}
                  {preview.total_items !== undefined && `, ${formatNumber(preview.total_items)} item`}
                </span>
              </div>
              <div className="flex items-center justify-between gap-2 text-muted-foreground">
                <span>Total Amount Excl. Tax</span>
                <span>{formatCurrency(preview.grand_total_amount_excl_tax)}</span>
              </div>
              {preview.grand_total_amount_incl_tax !== undefined && (
                <div className="flex items-center justify-between gap-2 text-muted-foreground">
                  <span>Total Amount Incl. Tax</span>
                  <span>{formatCurrency(preview.grand_total_amount_incl_tax)}</span>
                </div>
              )}
            </div>

            {hasIssues && (
              <Alert variant="destructive">
                <AlertTitle>Perlu direview</AlertTitle>
                <AlertDescription>
                  <div className="flex flex-col gap-2 text-sm">
                    {preview.grand_total_mismatch && (
                      <p>
                        <Badge variant="destructive" className="mr-1.5">Grand Total tidak cocok</Badge>
                        {preview.grand_total_mismatch.file_amount_excl_tax !== undefined ? (
                          <>
                            File: Excl. Tax {formatCurrency(preview.grand_total_mismatch.file_amount_excl_tax)}, Incl. Tax{' '}
                            {formatCurrency(preview.grand_total_mismatch.file_amount_incl_tax!)} — Hasil parsing: Excl. Tax{' '}
                            {formatCurrency(preview.grand_total_mismatch.computed_amount_excl_tax!)}, Incl. Tax{' '}
                            {formatCurrency(preview.grand_total_mismatch.computed_amount_incl_tax!)}.
                          </>
                        ) : (
                          <>
                            Jumlah subtotal item di file: {formatCurrency(preview.grand_total_mismatch.file_amount!)} — Hasil parsing:{' '}
                            {formatCurrency(preview.grand_total_mismatch.computed_amount!)}.
                          </>
                        )}
                      </p>
                    )}
                    {(preview.subtotal_mismatches?.length ?? 0) > 0 && (
                      <div>
                        <Badge variant="secondary" className="mr-1.5">{preview.subtotal_mismatches!.length}</Badge>
                        Subtotal item tidak cocok:
                        <ul className="mt-1 list-disc pl-5">
                          {preview.subtotal_mismatches!.slice(0, 10).map((m, i) => (
                            <li key={i}>
                              Baris {m.row} — {m.item_description} ({m.item_code}): file {formatCurrency(m.file_amount)}, hasil parsing{' '}
                              {formatCurrency(m.computed_amount)}
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
            <Button type="button" onClick={() => uploadMutation.mutate()} disabled={!file || !fileType || uploadMutation.isPending}>
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
