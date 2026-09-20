<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesListingSnapshotLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'snapshot_id',
        'txn_date',
        'document_number',
        'reference_so',
        'reference_do',
        'customer_code',
        'customer_name',
        'type_code',
        'amount_excl_tax',
        'disc_adjustment',
        'tax',
        'amount_incl_tax',
    ];

    protected $casts = [
        'txn_date' => 'date:Y-m-d',
        'amount_excl_tax' => 'decimal:2',
        'disc_adjustment' => 'decimal:2',
        'tax' => 'decimal:2',
        'amount_incl_tax' => 'decimal:2',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SalesListingSnapshot::class, 'snapshot_id');
    }
}
