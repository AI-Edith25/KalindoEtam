<?php

namespace App\Models;

use App\Enums\QtyCategory;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class IssueStockItem extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'issue_stock_id',
        'item_id',
        'item_code',
        'item_name',
        'uom',
        'qty_category',
        'qty',
        'unit_cost',
        'amount',
    ];

    protected $casts = [
        'qty_category' => QtyCategory::class,
        'qty' => 'decimal:4',
        'unit_cost' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function issueStock(): BelongsTo
    {
        return $this->belongsTo(IssueStock::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
