import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { toastApiError } from '@/shared/services/errorHandler'
import { fetchFkCandidates, updateFkResolutions } from '../../api/importApi'
import type { FkResolutionAction, FkResolutions, ImportFieldMeta } from '../../types/import'

interface ImportStepFkResolutionProps {
  batchId: string
  fields: ImportFieldMeta[]
  onResolved: () => void
  onBack: () => void
}

export function ImportStepFkResolution({ batchId, fields, onResolved, onBack }: ImportStepFkResolutionProps) {
  const candidatesQuery = useQuery({
    queryKey: ['import-fk-candidates', batchId],
    queryFn: () => fetchFkCandidates(batchId),
  })

  const [resolutions, setResolutions] = useState<FkResolutions>({})

  const saveMutation = useMutation({
    mutationFn: () => updateFkResolutions(batchId, resolutions),
    onSuccess: onResolved,
    onError: (error) => toastApiError(error),
  })

  if (candidatesQuery.isLoading) {
    return <p className="text-sm text-muted-foreground">Checking relation values…</p>
  }

  const candidates = candidatesQuery.data ?? {}
  const fieldLabel = (name: string) => fields.find((f) => f.name === name)?.label ?? name

  const unresolvedEntries = Object.entries(candidates).flatMap(([fieldName, values]) =>
    Object.entries(values)
      .filter(([, candidate]) => candidate.status !== 'match')
      .map(([value, candidate]) => ({ fieldName, value, candidate })),
  )

  const setResolution = (fieldName: string, value: string, action: FkResolutionAction, targetId: string | null) => {
    setResolutions((prev) => ({
      ...prev,
      [fieldName]: { ...prev[fieldName], [value]: { action, target_id: targetId } },
    }))
  }

  const allResolved = unresolvedEntries.every((entry) => resolutions[entry.fieldName]?.[entry.value]?.action !== undefined)

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h2 className="font-medium">Step 3 — Resolve Relations</h2>
        <p className="text-sm text-muted-foreground">Values that don't already match an existing master record.</p>
      </div>

      {unresolvedEntries.length === 0 ? (
        <p className="text-sm text-muted-foreground">All relation values matched existing records. Nothing to resolve.</p>
      ) : (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Field</TableHead>
                <TableHead>Value in File</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Resolution</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {unresolvedEntries.map(({ fieldName, value, candidate }) => {
                const resolution = resolutions[fieldName]?.[value]

                return (
                  <TableRow key={`${fieldName}:${value}`}>
                    <TableCell>{fieldLabel(fieldName)}</TableCell>
                    <TableCell>{value}</TableCell>
                    <TableCell>
                      <Badge variant={candidate.status === 'ambiguous' ? 'secondary' : 'destructive'}>
                        {candidate.status === 'ambiguous' ? 'Possible match' : 'No match'}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      <Select
                        value={resolution ? `${resolution.action}:${resolution.target_id ?? ''}` : ''}
                        onValueChange={(v) => {
                          const [action, targetId] = v.split(':')
                          setResolution(fieldName, value, action as FkResolutionAction, targetId || null)
                        }}
                      >
                        <SelectTrigger className="w-64">
                          <SelectValue placeholder="Choose…" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="create:">Create new master &quot;{value}&quot;</SelectItem>
                          <SelectItem value="skip:">Skip rows with this value</SelectItem>
                          {candidate.suggestions.map((suggestion) => (
                            <SelectItem key={suggestion.id} value={`map:${suggestion.id}`}>
                              Map to &quot;{suggestion.value}&quot; ({suggestion.score}% match)
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
      )}

      <div className="flex items-center gap-2">
        <Button type="button" variant="outline" onClick={onBack}>
          Back
        </Button>
        <Button
          type="button"
          onClick={() => (unresolvedEntries.length === 0 ? onResolved() : saveMutation.mutate())}
          disabled={!allResolved || saveMutation.isPending}
        >
          {saveMutation.isPending && <Loader2 className="size-4 animate-spin" />}
          Continue
        </Button>
      </div>
    </div>
  )
}
