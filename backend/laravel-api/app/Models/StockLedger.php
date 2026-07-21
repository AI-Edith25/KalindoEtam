<?php

namespace App\Models;

use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockLedger extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'item_id',
        'warehouse_id',
        'transaction_type',
        'voucher_type',
        'voucher_id',
        'reference_no',
        'qty_change',
        'balance_qty',
        'posting_datetime',
        'remarks',
    ];

    protected $casts = [
        'transaction_type' => StockTransactionType::class,
        'voucher_type' => StockVoucherType::class,
        'qty_change' => 'integer',
        'balance_qty' => 'integer',
        'posting_datetime' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
