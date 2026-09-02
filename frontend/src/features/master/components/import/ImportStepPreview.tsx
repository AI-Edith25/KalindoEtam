import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { DataTable, type DataTableColumn } from '@/components/shared/DataTable'
import { ErrorState } from '@/components/shared/ErrorState'
import { toastApiError } from '@/shared/services/errorHandler'
import { commitImportBatch, runImportPreview } from '../../api/importApi'
import type { CommitMode, PreviewRow, WriteMode } from '../../types/import'

interface ImportStepPreviewProps {
  batchId: string
  onCommitted: () => void
  onBack: () => void
}

export function ImportStepPreview({ batchId, onCommitted, onBack }: ImportStepPreviewProps) {
  const [writeMode, setWriteMode] = useState<WriteMode>('upsert')
  const [commitMode, setCommitMode] = useState<CommitMode>('skip_invalid')

  const previewQuery = useQuery({
    queryKey: ['import-preview', batchId],
    queryFn: () => runImportPreview(batchId),
  })

  const commitMutation = useMutation({
    mutationFn: () => commitImportBatch(batchId, writeMode, commitMode),
    onSuccess: onCommitted,
    onError: (error) => toastApiError(error),
  })

  if (previewQuery.isLoading) {
    return <p className="text-sm text-muted-foreground">Validating rows…</p>
  }

  if (previewQuery.isError || !previewQuery.data) {
    return <ErrorState onRetry={() => previewQuery.refetch()} />
  }

  const result = previewQuery.data

  const columns: DataTableColumn<PreviewRow>[] = [
    { header: '#', accessor: (row) => row.row_number },
    {
      header: 'Status',
      accessor: (row) => (
        <Badge variant={row.status === 'valid' ? 'default' : row.status === 'warning' ? 'secondary' : 'destructive'}>{row.status}</Badge>
      ),
    },
    {
      header: 'Data',
      accessor: (row) =>
        Object.values(row.data)
          .filter((value) => value !== null && value !== undefined && value !== '')
          .join(' · '),
    },
    { header: 'Messages', accessor: (row) => row.messages.join('; ') || '—' },
  ]

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h2 className="font-medium">Step 4 — Preview & Validate</h2>
        <p className="text-sm text-muted-foreground">Dry run — nothing is written yet.</p>
      </div>

      <div className="flex gap-4 text-sm">
        <span>Total: {result.summary.total}</span>
        <span className="text-green-600">Valid: {result.summary.valid}</span>
        <span className="text-amber-600">Warning: {result.summary.warning}</span>
        <span className="text-destructive">Error: {result.summary.error}</span>
      </div>

      {result.summary.valid === 0 && result.summary.total > 0 && (
        <p className="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          No valid rows to import. Check the Messages column below, then go back to fix mapping or FK resolutions.
        </p>
      )}

      <DataTable columns={columns} data={result.rows} rowKey={(row) => String(row.row_number)} />

      <div className="flex flex-wrap items-center gap-4">
        <div className="flex items-center gap-2">
          <span className="text-sm">Write mode</span>
          <Select value={writeMode} onValueChange={(value) => setWriteMode(value as WriteMode)}>
            <SelectTrigger className="w-56">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="upsert">Insert & Update (upsert)</SelectItem>
              <SelectItem value="insert_only">Insert only</SelectItem>
              <SelectItem value="update_only">Update only</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="flex items-center gap-2">
          <span className="text-sm">On error</span>
          <Select value={commitMode} onValueChange={(value) => setCommitMode(value as CommitMode)}>
            <SelectTrigger className="w-64">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="skip_invalid">Skip invalid rows, import the rest</SelectItem>
              <SelectItem value="all_or_nothing">All-or-nothing (cancel if any error)</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="flex items-center gap-2">
        <Button type="button" variant="outline" onClick={onBack}>
          Back
        </Button>
        <Button
          type="button"
          onClick={() => commitMutation.mutate()}
          disabled={commitMutation.isPending || result.summary.total === 0 || result.summary.valid === 0}
        >
          {commitMutation.isPending && <Loader2 className="size-4 animate-spin" />}
          Commit Import
        </Button>
      </div>
    </div>
  )
}
