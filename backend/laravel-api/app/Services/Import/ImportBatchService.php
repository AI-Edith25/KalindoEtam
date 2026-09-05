<?php

namespace App\Services\Import;

use App\Enums\ImportBatchStatus;
use App\Jobs\ProcessImportBatchJob;
use App\Models\ImportBatch;
use App\Repositories\ImportBatchRepository;
use App\Services\Import\Contracts\ImportTemplate;
use App\Services\Import\Exceptions\ImportCommitBlockedException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Orchestrates upload -> mapping -> fk-resolution -> preview -> commit-dispatch.
 * No per-row DB table: the source file lives on disk and every step re-reads
 * it fresh, applying the batch's stored mapping/clean_settings/fk_resolutions
 * — preview and the commit job share buildCleanedRows() so they can never
 * disagree about what a row looks like.
 */
class ImportBatchService
{
    private const DISK = 'local';

    public function __construct(
        protected ImportBatchRepository $importBatchRepository,
        protected ImportTemplateRegistry $registry,
        protected FkResolver $fkResolver,
    ) {}

    public function templateFor(string $module): ImportTemplate
    {
        return $this->registry->resolve($module);
    }

    /** @return array{batch: ImportBatch, headers: string[], fields: array, suggested_mapping: array, cleaning_report: array, sample_rows: array, header_row: int, data_start_row: int, raw_preview_rows: array} */
    public function upload(string $module, UploadedFile $file): array
    {
        $template = $this->registry->resolve($module);
        $path = $file->store('imports', self::DISK);
        $extension = strtolower($file->getClientOriginalExtension());

        $rawRows = ImportFileReader::readRaw(Storage::disk(self::DISK)->path($path), $extension);
        $headerSettings = HeaderDetector::detect($rawRows);

        $derived = $this->deriveHeaderState($template, $path, $extension, $headerSettings['header_row'], $headerSettings['data_start_row']);

        $batch = $this->importBatchRepository->create([
            'module' => $module,
            'status' => ImportBatchStatus::UPLOADED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => self::DISK,
            'file_path' => $path,
            'header_row' => $headerSettings['header_row'],
            'data_start_row' => $headerSettings['data_start_row'],
            'mapping' => $derived['suggested_mapping'],
            'clean_settings' => $derived['suggested_clean_settings'],
            'total_rows' => $derived['total_rows'],
            'created_by' => Auth::id(),
        ]);

        return [
            'batch' => $batch,
            'headers' => $derived['headers'],
            'fields' => array_map(fn (ImportFieldDefinition $f) => $f->toArray(), $template->fields()),
            'suggested_mapping' => $derived['suggested_mapping'],
            'cleaning_report' => $derived['cleaning_report'],
            'sample_rows' => $derived['sample_rows'],
            'header_row' => $headerSettings['header_row'],
            'data_start_row' => $headerSettings['data_start_row'],
            'raw_preview_rows' => array_slice($rawRows, 0, 15),
        ];
    }

    /**
     * Re-derives headers/suggested-mapping/cleaning-report/sample-rows for a
     * manual header_row/data_start_row override — same computation upload()
     * runs for the auto-detected defaults, kept in one place so the two can
     * never disagree about what "given these row settings" means.
     *
     * @return array{batch: ImportBatch, headers: string[], suggested_mapping: array, cleaning_report: array, sample_rows: array, header_row: int, data_start_row: int, raw_preview_rows: array}
     */
    public function updateHeaderSettings(ImportBatch $batch, int $headerRow, int $dataStartRow): array
    {
        $template = $this->registry->resolve($batch->module);
        $extension = $this->extensionOf($batch);
        $derived = $this->deriveHeaderState($template, $batch->file_path, $extension, $headerRow, $dataStartRow);

        $batch->update([
            'header_row' => $headerRow,
            'data_start_row' => $dataStartRow,
            'mapping' => $derived['suggested_mapping'],
            'clean_settings' => $derived['suggested_clean_settings'],
            'total_rows' => $derived['total_rows'],
        ]);

        $rawRows = ImportFileReader::readRaw(Storage::disk(self::DISK)->path($batch->file_path), $extension);

        return [
            'batch' => $batch,
            'headers' => $derived['headers'],
            'suggested_mapping' => $derived['suggested_mapping'],
            'cleaning_report' => $derived['cleaning_report'],
            'sample_rows' => $derived['sample_rows'],
            'header_row' => $headerRow,
            'data_start_row' => $dataStartRow,
            'raw_preview_rows' => array_slice($rawRows, 0, 15),
        ];
    }

