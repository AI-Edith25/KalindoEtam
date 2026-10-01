import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Download, Loader2, Upload } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { toastApiError } from '@/shared/services/errorHandler'
import { downloadImportBatchFailedRows } from '@/shared/lib/downloadImportBatchFailedRows'
import { fetchWarehousesLookup } from '@/features/master/api/lookupsApi'
import {
  fetchSalesInvoiceHistoryImportBatch,
  resolveSalesInvoiceHistoryImport,
  storeSalesInvoiceHistoryImport,
} from '../api/salesInvoiceHistoryImportApi'
import type { SalesInvoiceHistoryImportPreviewSummary, SalesInvoiceHistoryResolutionAction } from '../types'
import type { SalesInvoiceHistoryResolutionInput } from '../api/salesInvoiceHistoryImportApi'

const TERMINAL_STATUSES = ['completed', 'failed']

type Step = 'setup' | 'summary' | 'progress'

interface ResolutionState {
  [key: string]: { action: SalesInvoiceHistoryResolutionAction; target_id: string | null }
}

const resolutionKey = (category: string, value: string) => `${category}:${value}`

interface SalesInvoiceHistoryImportDialogProps {
  open: boolean
  onClose: () => void
  onImported?: () => void
}

/**
 * One file shape only ("Sales Invoice Listing - Detail") — simpler than
 * PurchaseHistoryImportDialog's multi-type dance. The only setup input is a Warehouse (a formality
 * field for Goods rows; stock never actually moves — see the backend's SalesInvoiceImportService).
 */
