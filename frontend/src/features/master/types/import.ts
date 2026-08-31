export type ImportFieldType = 'string' | 'number' | 'date' | 'fk'

export interface ImportFieldMeta {
  name: string
  label: string
  type: ImportFieldType
  required: boolean
  is_unique_key: boolean
  fk_target: { model: string; displayColumn: string } | null
  synonyms: string[]
}

export type ImportBatchStatus = 'uploaded' | 'mapped' | 'previewed' | 'queued' | 'processing' | 'completed' | 'failed'

export type DecimalStyle = 'dot_decimal' | 'dot_thousands'
export type WriteMode = 'insert_only' | 'update_only' | 'upsert'
export type CommitMode = 'skip_invalid' | 'all_or_nothing'
export type FkResolutionAction = 'map' | 'create' | 'skip'

export interface FkResolutions {
  [fieldName: string]: {
    [rawValue: string]: { action: FkResolutionAction; target_id: string | null }
  }
}

export interface ImportPreviewSummary {
  total: number
  valid: number
  warning: number
  error: number
}

export interface ImportBatch {
  id: string
  module: string
  status: ImportBatchStatus
  original_filename: string
  header_row: number
  data_start_row: number
  mapping: Record<string, string | null> | null
  clean_settings: Record<string, DecimalStyle> | null
  fk_resolutions: FkResolutions | null
  field_defaults: Record<string, string> | null
  commit_mode: CommitMode | null
  write_mode: WriteMode | null
  preview_summary: ImportPreviewSummary | null
  total_rows: number
  processed_rows: number
  success_rows: number
  failed_rows: number
  has_failed_rows: boolean
  failure_reason: string | null
  created_at: string
  updated_at: string
}

export interface CleaningSample {
  column: string
  before: unknown
  after: unknown
}

export interface CleaningReport {
  dropped_empty_columns: string[]
  dropped_constant_columns: string[]
  samples: CleaningSample[]
}

export interface UploadResult {
  batch: ImportBatch
  headers: string[]
  fields: ImportFieldMeta[]
  suggested_mapping: Record<string, string | null>
  cleaning_report: CleaningReport
  sample_rows: Record<string, unknown>[]
  header_row: number
  data_start_row: number
  raw_preview_rows: unknown[][]
}

/** Response of PATCH .../header-settings — same shape as UploadResult minus `fields` (unchanged). */
export interface HeaderSettingsResult {
  batch: ImportBatch
  headers: string[]
  suggested_mapping: Record<string, string | null>
  cleaning_report: CleaningReport
  sample_rows: Record<string, unknown>[]
  raw_preview_rows: unknown[][]
}

export interface MappingResult {
  batch: ImportBatch
  sample_rows: CleaningSample[]
}

export interface FkSuggestion {
  id: string
  value: string
  score: number
}

export interface FkCandidate {
  status: 'match' | 'ambiguous' | 'no_match'
  id: string | null
  suggestions: FkSuggestion[]
}

export type FkCandidates = Record<string, Record<string, FkCandidate>>

export interface PreviewRow {
  row_number: number
  status: 'valid' | 'warning' | 'error'
  messages: string[]
  data: Record<string, unknown>
}

export interface PreviewResult {
  rows: PreviewRow[]
  summary: ImportPreviewSummary
}

export interface ImportMappingPreset {
  id: string
  module: string
  name: string
  header_row: number
  data_start_row: number
  mapping: Record<string, string | null>
  clean_settings: Record<string, DecimalStyle> | null
  field_defaults: Record<string, string> | null
}
