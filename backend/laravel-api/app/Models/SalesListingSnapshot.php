<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesListingSnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'period_start',
        'period_end',
        'source_filename',
        'company_name',
        'total_rows',
        'total_documents',
        'grand_total_amount_excl_tax',
        'grand_total_amount_incl_tax',
        'imported_by',
    ];

    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'total_rows' => 'integer',
        'total_documents' => 'integer',
        'grand_total_amount_excl_tax' => 'decimal:2',
        'grand_total_amount_incl_tax' => 'decimal:2',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesListingSnapshotLine::class, 'snapshot_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
