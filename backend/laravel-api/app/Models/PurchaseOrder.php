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

class PurchaseOrder extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number',
        'status',
        'revision',
        'submitted_at',
        'cancelled_at',
        'supplier_id',
        'order_date',
        'expected_delivery_date',
        'total_amount',
        'tax_id',
        'tax_amount',
        'grand_total',
        'remarks',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'total_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public static function documentType(): string
    {
        return 'purchase';
    }

    /** Operational commitment to a supplier before any Goods Receipt/Payable exists — see docs/APPROVAL_WORKFLOW_DESIGN.md §3. */
    public function requiresApproval(): bool
    {
        return true;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** See docs/TAX_ENGINE_DESIGN.md §5/§6 — same tax_id/tax_amount pattern as Invoice, reusing TaxService. */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }
}
