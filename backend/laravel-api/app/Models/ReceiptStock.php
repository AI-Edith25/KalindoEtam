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
 * Records stock coming into the warehouse outside of a purchase (returns from
 * usage, stock found, supplier gifts). Mirrors OpeningStock's lifecycle and
 * FIFO-layer-creation-on-submit behavior exactly, at a user-entered cost
 * instead of a purchase rate — see ReceiptStockService.
 */
class ReceiptStock extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number',
        'status',
        'revision',
        'submitted_at',
        'cancelled_at',
        'warehouse_id',
        'receipt_date',
        'remarks',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'receipt_date' => 'date',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function documentType(): string
    {
        return 'receipt_stock';
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceiptStockItem::class);
    }
}
