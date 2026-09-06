<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\Documentable;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Records an item's starting balance + cost, separate from any purchase
 * document — see OpeningStockService for the FIFO layer it creates on
 * submit() and reverses on cancel(). Standard Draft/Submitted/Cancelled
 * lifecycle (Documentable's default) — unlike GoodsReceipt/Delivery/
 * StockAdjustment/StockTransfer, cancel() is NOT forbidden here.
 */
class OpeningStock extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number',
        'status',
        'revision',
        'submitted_at',
        'cancelled_at',
        'warehouse_id',
        'cutoff_date',
        'remarks',
        'import_batch_id',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'cutoff_date' => 'date',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function documentType(): string
    {
        return 'opening_stock';
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OpeningStockItem::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
