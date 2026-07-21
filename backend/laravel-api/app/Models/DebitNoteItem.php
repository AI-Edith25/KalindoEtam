<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One additional charge line. Either item-linked (invoice_item_id set,
 * adjusting an existing InvoiceItem — Under-Billed Invoice/Price
 * Correction) or freestanding (invoice_item_id null, description-only —
 * Additional Service Charge/Freight Adjustment). Never both null/both set —
 * enforced by DebitNoteService, not here. See docs/DEBIT_NOTE_DESIGN.md §2.
 */
class DebitNoteItem extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'debit_note_id',
        'invoice_item_id',
        'item_id',
        'item_code',
        'item_name',
        'uom',
        'description',
        'qty_adjusted',
        'rate',
        'amount',
    ];

    protected $casts = [
        'qty_adjusted' => 'integer',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function debitNote(): BelongsTo
    {
        return $this->belongsTo(DebitNote::class);
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
