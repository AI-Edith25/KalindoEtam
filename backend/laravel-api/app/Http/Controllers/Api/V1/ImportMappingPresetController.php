<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesImportModule;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportMappingPresetRequest;
use App\Models\ImportBatch;
use App\Models\ImportMappingPreset;
use App\Repositories\ImportMappingPresetRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ImportMappingPresetController extends Controller
{
    use ApiResponse, AuthorizesImportModule;

    public function __construct(protected ImportMappingPresetRepository $presets) {}

    public function index(string $module): JsonResponse
    {
        $this->authorizeModule($module);

        return $this->success($this->presets->forModule($module));
    }

    /** Copies the batch's already-validated mapping/clean_settings into a named, reusable preset. */
    public function store(StoreImportMappingPresetRequest $request, ImportBatch $batch): JsonResponse
    {
        $this->authorizeBatch($batch);

        $preset = $this->presets->create([
            'module' => $batch->module,
            'name' => $request->validated('name'),
            'header_row' => $batch->header_row,
            'data_start_row' => $batch->data_start_row,
            'mapping' => $batch->mapping,
            'clean_settings' => $batch->clean_settings,
            'field_defaults' => $batch->field_defaults,
            'created_by' => Auth::id(),
        ]);

        return $this->success($preset, 'Preset saved.', 201);
    }

    public function destroy(ImportMappingPreset $preset): JsonResponse
    {
        $this->authorizeModule($preset->module);

        $this->presets->delete($preset);

        return $this->success(null, 'Preset deleted.');
    }
}
