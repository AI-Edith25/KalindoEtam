<?php

namespace App\Models;

use App\Enums\MiscellaneousChargeType;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MiscellaneousItem extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'misc_code',
        'description',
        'rate',
        'uom_id',
        'charge_type',
        'unit_cost',
        'sales_account_id',
        'purchase_account_id',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'charge_type' => MiscellaneousChargeType::class,
        'unit_cost' => 'decimal:2',
    ];

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'uom_id');
    }

    public function salesAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'sales_account_id');
    }

    public function purchaseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'purchase_account_id');
    }
}
