<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvoiceChangeRequest extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'invoice_id',
        'status',
        'requested_by_id',
        'request_reason',
        'decided_by_id',
        'decision_remarks',
        'decided_at',
        'consumed_at',
    ];

    protected $casts = [
        'status' => ApprovalStatus::class,
        'decided_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }
}