    /** @return array{headers: string[], suggested_mapping: array, suggested_clean_settings: array, cleaning_report: array, sample_rows: array, total_rows: int} */
    private function deriveHeaderState(ImportTemplate $template, string $path, string $extension, int $headerRow, int $dataStartRow): array
    {
        [$headers, $rows] = $this->readFile($path, $extension, $headerRow, $dataStartRow);
        $cleaned = DataCleaner::dropEmptyAndConstantColumns($rows);

        $fields = $template->fields();
        $suggestedMapping = $this->suggestMapping($headers, $fields);

        // A column already flagged as junk must never be suggested for mapping, even if its
        // header text happens to fuzzy-match a field name (e.g. an empty "Catatan" column
        // matching "Name" by header-string similarity) — that would silently blank out
        // whichever field it's mapped to when the column contains no real data.
        foreach ([...$cleaned['droppedEmpty'], ...$cleaned['droppedConstant']] as $droppedHeader) {
            $suggestedMapping[$droppedHeader] = null;
        }

        // Seed each mapped numeric field's decimal style from the column's own data instead of
        // always defaulting to 'dot_thousands' — a genuinely-numeric Excel column never needs
        // this (normalizeNumber short-circuits it), but a text/CSV numeric column does.
        $suggestedCleanSettings = [];
        $headerForField = array_flip(array_filter($suggestedMapping));
        foreach ($fields as $field) {
            if ($field->type !== 'number' || ! isset($headerForField[$field->name])) {
                continue;
            }

            $header = $headerForField[$field->name];
            $columnValues = array_map(fn ($row) => $row[$header] ?? null, $rows);
            $suggestedCleanSettings[$field->name] = DataCleaner::detectDecimalStyle($columnValues);
        }

        return [
            'headers' => $headers,
            'suggested_mapping' => $suggestedMapping,
            'suggested_clean_settings' => $suggestedCleanSettings,
            'cleaning_report' => (new CleaningReport($cleaned['droppedEmpty'], $cleaned['droppedConstant']))->toArray(),
            'sample_rows' => array_slice($rows, 0, 5),
            'total_rows' => count($rows),
        ];
    }

    /** @return array{sample_rows: array} */
    public function updateMapping(ImportBatch $batch, array $mapping, array $cleanSettings, array $fieldDefaults = []): array
    {
        $batch->update([
            'mapping' => $mapping,
            'clean_settings' => $cleanSettings,
            'field_defaults' => $fieldDefaults,
            'status' => ImportBatchStatus::MAPPED,
        ]);

        [, $rows] = $this->readFile($batch->file_path, $this->extensionOf($batch), $batch->header_row, $batch->data_start_row);
        $template = $this->registry->resolve($batch->module);
        $fieldsByName = $this->fieldsByName($template);
        $headerForField = array_flip(array_filter($mapping));

        $samples = [];
        foreach (array_slice($rows, 0, 5) as $row) {
            foreach ($headerForField as $fieldName => $header) {
                $field = $fieldsByName[$fieldName] ?? null;
                if (! $field || $field->type === 'fk') {
                    continue;
                }

                $before = $row[$header] ?? null;
                $samples[] = [
                    'column' => $header,
                    'before' => $before,
                    'after' => $this->transformScalar($field, $before, $cleanSettings),
                ];
            }
        }

        return ['sample_rows' => $samples];
    }

    /** @return array<string, array<string, array{status: string, id: ?string, suggestions: array}>> keyed by field name */
    public function fkCandidates(ImportBatch $batch): array
    {
        $template = $this->registry->resolve($batch->module);
        $fieldsByName = $this->fieldsByName($template);
        $mapping = $batch->mapping ?? [];
        $fieldDefaults = $batch->field_defaults ?? [];
        $headerForField = array_flip(array_filter($mapping));

        [, $rows] = $this->readFile($batch->file_path, $this->extensionOf($batch), $batch->header_row, $batch->data_start_row);

        $candidates = [];
        foreach ($fieldsByName as $fieldName => $field) {
            if ($field->type !== 'fk' || (! isset($headerForField[$fieldName]) && ! isset($fieldDefaults[$fieldName]))) {
                continue;
            }

            $header = $headerForField[$fieldName] ?? null;
            $values = $header !== null
                ? array_map(fn ($row) => $row[$header] ?? null, $rows)
                : array_fill(0, count($rows), $fieldDefaults[$fieldName]);

            $candidates[$fieldName] = $this->fkResolver->classify(
                $field->fkTarget['model'],
                $field->fkTarget['displayColumn'],
                $values,
            );
        }

        return $candidates;
    }

