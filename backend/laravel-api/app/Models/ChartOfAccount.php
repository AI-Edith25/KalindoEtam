<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\CashBankCategory;
use App\Models\Concerns\HasAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChartOfAccount extends Model
{
    use HasAuditTrail, HasUuids, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'account_type',
        'is_active',
        'is_cash_bank',
        'cash_bank_category',
        'parent_id',
    ];

    protected $casts = [
        'account_type' => AccountType::class,
        'is_active' => 'boolean',
        'is_cash_bank' => 'boolean',
        'cash_bank_category' => CashBankCategory::class,
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Two levels only — a child's own children() is never populated (see Store/UpdateChartOfAccountRequest). */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
