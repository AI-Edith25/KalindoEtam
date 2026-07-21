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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Delivery extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number',
        'status',
        'revision',
        'submitted_at',
        'cancelled_at',
        'sales_order_id',
        'customer_id',
        'warehouse_id',
        'delivery_date',
        'due_date',
        'remarks',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'delivery_date' => 'date',
        'due_date' => 'date',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public static function documentType(): string
    {
        return 'delivery';
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    /**
     * Derived "has this Delivery been invoiced" check — enforced for real
     * by the unique constraint on invoices.delivery_id, not by a status
     * flag on this model (Delivery's own status is the shared
     * DocumentStatus enum that Documentable's submit()/cancel() guard on).
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * A submitted Delivery has already moved stock out and created an
     * Accounts Receivable record. Reversing that safely needs a Return
     * workflow (compensating stock-in + receivable adjustment), which
     * does not exist yet. Cancel is forbidden outright — see
     * GoodsReceipt::cancel() for the identical Sprint 4 precedent.
     */
    public function cancel(): static
    {
        throw new BusinessException('Delivery cannot be cancelled. Reversal is only available through the Return workflow (not yet implemented).');
    }
}
