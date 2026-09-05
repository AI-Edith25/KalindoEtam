import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type {
  CommitMode,
  DecimalStyle,
  FkCandidates,
  FkResolutions,
  HeaderSettingsResult,
  ImportBatch,
  ImportFieldMeta,
  ImportMappingPreset,
  MappingResult,
  PreviewResult,
  UploadResult,
  WriteMode,
} from '../types/import'

function triggerBlobDownload(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}

export async function fetchImportFields(module: string): Promise<ImportFieldMeta[]> {
  const { data } = await apiClient.get<ApiResponse<ImportFieldMeta[]>>(`/import/${module}/fields`)
  return data.data
}

export async function downloadImportTemplate(module: string): Promise<void> {
  const { data } = await apiClient.get(`/import/${module}/template`, { responseType: 'blob' })
  triggerBlobDownload(data as Blob, `${module}-import-template.csv`)
}

export async function uploadImportBatch(module: string, file: File): Promise<UploadResult> {
  const formData = new FormData()
  formData.append('file', file)

  const { data } = await apiClient.post<ApiResponse<UploadResult>>(`/import/${module}/batches`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

/**
 * 1-step import: upload -> auto-map -> preview -> commit, no manual screens. On success
 * returns the queued batch immediately (poll it the same way ImportStepCommit does). On a
 * 422 rejection (a required column wasn't confidently recognized) this throws — the caller
 * reads `error.response.data.message` / `.data.missing_fields` (see getAutoImportRejection).
 */
export async function autoImportBatch(module: string, file: File): Promise<ImportBatch> {
  const formData = new FormData()
  formData.append('file', file)

  const { data } = await apiClient.post<ApiResponse<ImportBatch>>(`/import/${module}/auto`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function fetchImportBatch(batchId: string): Promise<ImportBatch> {
  const { data } = await apiClient.get<ApiResponse<ImportBatch>>(`/import/batches/${batchId}`)
  return data.data
}

export async function updateImportMapping(
  batchId: string,
  mapping: Record<string, string | null>,
  cleanSettings: Record<string, DecimalStyle>,
  fieldDefaults: Record<string, string> = {},
): Promise<MappingResult> {
  const { data } = await apiClient.patch<ApiResponse<MappingResult>>(`/import/batches/${batchId}/mapping`, {
    mapping,
    clean_settings: cleanSettings,
    field_defaults: fieldDefaults,
  })
  return data.data
}

export async function updateHeaderSettings(batchId: string, headerRow: number, dataStartRow: number): Promise<HeaderSettingsResult> {
  const { data } = await apiClient.patch<ApiResponse<HeaderSettingsResult>>(`/import/batches/${batchId}/header-settings`, {
    header_row: headerRow,
    data_start_row: dataStartRow,
  })
  return data.data
}

export async function fetchFkCandidates(batchId: string): Promise<FkCandidates> {
  const { data } = await apiClient.get<ApiResponse<FkCandidates>>(`/import/batches/${batchId}/fk-candidates`)
  return data.data
}

export async function updateFkResolutions(batchId: string, resolutions: FkResolutions): Promise<ImportBatch> {
  const { data } = await apiClient.patch<ApiResponse<ImportBatch>>(`/import/batches/${batchId}/fk-resolutions`, { resolutions })
  return data.data
}

export async function runImportPreview(batchId: string): Promise<PreviewResult> {
  const { data } = await apiClient.post<ApiResponse<PreviewResult>>(`/import/batches/${batchId}/preview`)
  return data.data
}

export async function commitImportBatch(batchId: string, writeMode: WriteMode, commitMode: CommitMode): Promise<ImportBatch> {
  const { data } = await apiClient.post<ApiResponse<ImportBatch>>(`/import/batches/${batchId}/commit`, {
    write_mode: writeMode,
    commit_mode: commitMode,
  })
  return data.data
}

export async function downloadFailedRows(batchId: string, module: string): Promise<void> {
  const { data } = await apiClient.get(`/import/batches/${batchId}/failed-rows`, { responseType: 'blob' })
  triggerBlobDownload(data as Blob, `${module}-import-failed-rows.csv`)
}

export async function fetchMappingPresets(module: string): Promise<ImportMappingPreset[]> {
  const { data } = await apiClient.get<ApiResponse<ImportMappingPreset[]>>(`/import/${module}/mapping-presets`)
  return data.data
}

export async function saveMappingPreset(batchId: string, name: string): Promise<ImportMappingPreset> {
  const { data } = await apiClient.post<ApiResponse<ImportMappingPreset>>(`/import/batches/${batchId}/mapping-presets`, { name })
  return data.data
}

export async function deleteMappingPreset(presetId: string): Promise<void> {
  await apiClient.delete(`/import/mapping-presets/${presetId}`)
}
