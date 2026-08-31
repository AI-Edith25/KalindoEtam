import { useMutation, useQuery } from '@tanstack/react-query'
import { Download } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { toastApiError } from '@/shared/services/errorHandler'
import { downloadFailedRows, fetchImportBatch } from '../../api/importApi'
import type { ImportBatchStatus } from '../../types/import'

const TERMINAL_STATUSES: ImportBatchStatus[] = ['completed', 'failed']

interface ImportStepCommitProps {
  batchId: string
  module: string
  onDone: () => void
}

export function ImportStepCommit({ batchId, module, onDone }: ImportStepCommitProps) {
  const batchQuery = useQuery({
    queryKey: ['import-batch', batchId],
    queryFn: () => fetchImportBatch(batchId),
    refetchInterval: (query) => (query.state.data && TERMINAL_STATUSES.includes(query.state.data.status) ? false : 1500),
  })

  const downloadMutation = useMutation({
    mutationFn: () => downloadFailedRows(batchId, module),
    onError: (error) => toastApiError(error),
  })

  const batch = batchQuery.data

  if (!batch) {
    return <p className="text-sm text-muted-foreground">Loading…</p>
  }

  const isDone = TERMINAL_STATUSES.includes(batch.status)
  const progress = batch.total_rows > 0 ? Math.round((batch.processed_rows / batch.total_rows) * 100) : 0

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h2 className="font-medium">Step 5 — Commit</h2>
        <p className="text-sm text-muted-foreground">
          {batch.status === 'queued' && 'Queued — waiting for the import worker.'}
          {batch.status === 'processing' && 'Importing…'}
          {batch.status === 'completed' && 'Import completed.'}
          {batch.status === 'failed' && 'Import failed.'}
        </p>
      </div>

      <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
        <div className="h-full bg-primary transition-all" style={{ width: `${progress}%` }} />
      </div>
      <p className="text-sm text-muted-foreground">
        {batch.processed_rows} / {batch.total_rows} rows processed
      </p>

      {batch.status === 'failed' && batch.failure_reason && (
        <Alert variant="destructive">
          <AlertTitle>Import failed</AlertTitle>
          <AlertDescription>{batch.failure_reason}</AlertDescription>
        </Alert>
      )}

      {isDone && (
        <div className="flex gap-4 text-sm">
          <span className="text-green-600">Succeeded: {batch.success_rows}</span>
          <span className="text-destructive">Failed: {batch.failed_rows}</span>
        </div>
      )}

      <div className="flex items-center gap-2">
        {batch.has_failed_rows && (
          <Button type="button" variant="outline" onClick={() => downloadMutation.mutate()} disabled={downloadMutation.isPending}>
            <Download className="size-4" />
            Download Failed Rows
          </Button>
        )}
        {isDone && (
          <Button type="button" onClick={onDone}>
            Done
          </Button>
        )}
      </div>
    </div>
  )
}
