import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Loader2, Upload } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { toastApiError } from '@/shared/services/errorHandler'
import { fetchWarehousesLookup, searchItemsLookup } from '@/features/master/api/lookupsApi'
import { fetchPurchaseHistoryImportBatch, resolvePurchaseHistoryImport, storePurchaseHistoryImport } from '../api/purchaseHistoryImportApi'
import type { PurchaseHistoryImportPreviewSummary, PurchaseHistoryImportType, PurchaseHistoryResolutionAction } from '../types'
import type { PurchaseHistoryResolutionInput } from '../api/purchaseHistoryImportApi'

const TERMINAL_STATUSES = ['completed', 'failed']

/**
 * Each Purchase Report tab has its own Import button locked to one file type — this dialog only
 * ever asks for the input that type actually needs, and only ever links to that type's own
 * destination tab on completion. The server still auto-detects the file's real type (that's what
 * makes the pre-import summary possible) and rejects a mismatch — see
 * PurchaseHistoryImportController::store().
 */
const TYPE_CONFIG: Record<PurchaseHistoryImportType, {
  label: string
  needsWarehouse: boolean
  needsPlaceholderItem: boolean
  destinationTab: string | null
  destinationLabel: string
  processingDescription: string
}> = {
  supplier_purchase_listing: {
    label: 'Supplier Purchase Listing',
    needsWarehouse: false,
    needsPlaceholderItem: true,
    destinationTab: null,
    destinationLabel: 'Purchase Orders',
    processingDescription: 'Memproses file — membuat Purchase Order dari baris yang valid…',
  },
  product_purchase_report: {
    label: 'Product Purchase Report',
    needsWarehouse: false,
    needsPlaceholderItem: false,
    destinationTab: 'product-purchase',
    destinationLabel: 'Product Purchase',
    processingDescription: 'Memproses file — menghitung ringkasan per item…',
  },
  purchase_order_tracking: {
    label: 'Purchase Order Tracking',
    needsWarehouse: true,
    needsPlaceholderItem: true,
    destinationTab: 'po-tracking',
    destinationLabel: 'PO Tracking',
    processingDescription: 'Memproses file — membuat Purchase Order/Goods Receipt dari baris yang valid…',
  },
}

type Step = 'setup' | 'summary' | 'progress'

interface ResolutionState {
  [key: string]: { action: PurchaseHistoryResolutionAction; target_id: string | null }
}

const resolutionKey = (category: string, value: string) => `${category}:${value}`

interface PurchaseHistoryImportDialogProps {
  open: boolean
  onClose: () => void
  expectedType: PurchaseHistoryImportType
}

