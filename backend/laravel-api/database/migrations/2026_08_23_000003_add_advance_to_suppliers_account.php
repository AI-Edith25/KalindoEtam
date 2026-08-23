<?php

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;

/**
 * The payable-side mirror of 1150 "Unapplied Customer Payments": money paid
 * out to a supplier that hasn't yet been matched to a specific bill. Unlike
 * 1150 (a liability — money received, not yet earned), this is an asset —
 * cash has left the business but hasn't yet been converted into a settled
 * payable, so it's still something the business is owed (goods/services or
 * a refund). Idempotent (firstOrCreate), same as ChartOfAccountsSeeder and
 * 2026_08_12_000001's permission migration — production is live and seeders
 * aren't re-run on deploy, so this has to exist as a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        ChartOfAccount::query()->firstOrCreate(
            ['code' => '1250'],
            ['name' => 'Advance to Suppliers', 'account_type' => AccountType::ASSET, 'is_active' => true, 'is_cash_bank' => false],
        );
    }

    public function down(): void
    {
        ChartOfAccount::query()->where('code', '1250')->delete();
    }
};
