<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSalesSnapshotLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'snapshot_id',
        'txn_date',
        'document_number',
        'item_code',
        'item_description',
        'item_group',
        'customer_code',
        'customer_name',
        'qty',
        'base_qty',
        'amount_excl_tax',
        'tax',
        'amount_incl_tax',
    ];

    protected $casts = [
        'txn_date' => 'date:Y-m-d',
        'qty' => 'decimal:2',
        'base_qty' => 'decimal:2',
        'amount_excl_tax' => 'decimal:2',
        'tax' => 'decimal:2',
        'amount_incl_tax' => 'decimal:2',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ProductSalesSnapshot::class, 'snapshot_id');
    }
}