export function PurchaseHistoryImportDialog({ open, onClose, expectedType }: PurchaseHistoryImportDialogProps) {
  const navigate = useNavigate()
  const config = TYPE_CONFIG[expectedType]

  const [step, setStep] = useState<Step>('setup')
  const [file, setFile] = useState<File | null>(null)
  const [warehouseId, setWarehouseId] = useState<string>()
  const [placeholderItemId, setPlaceholderItemId] = useState<string>()
  const [summary, setSummary] = useState<PurchaseHistoryImportPreviewSummary | null>(null)
  const [resolutions, setResolutions] = useState<ResolutionState>({})
  const [batchId, setBatchId] = useState<string | null>(null)

  const warehousesQuery = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup, enabled: open && config.needsWarehouse })

  const reset = () => {
    setStep('setup')
    setFile(null)
    setWarehouseId(undefined)
    setPlaceholderItemId(undefined)
    setSummary(null)
    setResolutions({})
    setBatchId(null)
  }

  const handleClose = () => {
    reset()
    onClose()
  }

  // store() always leaves the batch `previewed` now — the pre-import summary must be seen and
  // explicitly confirmed (via resolve(), below) before anything is queued, even when there's
  // nothing that needs a human decision.
  const uploadMutation = useMutation({
    mutationFn: () => storePurchaseHistoryImport(file as File, expectedType, warehouseId, placeholderItemId),
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
      // Every entry ships explicitly — a duplicate left untouched still needs a real 'skip' row here,
      // since the backend's `resolutions` field is `required` and rejects an empty array outright.
      const payload: PurchaseHistoryResolutionInput[] = (needsResolution ?? []).map((entry) => {
        const resolution = resolutions[resolutionKey(entry.category, entry.value)]
        return {
          category: entry.category,
          value: entry.value,
          action: resolution?.action ?? 'skip',
          target_id: resolution?.target_id ?? null,
        }
      })
      return resolvePurchaseHistoryImport(batchId as string, payload)
    },
    onSuccess: () => setStep('progress'),
    onError: (error) => toastApiError(error),
  })

  const batchQuery = useQuery({
    queryKey: ['purchase-history-import-batch', batchId],
    queryFn: () => fetchPurchaseHistoryImportBatch(batchId as string),
    enabled: batchId !== null && step === 'progress',
    refetchInterval: (query) => (query.state.data && TERMINAL_STATUSES.includes(query.state.data.status) ? false : 1500),
  })

  const setResolution = (category: string, value: string, action: PurchaseHistoryResolutionAction, targetId: string | null) => {
    setResolutions((prev) => ({ ...prev, [resolutionKey(category, value)]: { action, target_id: targetId } }))
  }

  // Duplicates default to skip on the backend even with no entry here, so only
  // supplier/item unresolved values gate the "Process" button.
  const requiredEntries = (needsResolution ?? []).filter((entry) => entry.category !== 'duplicate')
  const allResolved = requiredEntries.every((entry) => resolutions[resolutionKey(entry.category, entry.value)] !== undefined)

  const batch = batchQuery.data
  const isDone = !!batch && TERMINAL_STATUSES.includes(batch.status)
  const progress = batch && batch.total_rows > 0 ? Math.round((batch.processed_rows / batch.total_rows) * 100) : 0
  const vouchers = batch?.preview_summary?.vouchers ?? []
  const warnings = batch?.preview_summary?.warnings ?? []
  const needsReviewCount = batch?.preview_summary?.needs_review_rows ?? 0

  const canUpload = !!file && (!config.needsWarehouse || !!warehouseId) && (!config.needsPlaceholderItem || !!placeholderItemId)

  return (
    <Dialog open={open} onOpenChange={(next) => !next && handleClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Import {config.label}</DialogTitle>
          <DialogDescription>
            {step === 'setup' && `Upload file ${config.label} apa adanya. File yang terdeteksi bukan ${config.label} akan ditolak.`}
            {step === 'summary' && 'Ringkasan sebelum import — periksa dulu sebelum melanjutkan.'}
            {step === 'progress' && !isDone && config.processingDescription}
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

            {config.needsWarehouse && (
              <div className="flex flex-col gap-1.5">
                <label className="text-sm font-medium">Warehouse Penerima</label>
                <SearchableSelect
                  options={(warehousesQuery.data ?? []).map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` }))}
                  value={warehouseId}
                  onChange={(value) => setWarehouseId(value)}
                  loading={warehousesQuery.isLoading}
                  placeholder="Pilih warehouse…"
                  clearable={false}
                />
              </div>
            )}

            {config.needsPlaceholderItem && (
              <div className="flex flex-col gap-1.5">
                <label className="text-sm font-medium">Placeholder Item</label>
                <p className="text-xs text-muted-foreground">Dipakai sebagai baris item untuk PO yang diimpor dari file ini — file itu tidak punya rincian item per baris.</p>
                <SearchableSelect
                  loadOptions={async (query) => (await searchItemsLookup(query)).map((item) => ({ value: item.id, label: `${item.item_code} — ${item.item_name}` }))}
                  value={placeholderItemId}
                  onChange={(value) => setPlaceholderItemId(value)}
                  placeholder="Cari item…"
                  clearable={false}
                />
              </div>
            )}
          </div>
        )}

        {step === 'summary' && (
          <div className="flex max-h-[28rem] flex-col gap-4 overflow-y-auto">
            <div className="flex flex-col gap-2 rounded-md border p-3 text-sm">
              <div className="flex items-center justify-between gap-2">
                <span className="font-medium">Jenis file terdeteksi</span>
                <Badge variant="secondary">{summary?.type_label ?? '—'}</Badge>
              </div>
              <div className="flex items-center justify-between gap-2 text-muted-foreground">
                <span>Baris valid vs dilewati</span>
                <span>{summary?.valid_count ?? 0} valid, {summary?.skipped_count ?? 0} dilewati</span>
              </div>
              {!!summary?.computed_defaults?.length && (
                <ul className="list-disc pl-5 text-xs text-muted-foreground">
                  {summary.computed_defaults.map((line, index) => <li key={index}>{line}</li>)}
                </ul>
              )}
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
                              setResolution(entry.category, entry.value, action as PurchaseHistoryResolutionAction, targetId || null)
                            }}
                          >
                            <SelectTrigger className="w-64">
                              <SelectValue placeholder={entry.category === 'duplicate' ? 'Skip (default)' : 'Pilih…'} />
                            </SelectTrigger>
                            <SelectContent>
                              {entry.category === 'supplier' && <SelectItem value="create:">Buat supplier baru &quot;{entry.value}&quot;</SelectItem>}
                              {entry.category === 'duplicate' ? (
                                <SelectItem value="proceed:">Import ulang (proceed)</SelectItem>
                              ) : (
                                <SelectItem value="skip:">Skip baris ini</SelectItem>
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
                <p className="text-sm text-muted-foreground">{batch.processed_rows} / {batch.total_rows} baris diproses</p>
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

                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-sm text-muted-foreground">Lihat hasilnya di:</span>
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => {
                      handleClose()
                      navigate(config.destinationTab ? `/reports/purchase?tab=${config.destinationTab}` : '/reports/purchase')
                    }}
                  >
                    {config.destinationLabel}
                  </Button>
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
            <Button type="button" onClick={handleClose} disabled={!isDone}>
              {isDone ? 'Selesai' : <Loader2 className="size-4 animate-spin" />}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
