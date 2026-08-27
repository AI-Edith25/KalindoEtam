<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Singleton row — see PurchaseSettingRepository::current(). */
class PurchaseSetting extends Model
{
    use HasUuids;

    protected $fillable = [
        'weight_over_receipt_tolerance_percent',
        'updated_by',
    ];

    protected $casts = [
        'weight_over_receipt_tolerance_percent' => 'decimal:2',
    ];
}
