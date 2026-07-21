<?php

namespace App\Models;

use App\Enums\AccountsReceivableStatus;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Not Documentable — system-generated receivable record with its own
 * payment-status lifecycle, not a user-drafted/submitted document.
 * Mirrors AccountsPayable (Sprint 4) exactly.
 */
class AccountsReceivable extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'invoice_id',
        'sales_order_id',
        'delivery_id',
        'reference_number',
        'amount',
        'paid_amount',
        'credited_amount',
        'debited_amount',
        'due_date',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'credited_amount' => 'decimal:2',
        'debited_amount' => 'decimal:2',
        'due_date' => 'date',
        'status' => AccountsReceivableStatus::class,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function receiptEntryItems(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
