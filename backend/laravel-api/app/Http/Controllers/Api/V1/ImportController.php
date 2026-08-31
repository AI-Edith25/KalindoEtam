<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesImportModule;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommitImportBatchRequest;
use App\Http\Requests\StoreImportBatchRequest;
use App\Http\Requests\UpdateImportFkResolutionsRequest;
use App\Http\Requests\UpdateImportHeaderSettingsRequest;
use App\Http\Requests\UpdateImportMappingRequest;
use App\Http\Resources\ImportBatchResource;
use App\Models\ImportBatch;
use App\Services\Import\Exceptions\ImportCommitBlockedException;
use App\Services\Import\ImportBatchService;
use App\Services\Import\ImportFieldDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    use ApiResponse, AuthorizesImportModule;

    public function __construct(protected ImportBatchService $importBatchService) {}

    public function fields(string $module): JsonResponse
    {
        $this->authorizeModule($module);

        $fields = $this->importBatchService->templateFor($module)->fields();

        return $this->success(array_map(fn (ImportFieldDefinition $f) => $f->toArray(), $fields));
    }

    /** Blank CSV: header row (field labels) + 1 example row. */
    public function template(string $module): StreamedResponse
    {
        $this->authorizeModule($module);

        $fields = $this->importBatchService->templateFor($module)->fields();

        return response()->streamDownload(function () use ($fields) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_map(fn (ImportFieldDefinition $f) => $f->label, $fields));
            fputcsv($handle, array_map(fn (ImportFieldDefinition $f) => $f->example, $fields));
            fclose($handle);
        }, "{$module}-import-template.csv");
    }

    public function store(StoreImportBatchRequest $request, string $module): JsonResponse
    {
        $this->authorizeModule($module);

        $result = $this->importBatchService->upload($module, $request->file('file'));

        return $this->success([
            'batch' => new ImportBatchResource($result['batch']),
            'headers' => $result['headers'],
            'fields' => $result['fields'],
            'suggested_mapping' => $result['suggested_mapping'],
            'cleaning_report' => $result['cleaning_report'],
            'sample_rows' => $result['sample_rows'],
            'header_row' => $result['header_row'],
            'data_start_row' => $result['data_start_row'],
            'raw_preview_rows' => $result['raw_preview_rows'],
        ], 'File uploaded.', 201);
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($batch);

        return $this->success(new ImportBatchResource($batch));
    }

    /** Manual override for the auto-detected header/data-start rows — re-suggests mapping against the new header. */
    public function updateHeaderSettings(UpdateImportHeaderSettingsRequest $request, ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($batch);

        $result = $this->importBatchService->updateHeaderSettings(
            $batch,
            $request->validated('header_row'),
            $request->validated('data_start_row'),
        );

        return $this->success([
            'batch' => new ImportBatchResource($batch->refresh()),
            'headers' => $result['headers'],
            'suggested_mapping' => $result['suggested_mapping'],
            'cleaning_report' => $result['cleaning_report'],
            'sample_rows' => $result['sample_rows'],
            'raw_preview_rows' => $result['raw_preview_rows'],
        ], 'Header settings updated.');
    }

    public function updateMapping(UpdateImportMappingRequest $request, ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($batch);

        $data = $request->validated();
        $result = $this->importBatchService->updateMapping($batch, $data['mapping'], $data['clean_settings'] ?? [], $data['field_defaults'] ?? []);

        return $this->success([
            'batch' => new ImportBatchResource($batch->refresh()),
            'sample_rows' => $result['sample_rows'],
        ], 'Mapping saved.');
    }

    public function fkCandidates(ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($batch);

        return $this->success($this->importBatchService->fkCandidates($batch));
    }

    public function updateFkResolutions(UpdateImportFkResolutionsRequest $request, ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($batch);

        $this->importBatchService->updateFkResolutions($batch, $request->validated('resolutions'));

        return $this->success(new ImportBatchResource($batch->refresh()), 'Resolutions saved.');
    }

    public function preview(ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($batch);

        $result = $this->importBatchService->preview($batch);

        return $this->success($result);
    }

    public function commit(CommitImportBatchRequest $request, ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($batch);

        try {
            $this->importBatchService->commit($batch, $request->validated('write_mode'), $request->validated('commit_mode'));
        } catch (ImportCommitBlockedException $e) {
            return $this->success(null, $e->getMessage(), 422);
        }

        return $this->success(new ImportBatchResource($batch->refresh()), 'Import queued.');
    }

    public function failedRows(ImportBatch $batch): StreamedResponse
    {
        $this->authorizeBatch($batch);

        abort_if($batch->error_report_path === null, 404);

        return Storage::disk($batch->disk)->download($batch->error_report_path, "{$batch->module}-import-failed-rows.csv");
    }
}
