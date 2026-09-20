<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOutstandingSnapshotLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'snapshot_id',
        'customer_code',
        'customer_name',
        'txn_date',
        'ref_no',
        'invoice_amount',
        'paid_amount',
        'unpaid_amount',
        'terms_days',
        'due_date',
        'overdue_amount',
        'overdue_days',
    ];

    protected $casts = [
        'txn_date' => 'date',
        'due_date' => 'date',
        'invoice_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'unpaid_amount' => 'decimal:2',
        'overdue_amount' => 'decimal:2',
        'terms_days' => 'integer',
        'overdue_days' => 'integer',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(CustomerOutstandingSnapshot::class, 'snapshot_id');
    }

    /**
     * Computed from the snapshot's own stored figures, never recalculated from today's date.
     * No "lunas" branch -- this file only ever contains unpaid invoices, a settled one is never
     * in it at all, so unpaid_amount is never legitimately <= 0 for a real row.
     */
    public function status(): string
    {
        return (float) $this->overdue_amount > 0 ? 'overdue' : 'outstanding';
    }
}