export function SalesInvoiceHistoryImportDialog({ open, onClose, onImported }: SalesInvoiceHistoryImportDialogProps) {
  const [step, setStep] = useState<Step>('setup')
  const [file, setFile] = useState<File | null>(null)
  const [warehouseId, setWarehouseId] = useState<string>()
  const [summary, setSummary] = useState<SalesInvoiceHistoryImportPreviewSummary | null>(null)
  const [resolutions, setResolutions] = useState<ResolutionState>({})
  const [batchId, setBatchId] = useState<string | null>(null)

  const warehousesQuery = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup, enabled: open })

  const reset = () => {
    setStep('setup')
    setFile(null)
    setWarehouseId(undefined)
    setSummary(null)
    setResolutions({})
    setBatchId(null)
  }

  const handleClose = () => {
    reset()
    onClose()
  }

  const uploadMutation = useMutation({
    mutationFn: () => storeSalesInvoiceHistoryImport(file as File, warehouseId as string),
    onSuccess: (batch) => {
      setBatchId(batch.id)
      setSummary(batch.preview_summary ?? null)
      setStep('summary')
    },
    onError: (error) => toastApiError(error),
  })

  const needsResolution = summary?.needs_resolution ?? []

  const resolveMutation = useMutation({
    mutationFn: () => {
      const payload: SalesInvoiceHistoryResolutionInput[] = (needsResolution ?? []).map((entry) => {
        const resolution = resolutions[resolutionKey(entry.category, entry.value)]
        return {
          category: entry.category,
          value: entry.value,
          action: resolution?.action ?? 'skip',
          target_id: resolution?.target_id ?? null,
        }
      })
      return resolveSalesInvoiceHistoryImport(batchId as string, payload)
    },
    onSuccess: () => setStep('progress'),
    onError: (error) => toastApiError(error),
  })

  const batchQuery = useQuery({
    queryKey: ['sales-invoice-history-import-batch', batchId],
    queryFn: () => fetchSalesInvoiceHistoryImportBatch(batchId as string),
    enabled: batchId !== null && step === 'progress',
    refetchInterval: (query) => (query.state.data && TERMINAL_STATUSES.includes(query.state.data.status) ? false : 1500),
  })

  const downloadMutation = useMutation({
    mutationFn: () => downloadImportBatchFailedRows(batchId as string, 'sales-invoice-history'),
    onError: (error) => toastApiError(error),
  })

  const setResolution = (category: string, value: string, action: SalesInvoiceHistoryResolutionAction, targetId: string | null) => {
    setResolutions((prev) => ({ ...prev, [resolutionKey(category, value)]: { action, target_id: targetId } }))
  }

  // Duplicates default to skip on the backend even with no entry here, so only
  // customer/item unresolved values gate the "Process" button.
  const requiredEntries = (needsResolution ?? []).filter((entry) => entry.category !== 'duplicate')
  const allResolved = requiredEntries.every((entry) => resolutions[resolutionKey(entry.category, entry.value)] !== undefined)

  const batch = batchQuery.data
  const isDone = !!batch && TERMINAL_STATUSES.includes(batch.status)
  const progress = batch && batch.total_rows > 0 ? Math.round((batch.processed_rows / batch.total_rows) * 100) : 0
  const vouchers = batch?.preview_summary?.vouchers ?? []
  const warnings = batch?.preview_summary?.warnings ?? []
  const needsReviewCount = batch?.preview_summary?.needs_review_rows ?? 0

  const canUpload = !!file && !!warehouseId

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) {
          if (isDone && batch?.status === 'completed') onImported?.()
          handleClose()
        }
      }}
    >
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Import Sales Invoice</DialogTitle>
          <DialogDescription>
            {step === 'setup' && 'Upload file "Sales Invoice Listing - Detail" apa adanya. File yang terdeteksi bukan format ini akan ditolak.'}
            {step === 'summary' && 'Ringkasan sebelum import — periksa dulu sebelum melanjutkan.'}
            {step === 'progress' && !isDone && 'Memproses file — membuat Invoice dari dokumen yang valid…'}
            {step === 'progress' && isDone && batch?.status === 'completed' && 'Import selesai. Berikut ringkasan hasilnya.'}
            {step === 'progress' && isDone && batch?.status === 'failed' && 'Import tidak bisa diproses.'}
          </DialogDescription>
        </DialogHeader>

        {step === 'setup' && (
          <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium">File</label>
              <input
                type="file"
                accept=".csv,.xlsx,.xls"
                onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                className="text-sm"
              />
            </div>

            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium">Warehouse</label>
              <p className="text-xs text-muted-foreground">Data historis ini tidak terikat stock — Warehouse hanya diperlukan sebagai kelengkapan data Invoice, tidak memengaruhi stock.</p>
              <SearchableSelect
                options={(warehousesQuery.data ?? []).map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` }))}
                value={warehouseId}
                onChange={(value) => setWarehouseId(value)}
                loading={warehousesQuery.isLoading}
                placeholder="Pilih warehouse…"
                clearable={false}
              />
            </div>
          </div>
        )}

        {step === 'summary' && (
          <div className="flex max-h-[28rem] flex-col gap-4 overflow-y-auto">
            <div className="flex flex-col gap-2 rounded-md border p-3 text-sm">
              <div className="flex items-center justify-between gap-2 text-muted-foreground">
                <span>Dokumen valid vs dilewati</span>
                <span>{summary?.valid_count ?? 0} valid, {summary?.skipped_count ?? 0} dilewati</span>
              </div>
            </div>

            {!!summary?.warnings?.length && (
              <Alert>
                <AlertTitle>Peringatan</AlertTitle>
                <AlertDescription>
                  {summary.warnings.map((warning, index) => <p key={index}>{warning}</p>)}
                </AlertDescription>
              </Alert>
            )}

            {needsResolution.length > 0 && (
            <div className="overflow-x-auto rounded-md border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Jenis</TableHead>
                    <TableHead>Nilai di File</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Keputusan</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {needsResolution.map((entry) => {
                    const key = resolutionKey(entry.category, entry.value)
                    const resolution = resolutions[key]

                    return (
                      <TableRow key={key}>
                        <TableCell className="capitalize">{entry.category}</TableCell>
                        <TableCell>{entry.value}</TableCell>
                        <TableCell>
                          <Badge variant={entry.category === 'duplicate' ? 'secondary' : 'destructive'}>
                            {entry.category === 'duplicate' ? 'Sudah pernah diimpor' : entry.status === 'ambiguous' ? 'Kemungkinan cocok' : 'Tidak ditemukan'}
                          </Badge>
                        </TableCell>
                        <TableCell>
                          <Select
                            value={resolution ? `${resolution.action}:${resolution.target_id ?? ''}` : ''}
                            onValueChange={(v) => {
                              const [action, targetId] = v.split(':')
                              setResolution(entry.category, entry.value, action as SalesInvoiceHistoryResolutionAction, targetId || null)
                            }}
                          >
                            <SelectTrigger className="w-64">
                              <SelectValue placeholder={entry.category === 'duplicate' ? 'Skip (default)' : 'Pilih…'} />
                            </SelectTrigger>
                            <SelectContent>
                              {entry.category === 'duplicate' ? (
                                <SelectItem value="proceed:">Import ulang (proceed)</SelectItem>
                              ) : (
                                <SelectItem value="skip:">Skip dokumen ini</SelectItem>
                              )}
                              {entry.suggestions.map((suggestion) => (
                                <SelectItem key={suggestion.id} value={`map:${suggestion.id}`}>
                                  Gunakan &quot;{suggestion.value}&quot; ({suggestion.score}% cocok)
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </TableCell>
                      </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            </div>
            )}
          </div>
        )}

        {step === 'progress' && (
          <div className="flex flex-col gap-4">
            {!batch && (
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />
                Memulai…
              </div>
            )}

            {batch && !isDone && (
              <div className="flex flex-col gap-2">
                <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                  <div className="h-full bg-primary transition-all" style={{ width: `${progress}%` }} />
                </div>
                <p className="text-sm text-muted-foreground">{batch.processed_rows} / {batch.total_rows} dokumen diproses</p>
              </div>
            )}

            {batch && isDone && batch.status === 'failed' && (
              <Alert variant="destructive">
                <AlertTitle>Import gagal</AlertTitle>
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

                {warnings.length > 0 && (
                  <Alert>
                    <AlertTitle>Peringatan</AlertTitle>
                    <AlertDescription>
                      {warnings.map((warning, index) => (
                        <p key={index}>{warning}</p>
                      ))}
                    </AlertDescription>
                  </Alert>
                )}

                <div className="flex max-h-64 flex-col gap-2 overflow-y-auto rounded-md border p-2">
                  {vouchers.map((voucher, index) => (
                    <div key={`${voucher.document_number}-${index}`} className="flex flex-col gap-1 border-b pb-2 last:border-b-0 last:pb-0">
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
          </div>
        )}

        <DialogFooter>
          {step === 'setup' && (
            <Button type="button" onClick={() => uploadMutation.mutate()} disabled={!canUpload || uploadMutation.isPending}>
              {uploadMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              <Upload className="size-4" />
              Upload
            </Button>
          )}
          {step === 'summary' && (
            <Button type="button" onClick={() => resolveMutation.mutate()} disabled={!allResolved || resolveMutation.isPending}>
              {resolveMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Lanjutkan Import
            </Button>
          )}
          {step === 'progress' && (
            <>
              {batch?.has_failed_rows && (
                <Button type="button" variant="outline" onClick={() => downloadMutation.mutate()} disabled={downloadMutation.isPending}>
                  <Download className="size-4" />
                  Unduh Baris Ditolak
                </Button>
              )}
              <Button
                type="button"
                onClick={() => {
                  if (isDone && batch?.status === 'completed') onImported?.()
                  handleClose()
                }}
                disabled={!isDone}
              >
                {isDone ? 'Selesai' : <Loader2 className="size-4 animate-spin" />}
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