    public function updateFkResolutions(ImportBatch $batch, array $resolutions): void
    {
        $batch->update(['fk_resolutions' => $resolutions]);
    }

    /** @return array{rows: array, summary: array} */
    public function preview(ImportBatch $batch): array
    {
        $rows = $this->buildCleanedRows($batch);
        $summary = $this->summarize($rows);

        $batch->update([
            'preview_summary' => $summary,
            'total_rows' => count($rows),
            'status' => ImportBatchStatus::PREVIEWED,
        ]);

        return [
            'rows' => array_map(fn (array $row, int $i) => [
                'row_number' => $i + 1,
                'status' => $row['status'],
                'messages' => $row['messages'],
                'data' => $row['data'],
            ], $rows, array_keys($rows)),
            'summary' => $summary,
        ];
    }

    public function commit(ImportBatch $batch, string $writeMode, string $commitMode): void
    {
        $summary = $batch->preview_summary;

        if ($summary === null) {
            throw new ImportCommitBlockedException('Run Preview before committing.');
        }

        if ($commitMode === 'all_or_nothing' && ($summary['error'] ?? 0) > 0) {
            throw new ImportCommitBlockedException('All-or-nothing commit refused: the last preview had failing rows.');
        }

        $batch->update([
            'write_mode' => $writeMode,
            'commit_mode' => $commitMode,
            'status' => ImportBatchStatus::QUEUED,
            'queued_at' => now(),
        ]);

        ProcessImportBatchJob::dispatch($batch->id);
    }

    /** Per-module policy the 1-step flow needs that isn't a property of any one field. */
    private const MODULE_FIELD_DEFAULTS = [
        // warehouse_type has no DB default and no source column in the legacy Area/Branch
        // export — every imported row becomes a Transit warehouse, matching the fallback
        // the user specified rather than guessing Main for any of them.
        'warehouses' => ['warehouse_type' => 'transit'],
        // items.standard_rate is NOT NULL with a DB default of 0.00 — but an explicit NULL
        // insert (what an unmapped field would otherwise produce) still violates NOT NULL,
        // the DB default only applies when a column is omitted from the insert entirely.
        // Since this module's mapping for standard_rate is always stripped below (never
        // populated from a price column here — see the class docblock), it needs an
        // explicit 0 default so the row can actually be written.
        'items' => ['standard_rate' => '0'],
    ];

    private const MODULE_WRITE_MODES = [
        // item-standard-rates only ever updates an existing Item's standard_rate — an
        // unmatched item_code must fail the row, never create a bare Item.
        'item-standard-rates' => 'update_only',
    ];

