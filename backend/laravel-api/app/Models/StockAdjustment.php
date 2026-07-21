<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\Concerns\Documentable;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockAdjustment extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number',
        'status',
        'revision',
        'submitted_at',
        'cancelled_at',
        'warehouse_id',
        'adjustment_date',
        'remarks',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'adjustment_date' => 'date',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public static function documentType(): string
    {
        return 'stock_adjustment';
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }

    /**
     * A submitted Stock Adjustment has already written immutable Stock
     * Ledger entries. Reversing that safely needs a compensating adjustment
     * or a Return workflow, neither of which exists yet — see
     * Delivery::cancel() / GoodsReceipt::cancel() for the identical
     * precedent.
     */
    public function cancel(): static
    {
        throw new BusinessException('Stock Adjustment cannot be cancelled. Reversal requires a compensating adjustment (not yet implemented).');
    }
}
