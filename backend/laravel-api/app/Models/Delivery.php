<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\Concerns\Documentable;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'terms_of_payment_id',
        'remarks',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'delivery_date' => 'date',
        'due_date' => 'date',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function documentType(): string
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

    public function termsOfPayment(): BelongsTo
    {
        return $this->belongsTo(TermsOfPayment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    /**
     * "Has this Delivery been invoiced" is derived from this relation being
     * non-empty — enforced for real by the unique constraint on
     * invoice_deliveries.delivery_id (a Delivery can belong to at most one
     * Invoice, but that Invoice may combine several Deliveries), not by a
     * status flag on this model (Delivery's own status is the shared
     * DocumentStatus enum that Documentable's submit()/cancel() guard on).
     */
    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class, 'invoice_deliveries');
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