    /**
     * 1-step import: upload -> (reject if any required field isn't confidently
     * recognized) -> auto-map -> preview -> commit, with no manual screens in
     * between. Reuses upload()/updateMapping()/preview()/commit() as-is — this
     * is orchestration only, no new row-processing logic.
     *
     * @return array{ok: bool, batch: ?ImportBatch, message: ?string, missing_fields: string[]}
     */
    public function autoImport(string $module, UploadedFile $file): array
    {
        $template = $this->registry->resolve($module);
        $fields = $template->fields();

        $upload = $this->upload($module, $file);
        $batch = $upload['batch'];

        // The 1-step flow's mapping is built from EXACT matches only — never
        // suggestMapping()'s fuzzy 50%+ guesses. A real legacy export can carry 60-100+
        // unrelated columns whose header text coincidentally scores well against a field's
        // synonyms (e.g. "AnalysisCode1" against "sales_tax_id"'s synonyms) — the manual
        // wizard is safe leaving that as an editable suggestion because a human reviews it
        // before committing; this flow has no such review step, so a wrong fuzzy guess
        // would silently write bad data instead of just being an ignorable hint.
        $mapping = $this->exactMapping($upload['headers'], $fields);

        $missing = $this->unmatchedRequiredFields($mapping, $fields);

        if ($missing !== []) {
            return [
                'ok' => false,
                'batch' => $batch,
                'message' => 'Kolom wajib tidak dikenali di file ini: '.implode(', ', $missing).'. Gunakan Download Template, atau import manual lewat wizard.',
                'missing_fields' => $missing,
            ];
        }

        // A field with autoMapFrom always maps from its synthetic transformRow() key —
        // strip any real header exactMapping() might independently have matched to the
        // same field (e.g. a literal "UnitPrice" column can legitimately exact-match
        // standard_rate's own synonyms) so the two can never both claim it.
        foreach ($fields as $field) {
            if ($field->autoMapFrom === null) {
                continue;
            }

            foreach ($mapping as $header => $mappedField) {
                if ($mappedField === $field->name) {
                    $mapping[$header] = null;
                }
            }

            $mapping[$field->autoMapFrom] = $field->name;
        }

        // Items (step 5): standard_rate is deliberately never populated from this module,
        // even if a column like "Unit Price" auto-matched it — it comes from the separate
        // item-standard-rates module instead (see ItemImportTemplate's class docblock).
        if ($module === 'items') {
            foreach ($mapping as $header => $mappedField) {
                if ($mappedField === 'standard_rate') {
                    $mapping[$header] = null;
                }
            }
        }

        $this->updateMapping($batch, $mapping, [], self::MODULE_FIELD_DEFAULTS[$module] ?? []);
        $this->preview($batch);
        $this->commit($batch, self::MODULE_WRITE_MODES[$module] ?? 'upsert', 'skip_invalid');

        return ['ok' => true, 'batch' => $batch->refresh(), 'message' => null, 'missing_fields' => []];
    }

    /**
     * Which of the template's required fields ended up unmapped in an exactMapping()
     * result — used to decide whether the 1-step flow can proceed or must reject the
     * file outright. autoMapFrom fields are always considered satisfied (populated via
     * transformRow()'s synthetic key, never a real file header).
     *
     * @param  array<string, ?string>  $mapping  header => field name (or null)
     * @param  \App\Services\Import\ImportFieldDefinition[]  $fields
     * @return string[] labels of unmatched required fields
     */
    private function unmatchedRequiredFields(array $mapping, array $fields): array
    {
        $mappedFieldNames = array_filter($mapping);

        return array_values(array_map(
            fn ($field) => $field->label,
            array_filter($fields, fn ($field) => $field->required
                && $field->autoMapFrom === null
                && ! in_array($field->name, $mappedFieldNames, true)),
        ));
    }

    /**
     * A mapping built from EXACT (normalized name/label/synonym) matches only — every
     * header gets at most one field, every field gets at most one header (first header
     * in file-column order wins if more than one would otherwise exactly match, which
     * shouldn't normally happen with distinct synonym lists but is guarded regardless).
     *
     * @param  string[]  $headers
     * @param  \App\Services\Import\ImportFieldDefinition[]  $fields
     * @return array<string, ?string> header => field name (or null)
     */
    private function exactMapping(array $headers, array $fields): array
    {
        $mapping = [];
        $claimedFields = [];

        foreach ($headers as $header) {
            $normalizedHeader = $this->normalizeHeader($header);
            $matched = null;

            foreach ($fields as $field) {
                if (in_array($field->name, $claimedFields, true)) {
                    continue;
                }

                foreach ([$field->name, $field->label, ...$field->synonyms] as $candidate) {
                    if ($this->normalizeHeader($candidate) === $normalizedHeader) {
                        $matched = $field->name;
                        break 2;
                    }
                }
            }

            $mapping[$header] = $matched;

            if ($matched !== null) {
                $claimedFields[] = $matched;
            }
        }

        return $mapping;
    }

    /**
     * Creates any master records the user chose to auto-create for an
     * unmatched FK value, once, before the commit chunks run. firstOrCreate
     * keeps this idempotent if the job is ever retried.
     *
     * @return array<string, string> "{field}|{lowercased value}" => created id
     */
    public function createMissingFkMasters(ImportBatch $batch): array
    {
        $template = $this->registry->resolve($batch->module);
        $fkResolutions = $batch->fk_resolutions ?? [];
        $overrides = [];

        foreach ($this->fieldsByName($template) as $fieldName => $field) {
            if ($field->type !== 'fk') {
                continue;
            }

            foreach ($fkResolutions[$fieldName] ?? [] as $rawValue => $resolution) {
                if (($resolution['action'] ?? null) !== 'create') {
                    continue;
                }

                $modelClass = $field->fkTarget['model'];
                $displayColumn = $field->fkTarget['displayColumn'];
                $created = $modelClass::query()->firstOrCreate([$displayColumn => $rawValue]);

                $overrides[$fieldName.'|'.mb_strtolower($rawValue)] = $created->id;
            }
        }

        return $overrides;
    }

