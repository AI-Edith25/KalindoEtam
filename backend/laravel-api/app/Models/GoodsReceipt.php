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

class GoodsReceipt extends Model
{
    use Documentable, HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_number',
        'status',
        'revision',
        'submitted_at',
        'cancelled_at',
        'purchase_order_id',
        'supplier_id',
        'warehouse_id',
        'receipt_date',
        'due_date',
        'remarks',
    ];

    protected $casts = [
        'status' => DocumentStatus::class,
        'receipt_date' => 'date',
        'due_date' => 'date',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function documentType(): string
    {
        return 'goods_receipt';
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    /** Every Purchase Invoice this Goods Receipt has been billed on — used to guard against invoicing it twice. */
    public function purchaseInvoices(): BelongsToMany
    {
        return $this->belongsToMany(PurchaseInvoice::class, 'purchase_invoice_goods_receipts');
    }

    /**
     * A submitted Goods Receipt has already moved stock. Reversing that
     * safely (decrementing stock + the source Purchase Order's
     * received_qty) has no dedicated workflow yet — Purchase Return
     * corrects a posted Purchase Invoice, not a Goods Receipt directly, so
     * it cannot be used to unwind a GR that hasn't been invoiced. Cancel is
     * therefore forbidden outright rather than left as a status flip that
     * would silently desynchronize inventory.
     */
    public function cancel(): static
    {
        throw new BusinessException('Goods Receipt cannot be cancelled. Reversal is only available through the Return workflow (not yet implemented).');
    }
}
