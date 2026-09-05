import { useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Download, Loader2, Upload } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { PageHeader } from '@/components/shared/PageHeader'
import { SectionNav } from '@/components/shared/SectionNav'
import { getAutoImportRejection, toastApiError } from '@/shared/services/errorHandler'
import { autoImportBatch, downloadImportTemplate } from '../../api/importApi'
import { ImportStepCommit } from './ImportStepCommit'

interface AutoImportPageProps {
  module: string
  label: string
  listPath: string
  /** Route to the old 5-step wizard for this module, offered as a fallback on rejection — omit for a module that has none. */
  manualWizardPath?: string
}

/**
 * 1-step import — upload, auto-map, preview, commit, all server-side in one
 * request (see ImportBatchService::autoImport). No mapping/fk-resolution/
 * preview screens: a file whose required columns aren't confidently
 * recognized is rejected outright rather than guessed at.
 */
export function AutoImportPage({ module, label, listPath, manualWizardPath }: AutoImportPageProps) {
  const navigate = useNavigate()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [batchId, setBatchId] = useState<string | null>(null)
  const [rejection, setRejection] = useState<{ message: string; missingFields: string[] } | null>(null)

  const templateMutation = useMutation({
    mutationFn: () => downloadImportTemplate(module),
    onError: (error) => toastApiError(error),
  })

  const importMutation = useMutation({
    mutationFn: (file: File) => autoImportBatch(module, file),
    onSuccess: (batch) => {
      setRejection(null)
      setBatchId(batch.id)
    },
    onError: (error) => {
      const rejectionInfo = getAutoImportRejection(error)
      if (rejectionInfo) {
        setRejection(rejectionInfo)
      } else {
        toastApiError(error)
      }
    },
  })

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="master" />

      <PageHeader
        title={`Import ${label}`}
        description={`Upload a CSV or Excel file — it's mapped, validated, and imported automatically.`}
        actions={
          <Button type="button" variant="outline" onClick={() => navigate(listPath)}>
            {batchId ? 'Close' : 'Cancel'}
          </Button>
        }
      />

      <Card>
        <CardContent>
          {batchId ? (
            <ImportStepCommit batchId={batchId} module={module} onDone={() => navigate(listPath)} />
          ) : (
            <div className="flex flex-col gap-4">
              <div className="flex items-center justify-between gap-4">
                <p className="text-sm text-muted-foreground">CSV or Excel (.csv, .xlsx, .xls), max 20 MB.</p>
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
                onChange={(event) => {
                  setSelectedFile(event.target.files?.[0] ?? null)
                  setRejection(null)
                }}
              />

              <div className="flex items-center gap-3 rounded-md border border-dashed p-6">
                <Button type="button" variant="outline" onClick={() => fileInputRef.current?.click()}>
                  <Upload className="size-4" />
                  Choose File
                </Button>
                <span className="text-sm text-muted-foreground">{selectedFile ? selectedFile.name : 'No file selected.'}</span>
              </div>

              {rejection && (
                <Alert variant="destructive">
                  <AlertTitle>File not recognized</AlertTitle>
                  <AlertDescription>
                    <p>{rejection.message}</p>
                    {manualWizardPath && (
                      <Button type="button" variant="link" className="h-auto p-0" onClick={() => navigate(manualWizardPath)}>
                        Use the manual import wizard instead
                      </Button>
                    )}
                  </AlertDescription>
                </Alert>
              )}

              <div>
                <Button
                  type="button"
                  onClick={() => selectedFile && importMutation.mutate(selectedFile)}
                  disabled={!selectedFile || importMutation.isPending}
                >
                  {importMutation.isPending && <Loader2 className="size-4 animate-spin" />}
                  Import
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
