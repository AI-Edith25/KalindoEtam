<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** AP mirror of CustomerOutstandingSnapshot. */
class SupplierOutstandingSnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'source_filename',
        'company_name',
        'snapshot_as_of_date',
        'total_rows',
        'total_suppliers',
        'grand_total_unpaid',
        'grand_total_overdue',
        'imported_by',
        'import_batch_id',
    ];

    protected $casts = [
        'snapshot_as_of_date' => 'date:Y-m-d',
        'total_rows' => 'integer',
        'total_suppliers' => 'integer',
        'grand_total_unpaid' => 'decimal:2',
        'grand_total_overdue' => 'decimal:2',
    ];

    protected $hidden = ['importBatch'];

    protected $appends = ['has_failed_rows'];

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierOutstandingSnapshotLine::class, 'snapshot_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    /** See CustomerOutstandingSnapshot::hasFailedRows() -- same accessor, AP mirror. */
    protected function hasFailedRows(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->importBatch?->error_report_path !== null,
        );
    }
}
