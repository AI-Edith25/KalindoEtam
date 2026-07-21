<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One credited line against a specific InvoiceItem. Not Documentable — a
 * line fact owned by its CreditNote, same category as InvoiceItem.
 * `restock` is intent only this sprint (no StockLedger movement is ever
 * posted for it) — see CreditNote's own docblock and
 * docs/CREDIT_NOTE_DESIGN.md §5's "Pending Inventory Return Module" note.
 */
class CreditNoteItem extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'credit_note_id', 'invoice_item_id', 'item_id',
        'item_code', 'item_name', 'uom', 'qty_credited', 'rate', 'amount', 'restock',
    ];

    protected $casts = [
        'qty_credited' => 'integer',
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
