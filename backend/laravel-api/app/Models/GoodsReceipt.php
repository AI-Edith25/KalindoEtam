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

    /**
     * Dr Purchase Expense, Cr Accounts Payable — for AccountingService::
     * postForDocument() to post (see GoodsReceiptService::submit()). Same
     * shape/purpose as Invoice::journalLines(). Amount matches
     * AccountsPayableService::createFromGoodsReceipt()'s own computation
     * exactly (same source: items.sum('amount')), so the AP row and this
     * journal never diverge.
     */
    public function journalLines(): array
    {
        $amount = (float) $this->items->sum('amount');

        return [
            ['account' => '5100', 'type' => 'debit', 'amount' => $amount], // Purchase Expense
            ['account' => '2000', 'type' => 'credit', 'amount' => $amount], // Accounts Payable
        ];
    }

    /**
     * A submitted Goods Receipt has already moved stock and created an
     * Accounts Payable record. Reversing that safely means a Return
     * workflow (compensating stock + AP entries), which does not exist
     * yet. Cancel is therefore forbidden outright rather than left as a
     * status flip that would silently desynchronize inventory.
     */
    public function cancel(): static
    {
        throw new BusinessException('Goods Receipt cannot be cancelled. Reversal is only available through the Return workflow (not yet implemented).');
    }
}
