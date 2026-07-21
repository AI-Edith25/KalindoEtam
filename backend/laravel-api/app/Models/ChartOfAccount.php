<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChartOfAccount extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'account_type',
        'is_active',
    ];

    protected $casts = [
        'account_type' => AccountType::class,
        'is_active' => 'boolean',
    ];

    /**
     * Derived, not stored — normal balance is fully determined by
     * account_type (asset/expense increase on the debit side; liability,
     * equity, and revenue increase on the credit side).
     */
    public function isDebitNormal(): bool
    {
        return in_array($this->account_type, [AccountType::ASSET, AccountType::EXPENSE], true);
    }
}
