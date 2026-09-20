<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** AP mirror of CustomerOutstandingSnapshotLine. */
class SupplierOutstandingSnapshotLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'snapshot_id',
        'supplier_code',
        'supplier_name',
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
        return $this->belongsTo(SupplierOutstandingSnapshot::class, 'snapshot_id');
    }

    /** No 'lunas' branch -- this file only ever contains unpaid invoices. */
    public function status(): string
    {
        return (float) $this->overdue_amount > 0 ? 'overdue' : 'outstanding';
    }
}
