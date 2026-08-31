import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { PageHeader } from '@/components/shared/PageHeader'
import { SectionNav } from '@/components/shared/SectionNav'
import { ImportStepCommit } from './ImportStepCommit'
import { ImportStepFkResolution } from './ImportStepFkResolution'
import { ImportStepMapping } from './ImportStepMapping'
import { ImportStepPreview } from './ImportStepPreview'
import { ImportStepUpload } from './ImportStepUpload'
import { ImportStepper } from './ImportStepper'
import type { UploadResult } from '../../types/import'

interface ImportWizardPageProps {
  module: string
  label: string
  listPath: string
}

/** 5-step Import Wizard shell, reused across every master module by passing a different module/label/listPath. */
export function ImportWizardPage({ module, label, listPath }: ImportWizardPageProps) {
  const navigate = useNavigate()
  const [step, setStep] = useState(0)
  const [upload, setUpload] = useState<UploadResult | null>(null)

  const batchId = upload?.batch.id ?? null
  const hasFkFields = upload?.fields.some((f) => f.type === 'fk') ?? false

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="master" />

      <PageHeader
        title={`Import ${label}`}
        description={`Upload a CSV or Excel file to bulk import ${label.toLowerCase()}.`}
        actions={
          <Button type="button" variant="outline" onClick={() => navigate(listPath)}>
            Cancel
          </Button>
        }
      />

      <ImportStepper current={step} />

      <Card>
        <CardContent>
          {step === 0 && (
            <ImportStepUpload
              module={module}
              onUploaded={(result) => {
                setUpload(result)
                setStep(1)
              }}
            />
          )}

          {step === 1 && upload && (
            <ImportStepMapping upload={upload} onSaved={() => setStep(hasFkFields ? 2 : 3)} onBack={() => setStep(0)} />
          )}

          {step === 2 && batchId && hasFkFields && (
            <ImportStepFkResolution
              batchId={batchId}
              fields={upload!.fields}
              onResolved={() => setStep(3)}
              onBack={() => setStep(1)}
            />
          )}

          {step === 3 && batchId && (
            <ImportStepPreview batchId={batchId} onCommitted={() => setStep(4)} onBack={() => setStep(hasFkFields ? 2 : 1)} />
          )}

          {step === 4 && batchId && <ImportStepCommit batchId={batchId} module={module} onDone={() => navigate(listPath)} />}
        </CardContent>
      </Card>
    </div>
  )
}
