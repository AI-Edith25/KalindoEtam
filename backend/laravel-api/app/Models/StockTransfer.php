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

class StockTransfer extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number',
        'status',
        'revision',
        'submitted_at',
        'cancelled_at',
        'source_warehouse_id',
        'destination_warehouse_id',
        'transfer_date',
        'remarks',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'transfer_date' => 'date',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function documentType(): string
    {
        return 'stock_transfer';
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    /**
     * A submitted Stock Transfer has already written immutable Stock Ledger
     * entries at both warehouses. Reversing that safely needs a compensating
     * transfer, which does not exist yet — see Delivery::cancel() /
     * StockAdjustment::cancel() for the identical precedent.
     */
    public function cancel(): static
    {
        throw new BusinessException('Stock Transfer cannot be cancelled. Reversal requires a compensating transfer (not yet implemented).');
    }
}
