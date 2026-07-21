<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Structure only — no approval workflow logic is implemented yet.
 */
class ApprovalFlow extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'approvable_type',
        'approvable_id',
        'approver_id',
        'status',
        'step',
        'remarks',
        'decided_at',
    ];

    protected $casts = [
        'status' => ApprovalStatus::class,
        'step' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
