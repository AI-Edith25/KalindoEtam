<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An import run's operational state — not master data, so no HasAuditTrail/
 * SoftDeletes; one AuditLogService line per completed batch covers auditability.
 * No per-row child table: preview results live in preview_summary (counts
 * only), failed-row detail lives in a CSV at error_report_path.
 */
class ImportBatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'module',
        'status',
        'original_filename',
        'disk',
        'file_path',
        'header_row',
        'data_start_row',
        'mapping',
        'clean_settings',
        'fk_resolutions',
        'field_defaults',
        'commit_mode',
        'write_mode',
        'preview_summary',
        'total_rows',
        'processed_rows',
        'success_rows',
        'failed_rows',
        'error_report_path',
        'failure_reason',
        'queued_at',
        'started_at',
        'created_by',
    ];

    protected $casts = [
        'status' => ImportBatchStatus::class,
        'header_row' => 'integer',
        'data_start_row' => 'integer',
        'mapping' => 'array',
        'clean_settings' => 'array',
        'fk_resolutions' => 'array',
        'field_defaults' => 'array',
        'preview_summary' => 'array',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'success_rows' => 'integer',
        'failed_rows' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
