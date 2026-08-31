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

    /** @return array{batch: ImportBatch, headers: string[], fields: array, suggested_mapping: array, cleaning_report: array, sample_rows: array} */
    public function upload(string $module, UploadedFile $file): array
    {
        $template = $this->registry->resolve($module);
        $path = $file->store('imports', self::DISK);
        $extension = strtolower($file->getClientOriginalExtension());

        [$headers, $rows] = $this->readFile($path, $extension);
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

        $batch = $this->importBatchRepository->create([
            'module' => $module,
            'status' => ImportBatchStatus::UPLOADED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => self::DISK,
            'file_path' => $path,
            'mapping' => $suggestedMapping,
            'total_rows' => count($rows),
            'created_by' => Auth::id(),
        ]);

        return [
            'batch' => $batch,
            'headers' => $headers,
            'fields' => array_map(fn (ImportFieldDefinition $f) => $f->toArray(), $fields),
            'suggested_mapping' => $suggestedMapping,
            'cleaning_report' => (new CleaningReport($cleaned['droppedEmpty'], $cleaned['droppedConstant']))->toArray(),
            'sample_rows' => array_slice($rows, 0, 5),
        ];
    }

    /** @return array{sample_rows: array} */
    public function updateMapping(ImportBatch $batch, array $mapping, array $cleanSettings): array
    {
        $batch->update([
            'mapping' => $mapping,
            'clean_settings' => $cleanSettings,
            'status' => ImportBatchStatus::MAPPED,
        ]);

        [, $rows] = $this->readFile($batch->file_path, $this->extensionOf($batch));
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
        $headerForField = array_flip(array_filter($mapping));

        [, $rows] = $this->readFile($batch->file_path, $this->extensionOf($batch));

        $candidates = [];
        foreach ($fieldsByName as $fieldName => $field) {
            if ($field->type !== 'fk' || ! isset($headerForField[$fieldName])) {
                continue;
            }

            $header = $headerForField[$fieldName];
            $values = array_map(fn ($row) => $row[$header] ?? null, $rows);

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
        ]);

        ProcessImportBatchJob::dispatch($batch->id);
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
        $headerForField = array_flip(array_filter($mapping));

        [, $rawRows] = $this->readFile($batch->file_path, $this->extensionOf($batch));

        $fkClassifications = [];
        foreach ($fieldsByName as $fieldName => $field) {
            if ($field->type !== 'fk' || ! isset($headerForField[$fieldName])) {
                continue;
            }

            $header = $headerForField[$fieldName];
            $values = array_map(fn ($row) => $row[$header] ?? null, $rawRows);
            $fkClassifications[$fieldName] = $this->fkResolver->classify(
                $field->fkTarget['model'],
                $field->fkTarget['displayColumn'],
                $values,
            );
        }

        $built = [];
        foreach ($rawRows as $rawRow) {
            $data = [];
            $messages = [];
            $status = 'valid';

            $pendingCreateFields = [];

            foreach ($fields as $field) {
                $header = $headerForField[$field->name] ?? null;
                $raw = $header !== null ? ($rawRow[$header] ?? null) : null;

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
                'skip' => [null, 'error', "{$field->label} \"{$value}\" skipped."],
                default => [null, 'error', "{$field->label} \"{$value}\" has an unrecognized resolution."],
            };
        }

        $classification = $fkClassifications[$field->name][$value] ?? null;

        if ($classification !== null && $classification['status'] === 'match') {
            return [$classification['id'], 'valid', null];
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
    private function readFile(string $path, string $extension): array
    {
        $absolutePath = Storage::disk(self::DISK)->path($path);
        $result = ImportFileReader::read($absolutePath, $extension);

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
