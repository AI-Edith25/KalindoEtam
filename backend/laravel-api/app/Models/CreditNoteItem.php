<?php

namespace App\Models;

use App\Enums\QtyCategory;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One credited line against a specific InvoiceItem. Not Documentable — a
 * line fact owned by its CreditNote, same category as InvoiceItem.
 * `restock = true` now posts a real stock movement — see
 * CreditNoteService::submit() and FifoLayerService's write-path map.
 */
class CreditNoteItem extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'credit_note_id', 'invoice_item_id', 'item_id',
        'item_code', 'item_name', 'uom', 'qty_credited', 'qty_category', 'rate', 'amount', 'restock',
    ];

    protected $casts = [
        'qty_credited' => 'decimal:4',
        'qty_category' => QtyCategory::class,
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'restock' => 'boolean',
    ];

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
