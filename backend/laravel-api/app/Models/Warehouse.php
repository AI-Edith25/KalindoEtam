<?php

namespace App\Models;

use App\Enums\WarehouseType;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'warehouse_type',
    ];

    protected $casts = [
        'warehouse_type' => WarehouseType::class,
    ];

    public function stockIns(): HasMany
    {
        return $this->hasMany(StockIn::class);
    }

    public function stockLedgers(): HasMany
    {
        return $this->hasMany(StockLedger::class);
    }
}
