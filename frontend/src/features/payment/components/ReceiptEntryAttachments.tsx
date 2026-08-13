import { useRef } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Download, Loader2, Upload } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { toastApiError } from '@/shared/services/errorHandler'
import type { DocumentAttachment } from '@/features/administration/types'
import {
  fetchReceiptEntryAttachmentObjectUrl,
  fetchReceiptEntryAttachments,
  uploadReceiptEntryAttachment,
} from '../api/receiptEntryAttachmentApi'

/** D1 (UAT review 2026-08-12) — bukti transfer. Shared between the Editor (upload) and Detail (read-only) pages. */
export function ReceiptEntryAttachments({ receiptEntryId, showUpload = false }: { receiptEntryId: string; showUpload?: boolean }) {
  const queryClient = useQueryClient()
  const fileInputRef = useRef<HTMLInputElement>(null)

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
                <span>{attachment.original_filename}</span>
                <Button type="button" variant="ghost" size="sm" onClick={() => handleDownload(attachment)}>
                  <Download className="size-4" />
                </Button>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
