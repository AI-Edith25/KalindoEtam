import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { toastApiError } from '@/shared/services/errorHandler'
import {
  deleteMappingPreset,
  fetchMappingPresets,
  saveMappingPreset,
  updateHeaderSettings,
  updateImportMapping,
} from '../../api/importApi'
import type { CleaningReport, DecimalStyle, ImportMappingPreset, MappingResult, UploadResult } from '../../types/import'

const IGNORE = '__ignore__'

interface ImportStepMappingProps {
  upload: UploadResult
  onSaved: (result: MappingResult) => void
  onBack: () => void
}

interface HeaderState {
  headers: string[]
  suggestedMapping: Record<string, string | null>
  cleaningReport: CleaningReport
  sampleRows: Record<string, unknown>[]
  headerRow: number
  dataStartRow: number
  rawPreviewRows: unknown[][]
}

function headerStateFromUpload(upload: UploadResult): HeaderState {
  return {
    headers: upload.headers,
    suggestedMapping: upload.suggested_mapping,
    cleaningReport: upload.cleaning_report,
    sampleRows: upload.sample_rows,
    headerRow: upload.header_row,
    dataStartRow: upload.data_start_row,
    rawPreviewRows: upload.raw_preview_rows,
  }
}

export function ImportStepMapping({ upload, onSaved, onBack }: ImportStepMappingProps) {
  const [headerState, setHeaderState] = useState<HeaderState>(() => headerStateFromUpload(upload))
  const [headerRowInput, setHeaderRowInput] = useState(upload.header_row)
  const [dataStartRowInput, setDataStartRowInput] = useState(upload.data_start_row)
  const [mapping, setMapping] = useState<Record<string, string | null>>(upload.suggested_mapping)
  const [cleanSettings, setCleanSettings] = useState<Record<string, DecimalStyle>>(upload.batch.clean_settings ?? {})
  const [fieldDefaults, setFieldDefaults] = useState<Record<string, string>>({})
  const [savedResult, setSavedResult] = useState<MappingResult | null>(null)
  const [saveDialogOpen, setSaveDialogOpen] = useState(false)
  const [presetName, setPresetName] = useState('')

  const queryClient = useQueryClient()
  const presetsQuery = useQuery({
    queryKey: ['import-mapping-presets', upload.batch.module],
    queryFn: () => fetchMappingPresets(upload.batch.module),
  })

  const headerSettingsMutation = useMutation({
    mutationFn: (vars: { headerRow: number; dataStartRow: number }) => updateHeaderSettings(upload.batch.id, vars.headerRow, vars.dataStartRow),
    onSuccess: (result) => {
      setHeaderState({
        headers: result.headers,
        suggestedMapping: result.suggested_mapping,
        cleaningReport: result.cleaning_report,
        sampleRows: result.sample_rows,
        headerRow: result.batch.header_row,
        dataStartRow: result.batch.data_start_row,
        rawPreviewRows: result.raw_preview_rows,
      })
      setHeaderRowInput(result.batch.header_row)
      setDataStartRowInput(result.batch.data_start_row)
      setMapping(result.suggested_mapping)
      setCleanSettings(result.batch.clean_settings ?? {})
      setSavedResult(null)
    },
    onError: (error) => toastApiError(error),
  })

  const mappingMutation = useMutation({
    mutationFn: () => {
      const defaults = Object.fromEntries(Object.entries(fieldDefaults).filter(([, v]) => v.trim() !== ''))
      return updateImportMapping(upload.batch.id, mapping, cleanSettings, defaults)
    },
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

  function applyPreset(preset: ImportMappingPreset) {
    const applyRest = () => {
      setMapping(preset.mapping)
      setCleanSettings(preset.clean_settings ?? {})
      setFieldDefaults(preset.field_defaults ?? {})
      setSavedResult(null)
    }

    if (preset.header_row !== headerState.headerRow || preset.data_start_row !== headerState.dataStartRow) {
      setHeaderRowInput(preset.header_row)
      setDataStartRowInput(preset.data_start_row)
      headerSettingsMutation.mutate({ headerRow: preset.header_row, dataStartRow: preset.data_start_row }, { onSuccess: applyRest })
    } else {
      applyRest()
    }
  }

  const mappedFieldNames = new Set(Object.values(mapping).filter((value): value is string => !!value))
  const unmappedRequiredFields = upload.fields.filter((field) => field.required && !mappedFieldNames.has(field.name))
  const missingRequired = unmappedRequiredFields.filter((field) => !fieldDefaults[field.name]?.trim())

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
              <button type="button" className="text-sm hover:underline" onClick={() => applyPreset(preset)}>
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

      <div className="flex flex-col gap-3 rounded-md border p-4">
        <h3 className="text-sm font-medium">Header & Data Rows</h3>
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex flex-col gap-1">
            <Label htmlFor="header-row" className="text-xs text-muted-foreground">
              Header is on row
            </Label>
            <Input
              id="header-row"
              type="number"
              min={1}
              className="w-24"
              value={headerRowInput}
              onChange={(e) => setHeaderRowInput(Number(e.target.value) || 1)}
            />
          </div>
          <div className="flex flex-col gap-1">
            <Label htmlFor="data-start-row" className="text-xs text-muted-foreground">
              Data starts on row
            </Label>
            <Input
              id="data-start-row"
              type="number"
              min={2}
              className="w-24"
              value={dataStartRowInput}
              onChange={(e) => setDataStartRowInput(Number(e.target.value) || 2)}
            />
          </div>
          <Button
            type="button"
            variant="outline"
            onClick={() => headerSettingsMutation.mutate({ headerRow: headerRowInput, dataStartRow: dataStartRowInput })}
            disabled={headerSettingsMutation.isPending}
          >
            {headerSettingsMutation.isPending && <Loader2 className="size-4 animate-spin" />}
            Apply
          </Button>
        </div>

        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableBody>
              {headerState.rawPreviewRows.map((row, index) => {
                const rowNumber = index + 1
                const isHeader = rowNumber === headerState.headerRow
                const isSkipped = rowNumber > headerState.headerRow && rowNumber < headerState.dataStartRow

                return (
                  <TableRow key={rowNumber} className={isHeader ? 'bg-primary/10' : isSkipped ? 'bg-muted/50' : undefined}>
                    <TableCell className="text-muted-foreground">
                      {rowNumber}
                      {isHeader && (
                        <Badge variant="default" className="ml-2">
                          Header
                        </Badge>
                      )}
                      {isSkipped && (
                        <Badge variant="secondary" className="ml-2">
                          Skipped
                        </Badge>
                      )}
                    </TableCell>
                    {row.map((cell, cellIndex) => (
                      <TableCell key={cellIndex}>{String(cell ?? '—')}</TableCell>
                    ))}
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </div>
      </div>

      {(headerState.cleaningReport.dropped_empty_columns.length > 0 || headerState.cleaningReport.dropped_constant_columns.length > 0) && (
        <Alert>
          <AlertTitle>Columns ignored automatically</AlertTitle>
          <AlertDescription>
            {headerState.cleaningReport.dropped_empty_columns.length > 0 && (
              <p>Empty in every row: {headerState.cleaningReport.dropped_empty_columns.join(', ')}</p>
            )}
            {headerState.cleaningReport.dropped_constant_columns.length > 0 && (
              <p>Same value in every row: {headerState.cleaningReport.dropped_constant_columns.join(', ')}</p>
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
            {headerState.headers.map((header, index) => {
              const fieldName = mapping[header] ?? null
              const field = upload.fields.find((f) => f.name === fieldName)

              return (
                <TableRow key={`${header}-${index}`}>
                  <TableCell>{header || <span className="text-muted-foreground">(blank)</span>}</TableCell>
                  <TableCell className="text-muted-foreground">{String(headerState.sampleRows[0]?.[header] ?? '—')}</TableCell>
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

      {unmappedRequiredFields.length > 0 && (
        <div className="flex flex-col gap-2 rounded-md border p-4">
          <h3 className="text-sm font-medium">Default values for fields with no source column</h3>
          <p className="text-xs text-muted-foreground">These required fields aren't mapped to any column. Set one value applied to every row.</p>
          {unmappedRequiredFields.map((field) => (
            <div key={field.name} className="flex items-center gap-2">
              <Label className="w-40 shrink-0 text-sm">{field.label} *</Label>
              <Input
                value={fieldDefaults[field.name] ?? ''}
                onChange={(e) => setFieldDefaults((d) => ({ ...d, [field.name]: e.target.value }))}
                placeholder="Value applied to every row"
                className="max-w-xs"
              />
            </div>
          ))}
        </div>
      )}

      {missingRequired.length > 0 && (
        <p className="text-sm text-destructive">Map or set a default for all required fields: {missingRequired.map((f) => f.label).join(', ')}</p>
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
