<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerOutstandingSnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'source_filename',
        'company_name',
        'snapshot_as_of_date',
        'total_rows',
        'total_customers',
        'grand_total_unpaid',
        'grand_total_overdue',
        'imported_by',
    ];

    protected $casts = [
        'snapshot_as_of_date' => 'date:Y-m-d',
        'total_rows' => 'integer',
        'total_customers' => 'integer',
        'grand_total_unpaid' => 'decimal:2',
        'grand_total_overdue' => 'decimal:2',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerOutstandingSnapshotLine::class, 'snapshot_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