    /**
     * The shared row-building pipeline used by both preview() (dry-run,
     * $fkIdOverrides empty) and the commit job (real ids for anything
     * resolved via "create", built by createMissingFkMasters() first).
     *
     * @return array<int, array{data: array, raw: array, status: string, messages: string[]}>
     */
    public function buildCleanedRows(ImportBatch $batch, array $fkIdOverrides = []): array
    {
        $template = $this->registry->resolve($batch->module);
        $fields = $template->fields();
        $fieldsByName = $this->fieldsByName($template);
        $mapping = $batch->mapping ?? [];
        $cleanSettings = $batch->clean_settings ?? [];
        $fkResolutions = $batch->fk_resolutions ?? [];
        $fieldDefaults = $batch->field_defaults ?? [];
        $headerForField = array_flip(array_filter($mapping));

        [, $rawRows] = $this->readFile($batch->file_path, $this->extensionOf($batch), $batch->header_row, $batch->data_start_row);

        $fkClassifications = [];
        foreach ($fieldsByName as $fieldName => $field) {
            if ($field->type !== 'fk' || (! isset($headerForField[$fieldName]) && ! isset($fieldDefaults[$fieldName]))) {
                continue;
            }

            $header = $headerForField[$fieldName] ?? null;
            $values = $header !== null
                ? array_map(fn ($row) => $row[$header] ?? null, $rawRows)
                : array_fill(0, count($rawRows), $fieldDefaults[$fieldName]);
            $fkClassifications[$fieldName] = $this->fkResolver->classify(
                $field->fkTarget['model'],
                $field->fkTarget['displayColumn'],
                $values,
            );
        }

        $built = [];
        foreach ($rawRows as $rawRow) {
            $rawRow = $template->transformRow($rawRow);

            $data = [];
            $messages = [];
            $status = 'valid';

            $pendingCreateFields = [];

            foreach ($fields as $field) {
                $header = $headerForField[$field->name] ?? null;
                $raw = $header !== null ? ($rawRow[$header] ?? null) : ($fieldDefaults[$field->name] ?? null);

                if ($field->type === 'fk') {
                    [$id, $fieldStatus, $fieldMessage] = $this->resolveFk($field, $raw, $fkResolutions, $fkClassifications, $fkIdOverrides);
                    $data[$field->name] = $id;
                    $status = $this->worseStatus($status, $fieldStatus);
                    if ($fieldMessage !== null) {
                        $messages[] = $fieldMessage;
                    }

                    // A pending "auto-create" resolution already earned its warning above —
                    // id is deliberately null until the commit job creates the master, so
                    // don't let the required/exists rule below re-flag that as an error.
                    if ($fieldStatus === 'warning') {
                        $pendingCreateFields[] = $field->name;
                    }

                    continue;
                }

                $data[$field->name] = $this->transformScalar($field, $raw, $cleanSettings);
            }

            $rules = $template->validationRules($data, ['fkIdOverrides' => $fkIdOverrides]);
            $rules = array_diff_key($rules, array_flip($pendingCreateFields));
            $validator = Validator::make($data, $rules);

            if ($validator->fails()) {
                $status = 'error';
                array_push($messages, ...$validator->errors()->all());
            }

            $built[] = ['data' => $data, 'raw' => $rawRow, 'status' => $status, 'messages' => $messages];
        }

        return $this->flagDuplicateUniqueKeys($built, $template->uniqueKeyField());
    }

    private function resolveFk(
        ImportFieldDefinition $field,
        mixed $raw,
        array $fkResolutions,
        array $fkClassifications,
        array $fkIdOverrides,
    ): array {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return [null, $field->required ? 'error' : 'valid', $field->required ? "{$field->label} is required." : null];
        }

        $value = trim((string) $raw);
        $resolution = $fkResolutions[$field->name][$value] ?? null;

