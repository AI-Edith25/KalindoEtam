import { useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Download, Loader2, Upload } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { toastApiError } from '@/shared/services/errorHandler'
import { downloadImportTemplate, uploadImportBatch } from '../../api/importApi'
import type { UploadResult } from '../../types/import'

interface ImportStepUploadProps {
  module: string
  onUploaded: (result: UploadResult) => void
}

export function ImportStepUpload({ module, onUploaded }: ImportStepUploadProps) {
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)

  const uploadMutation = useMutation({
    mutationFn: (file: File) => uploadImportBatch(module, file),
    onSuccess: onUploaded,
    onError: (error) => toastApiError(error),
  })

  const templateMutation = useMutation({
    mutationFn: () => downloadImportTemplate(module),
    onError: (error) => toastApiError(error),
  })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-4">
        <div>
          <h2 className="font-medium">Step 1 — Upload File</h2>
          <p className="text-sm text-muted-foreground">CSV or Excel (.csv, .xlsx, .xls), max 20 MB.</p>
        </div>
        <Button type="button" variant="outline" onClick={() => templateMutation.mutate()} disabled={templateMutation.isPending}>
          {templateMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
          Download Template
        </Button>
      </div>

      <input
        ref={fileInputRef}
        type="file"
        accept=".csv,.xlsx,.xls"
        className="hidden"
        onChange={(event) => setSelectedFile(event.target.files?.[0] ?? null)}
      />

      <div className="flex items-center gap-3 rounded-md border border-dashed p-6">
        <Button type="button" variant="outline" onClick={() => fileInputRef.current?.click()}>
          <Upload className="size-4" />
          Choose File
        </Button>
        <span className="text-sm text-muted-foreground">{selectedFile ? selectedFile.name : 'No file selected.'}</span>
      </div>

      <div>
        <Button
          type="button"
          onClick={() => selectedFile && uploadMutation.mutate(selectedFile)}
          disabled={!selectedFile || uploadMutation.isPending}
        >
          {uploadMutation.isPending && <Loader2 className="size-4 animate-spin" />}
          Continue
        </Button>
      </div>
    </div>
  )
}
