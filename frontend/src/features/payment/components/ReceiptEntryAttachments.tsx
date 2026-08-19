import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, FileText, Loader2, Trash2, Upload } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { DeleteDialog } from '@/components/shared/DeleteDialog'
import { toastApiError } from '@/shared/services/errorHandler'
import type { DocumentAttachment } from '@/features/administration/types'
import {
  deleteReceiptEntryAttachment,
  fetchReceiptEntryAttachmentObjectUrl,
  fetchReceiptEntryAttachments,
  uploadReceiptEntryAttachment,
} from '../api/receiptEntryAttachmentApi'

/** Image thumbnail fetched as an authenticated blob (same fn the Download button uses) — attachments have no public URL. */
function AttachmentThumbnail({ attachment }: { attachment: DocumentAttachment }) {
  const thumbQuery = useQuery({
    queryKey: ['receipt-entry-attachment-thumb', attachment.id],
    queryFn: () => fetchReceiptEntryAttachmentObjectUrl(attachment.id),
    staleTime: Infinity,
  })

  if (!thumbQuery.data) {
    return <div className="flex size-10 shrink-0 items-center justify-center rounded border bg-muted" />
  }

  return <img src={thumbQuery.data} alt={attachment.original_filename} className="size-10 shrink-0 rounded border object-cover" />
}

/** D1 (UAT review 2026-08-12) — bukti transfer. Shared between the Editor (upload) and Detail (read-only) pages. */
export function ReceiptEntryAttachments({ receiptEntryId, showUpload = false }: { receiptEntryId: string; showUpload?: boolean }) {
  const queryClient = useQueryClient()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [deletingId, setDeletingId] = useState<string | null>(null)

  const attachmentsQuery = useQuery({
    queryKey: ['receipt-entry-attachments', receiptEntryId],
    queryFn: () => fetchReceiptEntryAttachments(receiptEntryId),
  })

  const uploadMutation = useMutation({
    mutationFn: (file: File) => uploadReceiptEntryAttachment(receiptEntryId, file),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['receipt-entry-attachments', receiptEntryId] })
      toast.success('File attached.')
    },
    onError: (error) => toastApiError(error),
  })

  const deleteMutation = useMutation({
    mutationFn: (attachmentId: string) => deleteReceiptEntryAttachment(attachmentId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['receipt-entry-attachments', receiptEntryId] })
      toast.success('Attachment deleted.')
    },
    onError: (error) => toastApiError(error),
    onSettled: () => setDeletingId(null),
  })

  const handleFileChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    if (file) uploadMutation.mutate(file)
    event.target.value = ''
  }

  const handleDownload = async (attachment: DocumentAttachment) => {
    const url = await fetchReceiptEntryAttachmentObjectUrl(attachment.id)
    const link = document.createElement('a')
    link.href = url
    link.download = attachment.original_filename
    link.click()
    URL.revokeObjectURL(url)
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle>Attachments</CardTitle>
        {showUpload && (
          <>
            <input ref={fileInputRef} type="file" accept="image/*,application/pdf" className="hidden" onChange={handleFileChange} />
            <Button type="button" variant="outline" size="sm" onClick={() => fileInputRef.current?.click()} disabled={uploadMutation.isPending}>
              {uploadMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Upload className="size-4" />}
              Attach File
            </Button>
          </>
        )}
      </CardHeader>
      <CardContent>
        {attachmentsQuery.isLoading ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : !attachmentsQuery.data?.length ? (
          <p className="text-sm text-muted-foreground">No files attached. Upload proof of transfer (image or PDF).</p>
        ) : (
          <ul className="flex flex-col gap-2">
            {attachmentsQuery.data.map((attachment) => (
              <li key={attachment.id} className="flex items-center justify-between gap-2 text-sm">
                <button type="button" className="flex min-w-0 items-center gap-2 text-left" onClick={() => handleDownload(attachment)}>
                  {attachment.mime_type?.startsWith('image/') ? (
                    <AttachmentThumbnail attachment={attachment} />
                  ) : (
                    <FileText className="size-10 shrink-0 rounded border bg-muted p-2 text-muted-foreground" />
                  )}
                  <span className="truncate">{attachment.original_filename}</span>
                </button>
                <div className="flex shrink-0 items-center">
                  <Button type="button" variant="ghost" size="sm" onClick={() => handleDownload(attachment)}>
                    <Download className="size-4" />
                  </Button>
                  {showUpload && (
                    <Button type="button" variant="ghost" size="sm" onClick={() => setDeletingId(attachment.id)}>
                      <Trash2 className="size-4" />
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardContent>

      <DeleteDialog
        open={!!deletingId}
        onOpenChange={(open) => !open && setDeletingId(null)}
        itemLabel="this attachment"
        onConfirm={() => {
          if (deletingId) deleteMutation.mutate(deletingId)
        }}
      />
    </Card>
  )
}