        if ($resolution !== null) {
            return match ($resolution['action'] ?? null) {
                'map' => [$resolution['target_id'] ?? null, 'valid', null],
                'create' => isset($fkIdOverrides[$field->name.'|'.mb_strtolower($value)])
                    ? [$fkIdOverrides[$field->name.'|'.mb_strtolower($value)], 'valid', null]
                    : [null, 'warning', "{$field->label} \"{$value}\" will be created."],
                'skip' => [null, 'error', $field->required
                    ? "{$field->label} is required — \"{$value}\" was set to Skip. Go back to Resolve Relations and choose Map or Create instead."
                    : "{$field->label} \"{$value}\" skipped."],
                default => [null, 'error', "{$field->label} \"{$value}\" has an unrecognized resolution."],
            };
        }

        $classification = $fkClassifications[$field->name][$value] ?? null;

        if ($classification !== null && $classification['status'] === 'match') {
            return [$classification['id'], 'valid', null];
        }

        // An optional FK with a genuinely unmatched value (not merely blank — that's the
        // early-return above) behaves like a blank cell: null it out and warn, don't fail
        // the whole row. Required FK fields keep the old hard-stop behavior unchanged.
        if (! $field->required) {
            return [null, 'warning', "{$field->label} \"{$value}\" not found — left blank."];
        }

        return [null, 'error', "{$field->label} \"{$value}\" is not resolved — go back to Mapping."];
    }

    private function transformScalar(ImportFieldDefinition $field, mixed $raw, array $cleanSettings): mixed
    {
        return match ($field->type) {
            'number' => DataCleaner::normalizeNumber($raw, $cleanSettings[$field->name] ?? 'dot_thousands'),
            'date' => DataCleaner::normalizeDate($raw),
            default => DataCleaner::normalizeText($raw),
        };
    }

    private function flagDuplicateUniqueKeys(array $rows, string $uniqueKeyField): array
    {
        $seen = [];

        foreach ($rows as $i => $row) {
            $key = $row['data'][$uniqueKeyField] ?? null;

            if ($key === null) {
                continue;
            }

            if (isset($seen[$key])) {
                $rows[$i]['status'] = 'error';
                $rows[$i]['messages'][] = "Duplicate {$uniqueKeyField} within this file.";
            } else {
                $seen[$key] = true;
            }
        }

        return $rows;
    }

    private function worseStatus(string $a, string $b): string
    {
        $rank = ['valid' => 0, 'warning' => 1, 'error' => 2];

        return $rank[$b] > $rank[$a] ? $b : $a;
    }

    private function summarize(array $rows): array
    {
        $summary = ['total' => count($rows), 'valid' => 0, 'warning' => 0, 'error' => 0];

        foreach ($rows as $row) {
            $summary[$row['status']]++;
        }

        return $summary;
    }

    /** @return array{0: string[], 1: array<int, array<string, mixed>>} */
    private function readFile(string $path, string $extension, int $headerRow = 1, int $dataStartRow = 2): array
    {
        $absolutePath = Storage::disk(self::DISK)->path($path);
        $result = ImportFileReader::read($absolutePath, $extension, $headerRow, $dataStartRow);

        return [$result['headers'], $result['rows']];
    }

    private function extensionOf(ImportBatch $batch): string
    {
        return pathinfo($batch->file_path, PATHINFO_EXTENSION);
    }

    /** @return array<string, ImportFieldDefinition> */
    private function fieldsByName(ImportTemplate $template): array
    {
        $byName = [];
        foreach ($template->fields() as $field) {
            $byName[$field->name] = $field;
        }

        return $byName;
    }

    private function suggestMapping(array $headers, array $fields): array
    {
        $mapping = [];

        foreach ($headers as $header) {
            $normalizedHeader = $this->normalizeHeader($header);
            $best = null;
            $bestScore = 0.0;

            foreach ($fields as $field) {
                foreach ([$field->name, $field->label, ...$field->synonyms] as $candidate) {
                    $normalizedCandidate = $this->normalizeHeader($candidate);

                    if ($normalizedCandidate === $normalizedHeader) {
                        $best = $field->name;
                        $bestScore = 100.0;
                        break 2;
                    }

                    similar_text($normalizedHeader, $normalizedCandidate, $percent);
                    if ($percent > $bestScore) {
                        $bestScore = $percent;
                        $best = $field->name;
                    }
                }
            }

            $mapping[$header] = $bestScore >= 50.0 ? $best : null;
        }

        return $mapping;
    }

    private function normalizeHeader(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)) ?? '');
    }
}
