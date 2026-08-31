import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { toastApiError } from '@/shared/services/errorHandler'
import { deleteMappingPreset, fetchMappingPresets, saveMappingPreset, updateImportMapping } from '../../api/importApi'
import type { DecimalStyle, MappingResult, UploadResult } from '../../types/import'

const IGNORE = '__ignore__'

interface ImportStepMappingProps {
  upload: UploadResult
  onSaved: (result: MappingResult) => void
  onBack: () => void
}

export function ImportStepMapping({ upload, onSaved, onBack }: ImportStepMappingProps) {
  const [mapping, setMapping] = useState<Record<string, string | null>>(upload.suggested_mapping)
  const [cleanSettings, setCleanSettings] = useState<Record<string, DecimalStyle>>({})
  const [savedResult, setSavedResult] = useState<MappingResult | null>(null)
  const [saveDialogOpen, setSaveDialogOpen] = useState(false)
  const [presetName, setPresetName] = useState('')

  const queryClient = useQueryClient()
  const presetsQuery = useQuery({
    queryKey: ['import-mapping-presets', upload.batch.module],
    queryFn: () => fetchMappingPresets(upload.batch.module),
  })

  const mappingMutation = useMutation({
    mutationFn: () => updateImportMapping(upload.batch.id, mapping, cleanSettings),
    onSuccess: (result) => {
      setSavedResult(result)
      toast.success('Mapping saved.')
    },
    onError: (error) => toastApiError(error),
  })

  const savePresetMutation = useMutation({
    mutationFn: () => saveMappingPreset(upload.batch.id, presetName),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['import-mapping-presets', upload.batch.module] })
      toast.success('Preset saved.')
      setSaveDialogOpen(false)
      setPresetName('')
    },
    onError: (error) => toastApiError(error),
  })

  const deletePresetMutation = useMutation({
    mutationFn: (presetId: string) => deleteMappingPreset(presetId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['import-mapping-presets', upload.batch.module] })
      toast.success('Preset deleted.')
    },
    onError: (error) => toastApiError(error),
  })

  const mappedFieldNames = new Set(Object.values(mapping).filter((value): value is string => !!value))
  const missingRequired = upload.fields.filter((field) => field.required && !mappedFieldNames.has(field.name))

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h2 className="font-medium">Step 2 — Auto-clean & Mapping</h2>
        <p className="text-sm text-muted-foreground">Confirm which file column maps to which system field.</p>
      </div>

      {presetsQuery.data && presetsQuery.data.length > 0 && (
        <div className="flex flex-wrap items-center gap-2">
          <Label className="text-sm text-muted-foreground">Load preset:</Label>
          {presetsQuery.data.map((preset) => (
            <div key={preset.id} className="flex items-center gap-1 rounded-md border pl-2 pr-1">
              <button
                type="button"
                className="text-sm hover:underline"
                onClick={() => {
                  setMapping(preset.mapping)
                  setCleanSettings(preset.clean_settings ?? {})
                  setSavedResult(null)
                }}
              >
                {preset.name}
              </button>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-6"
                onClick={() => deletePresetMutation.mutate(preset.id)}
                disabled={deletePresetMutation.isPending}
              >
                <Trash2 className="size-3" />
              </Button>
            </div>
          ))}
        </div>
      )}

      {(upload.cleaning_report.dropped_empty_columns.length > 0 || upload.cleaning_report.dropped_constant_columns.length > 0) && (
        <Alert>
          <AlertTitle>Columns ignored automatically</AlertTitle>
          <AlertDescription>
            {upload.cleaning_report.dropped_empty_columns.length > 0 && (
              <p>Empty in every row: {upload.cleaning_report.dropped_empty_columns.join(', ')}</p>
            )}
            {upload.cleaning_report.dropped_constant_columns.length > 0 && (
              <p>Same value in every row: {upload.cleaning_report.dropped_constant_columns.join(', ')}</p>
            )}
          </AlertDescription>
        </Alert>
      )}

      <div className="overflow-x-auto rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>File Column</TableHead>
              <TableHead>Sample Value</TableHead>
              <TableHead>System Field</TableHead>
              <TableHead>Number Format</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {upload.headers.map((header) => {
              const fieldName = mapping[header] ?? null
              const field = upload.fields.find((f) => f.name === fieldName)

              return (
                <TableRow key={header}>
                  <TableCell>{header}</TableCell>
                  <TableCell className="text-muted-foreground">{String(upload.sample_rows[0]?.[header] ?? '—')}</TableCell>
                  <TableCell>
                    <Select
                      value={fieldName ?? IGNORE}
                      onValueChange={(value) => setMapping((m) => ({ ...m, [header]: value === IGNORE ? null : value }))}
                    >
                      <SelectTrigger className="w-56">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value={IGNORE}>Ignore this column</SelectItem>
                        {upload.fields.map((f) => (
                          <SelectItem key={f.name} value={f.name}>
                            {f.label}
                            {f.required ? ' *' : ''}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </TableCell>
                  <TableCell>
                    {field?.type === 'number' && (
                      <Select
                        value={cleanSettings[field.name] ?? 'dot_thousands'}
                        onValueChange={(value) => setCleanSettings((s) => ({ ...s, [field.name]: value as DecimalStyle }))}
                      >
                        <SelectTrigger className="w-64">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="dot_thousands">. = thousands (65.000 → 65000)</SelectItem>
                          <SelectItem value="dot_decimal">. = decimal (65.5 → 65.5)</SelectItem>
                        </SelectContent>
                      </Select>
                    )}
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </div>

      {missingRequired.length > 0 && (
        <p className="text-sm text-destructive">Map all required fields: {missingRequired.map((f) => f.label).join(', ')}</p>
      )}

      {savedResult && savedResult.sample_rows.length > 0 && (
        <div>
          <h3 className="mb-2 text-sm font-medium">Before → After</h3>
          <div className="overflow-x-auto rounded-md border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Column</TableHead>
                  <TableHead>Before</TableHead>
                  <TableHead>After</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {savedResult.sample_rows.map((sample, index) => (
                  <TableRow key={index}>
                    <TableCell>{sample.column}</TableCell>
                    <TableCell className="text-muted-foreground">{String(sample.before ?? '—')}</TableCell>
                    <TableCell>{String(sample.after ?? '—')}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </div>
      )}

      <div className="flex items-center gap-2">
        <Button type="button" variant="outline" onClick={onBack}>
          Back
        </Button>
        <Button type="button" onClick={() => mappingMutation.mutate()} disabled={missingRequired.length > 0 || mappingMutation.isPending}>
          {mappingMutation.isPending && <Loader2 className="size-4 animate-spin" />}
          Save Mapping
        </Button>
        {savedResult && (
          <>
            <Button type="button" variant="outline" onClick={() => setSaveDialogOpen(true)}>
              Save as Preset
            </Button>
            <Button type="button" onClick={() => onSaved(savedResult)}>
              Continue
            </Button>
          </>
        )}
      </div>

      <Dialog open={saveDialogOpen} onOpenChange={setSaveDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Save mapping as preset</DialogTitle>
          </DialogHeader>
          <div className="flex flex-col gap-2">
            <Label htmlFor="preset-name">Name</Label>
            <Input id="preset-name" value={presetName} onChange={(e) => setPresetName(e.target.value)} placeholder="e.g. Legacy Export" />
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setSaveDialogOpen(false)}>
              Cancel
            </Button>
            <Button type="button" onClick={() => savePresetMutation.mutate()} disabled={!presetName.trim() || savePresetMutation.isPending}>
              {savePresetMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Save
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
