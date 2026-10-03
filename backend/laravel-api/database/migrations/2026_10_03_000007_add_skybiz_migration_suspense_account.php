<?php

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;

/**
 * Dedicated cash/bank account for Official Receipts created from the Skybiz
 * Customer Ledger reconciliation import's GJ (journal/adjustment) rows —
 * those rows settle an invoice without any real cash movement, but
 * ReceiptEntry always requires a real is_cash_bank account (see
 * StoreReceiptEntryRequest). Routing them here instead of a real bank
 * account keeps them out of real Bank Reconciliation (which always filters
 * by an explicitly-selected bank_account_id, never sweeps every
 * is_cash_bank account) and, by deliberately never adding a
 * ReportAccountMapping row for it, off the Balance Sheet entirely — visible
 * only via Trial Balance/General Ledger/Journal List, where it can't be
 * mistaken for real cash. See SkybizLedgerReconciliationImportService.
 */
return new class extends Migration
{
    public function up(): void
    {
        ChartOfAccount::query()->firstOrCreate(
            ['code' => '1199'],
            [
                'name' => 'PENYESUAIAN PIUTANG MIGRASI SKYBIZ',
                'account_type' => AccountType::ASSET,
                'is_active' => true,
                'is_cash_bank' => true,
                'cash_bank_category' => null,
            ],
        );
    }

    public function down(): void
    {
        // Never destroyed — may already have Official Receipt postings against it.
    }
};
