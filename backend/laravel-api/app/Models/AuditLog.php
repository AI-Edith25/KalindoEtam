<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * System-wide Audit Log — see docs/ADMINISTRATION_DESIGN.md §6. Deliberately
 * separate from DocumentTimeline (per-document activity feed, unchanged).
 * No HasAuditTrail here: an audit log entry is never itself updated or
 * deleted, so created_by/updated_by/deleted_by stamping doesn't apply —
 * `user_id` (who performed the action) is a plain foreign key instead.
 */
class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'module',
        'description',
        'ip_address',
        'properties',
        'created_at',
    ];

    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
