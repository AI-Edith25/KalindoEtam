<?php

namespace App\Models;

use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fleet extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'plat_number',
        'default_driver_name',
        'location_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function commissionRates(): HasMany
    {
        return $this->hasMany(DriverCommissionRate::class);
    }

    /** The Warehouse master ("Location" in this app's UI) — gates which fleets a Delivery at that location may pick. */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
