<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module' => $this->module,
            'status' => $this->status,
            'original_filename' => $this->original_filename,
            'mapping' => $this->mapping,
            'clean_settings' => $this->clean_settings,
            'fk_resolutions' => $this->fk_resolutions,
            'commit_mode' => $this->commit_mode,
            'write_mode' => $this->write_mode,
            'preview_summary' => $this->preview_summary,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'success_rows' => $this->success_rows,
            'failed_rows' => $this->failed_rows,
            'has_failed_rows' => $this->error_report_path !== null,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
