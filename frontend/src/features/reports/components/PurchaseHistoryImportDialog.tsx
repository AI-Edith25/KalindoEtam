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
import type { PurchaseHistoryImportPreviewSummary, PurchaseHistoryResolutionAction } from '../types'
import type { PurchaseHistoryResolutionInput } from '../api/purchaseHistoryImportApi'

const TERMINAL_STATUSES = ['completed', 'failed']
const REPORT_TABS = [
  { value: 'by-supplier', label: 'By Supplier' },
  { value: 'by-item', label: 'By Item' },
  { value: 'po-tracking', label: 'PO Tracking' },
]

type Step = 'setup' | 'resolve' | 'progress'

interface ResolutionState {
  [key: string]: { action: PurchaseHistoryResolutionAction; target_id: string | null }
}

const resolutionKey = (category: string, value: string) => `${category}:${value}`

interface PurchaseHistoryImportDialogProps {
  open: boolean
  onClose: () => void
}

/**
 * Purchase Orders tab's "Import" button — upload Product Purchase Report or Purchase Order
 * Tracking (auto-detected server-side), resolve unmatched suppliers/items/duplicates only if
 * any come back, then watch the real Purchase Order/Goods Receipt documents get created. See
 * PurchaseHistoryImportService for why this posts real documents instead of a report row.
 */
export function PurchaseHistoryImportDialog({ open, onClose }: PurchaseHistoryImportDialogProps) {
  const navigate = useNavigate()

  const [step, setStep] = useState<Step>('setup')
  const [file, setFile] = useState<File | null>(null)
  const [warehouseId, setWarehouseId] = useState<string>()
  const [placeholderItemId, setPlaceholderItemId] = useState<string>()
  const [needsResolution, setNeedsResolution] = useState<PurchaseHistoryImportPreviewSummary['needs_resolution']>([])
  const [resolutions, setResolutions] = useState<ResolutionState>({})
  const [batchId, setBatchId] = useState<string | null>(null)

  const warehousesQuery = useQuery({ queryKey: ['warehouses-lookup'], queryFn: fetchWarehousesLookup, enabled: open })

  const reset = () => {
    setStep('setup')
    setFile(null)
    setWarehouseId(undefined)
    setPlaceholderItemId(undefined)
    setNeedsResolution([])
    setResolutions({})
    setBatchId(null)
  }

  const handleClose = () => {
    reset()
    onClose()
  }

  const uploadMutation = useMutation({
    mutationFn: () => storePurchaseHistoryImport(file as File, warehouseId as string, placeholderItemId as string),
    onSuccess: (batch) => {
      setBatchId(batch.id)
      const entries = batch.preview_summary?.needs_resolution ?? []
      setNeedsResolution(entries)
      setStep(entries.length > 0 ? 'resolve' : 'progress')
    },
    onError: (error) => toastApiError(error),
  })

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

  return (
    <Dialog open={open} onOpenChange={(next) => !next && handleClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Import Purchase History</DialogTitle>
          <DialogDescription>
            {step === 'setup' && 'Upload file Product Purchase Report atau Purchase Order Tracking apa adanya — jenisnya dideteksi otomatis.'}
            {step === 'resolve' && 'Beberapa nilai di file tidak cocok dengan data master — putuskan cara menanganinya sebelum lanjut.'}
            {step === 'progress' && !isDone && 'Memproses file — membuat Purchase Order/Goods Receipt dari baris yang valid…'}
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

            <div className="flex flex-col gap-1.5">
              <label className="text-sm font-medium">Placeholder Item (Purchase Order Tracking)</label>
              <p className="text-xs text-muted-foreground">Dipakai sebagai baris item untuk PO yang diimpor dari Purchase Order Tracking — file itu tidak punya rincian item per baris.</p>
              <SearchableSelect
                loadOptions={async (query) => (await searchItemsLookup(query)).map((item) => ({ value: item.id, label: `${item.item_code} — ${item.item_name}` }))}
                value={placeholderItemId}
                onChange={(value) => setPlaceholderItemId(value)}
                placeholder="Cari item…"
                clearable={false}
              />
            </div>
          </div>
        )}

        {step === 'resolve' && (
          <div className="flex max-h-96 flex-col gap-4 overflow-y-auto">
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
                  {(needsResolution ?? []).map((entry) => {
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
                  {REPORT_TABS.map((reportTab) => (
                    <Button
                      key={reportTab.value}
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => {
                        handleClose()
                        navigate(`/reports/purchase?tab=${reportTab.value}`)
                      }}
                    >
                      {reportTab.label}
                    </Button>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}

        <DialogFooter>
          {step === 'setup' && (
            <Button
              type="button"
              onClick={() => uploadMutation.mutate()}
              disabled={!file || !warehouseId || !placeholderItemId || uploadMutation.isPending}
            >
              {uploadMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              <Upload className="size-4" />
              Upload
            </Button>
          )}
          {step === 'resolve' && (
            <Button type="button" onClick={() => resolveMutation.mutate()} disabled={!allResolved || resolveMutation.isPending}>
              {resolveMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Proses Import
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
