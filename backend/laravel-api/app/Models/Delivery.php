<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
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
        'sales_person_id',
        'warehouse_id',
        'delivery_date',
        'due_date',
        'terms_of_payment_id',
        'remarks',
        'fleet',
        'driver',
        'attention',
        'tel',
        'fax',
        'lock_version',
    ];

    protected $casts = [
        'status' => DeliveryStatus::class,
        'delivery_date' => 'date',
        'due_date' => 'date',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function documentType(): string
    {
        return 'delivery';
    }

    /** Pending = created, goods not yet confirmed out. */
    protected function initialStatus(): \BackedEnum
    {
        return DeliveryStatus::PENDING;
    }

    /** Complete = goods confirmed out — stock moves here (see DeliveryService::complete()). */
    protected function submittedStatus(): \BackedEnum
    {
        return DeliveryStatus::COMPLETE;
    }

    /** Anchor only (earliest order_date, tie-broken by id) — kept for backward compatibility with existing readers. salesOrders() below is the authoritative full source history. */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** Every Sales Order this Delivery was created from, one or many — see DeliveryService::create(). */
    public function salesOrders(): BelongsToMany
    {
        return $this->belongsToMany(SalesOrder::class, 'delivery_sales_orders');
    }

    /** This Delivery's own override — null falls back to salesOrder->sales_person for display, see DeliveryResource. */
    public function salesPerson(): BelongsTo
    {
        return $this->belongsTo(SalesPerson::class);
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

    /** Cancelled = withdrawn before goods left the warehouse. Nothing was posted, so nothing to reverse. */
    protected function cancelledStatus(): \BackedEnum
    {
        return DeliveryStatus::CANCELLED;
    }

    /**
     * Pending, or Complete with no live Invoice (DeliveryService::cancel() reverses the stock for a
     * Complete one first). An invoiced Delivery is refused there, not here.
     */
    protected function cancellableStatuses(): array
    {
        return [$this->initialStatus(), $this->submittedStatus()];
    }
}
