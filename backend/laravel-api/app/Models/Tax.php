<?php

namespace App\Models;

use App\Enums\TaxType;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tax extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'type',
        'rate',
        'is_active',
    ];

    protected $casts = [
        'type' => TaxType::class,
        'rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /** Reverse of invoices.tax_id — used by TaxService::delete()'s referenced-by-documents guard. */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** Reverse of purchase_orders.tax_id — same guard, Purchase side. */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
