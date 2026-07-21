<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BusinessException;
use App\Models\Concerns\Documentable;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentEntry extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number',
        'status',
        'revision',
        'submitted_at',
        'cancelled_at',
        'supplier_id',
        'payment_date',
        'payment_method',
        'reference_number',
        'remarks',
        'total_amount',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'payment_method' => PaymentMethod::class,
        'payment_date' => 'date',
        'total_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public static function documentType(): string
    {
        return 'payment';
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentEntryItem::class);
    }

    /**
     * A submitted Payment Entry has already reduced one or more Accounts
     * Payable balances. Reversing that safely needs a dedicated void/undo
     * workflow, which does not exist yet — same rationale as
     * GoodsReceipt::cancel() (Sprint 4) and Delivery::cancel() (Sprint 5).
     */
    public function cancel(): static
    {
        throw new BusinessException('Payment Entry cannot be cancelled. Reversal is not yet implemented.');
    }
}
