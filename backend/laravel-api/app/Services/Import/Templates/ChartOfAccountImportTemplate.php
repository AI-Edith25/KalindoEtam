<?php

namespace App\Services\Import\Templates;

use App\Models\ChartOfAccount;
use App\Services\Import\Contracts\ImportTemplate;
use App\Services\Import\ImportFieldDefinition;
use Illuminate\Validation\Rule;

/**
 * The real xlscoalisting.xlsx export isn't a flat table: it's a tree, printed as
 * "1. Financial Category : B10 [Share Capital]" group-header rows, followed by
 * summary/parent accounts (e.g. "211" HUTANG BANK, "211.01" a sub-group) and only the
 * deepest, "Detail YN"=YES rows are real postable accounts — chart_of_accounts has no
 * parent/hierarchy column, so only those ~110 detail rows are ever imported; the ~68
 * summary rows and 19 category headers are structural noise, skipped via transformRow()'s
 * `_skip_row` (see ImportBatchService::buildCleanedRows()).
 *
 * account_type/is_cash_bank aren't real columns in the file at all — every account's type
 * is only ever implied by which of the 19 Financial Category groups it falls under. Since
 * transformRow() runs once per row through ONE template instance in file order (see
 * ImportBatchService::buildCleanedRows()), a category-header row updates $currentAccountType/
 * $currentIsCashBank as a side effect before skipping itself, and every detail row under it
 * inherits that state via the synthetic `_account_type`/`_is_cash_bank` keys (autoMapFrom,
 * same convention as SalesPersonImportTemplate's `_is_active`). CATEGORY_TYPE_MAP below
 * covers all 19 categories actually present in the real export — an unrecognized category
 * prefix (a legacy category this file never used) leaves $currentAccountType null, which
 * fails that row's required validation rather than guessing.
 *
 * A manually-authored file (filled from Download Template, no category headers at all)
 * can still set the type directly via a real "Account Type"/"Type" column — transformRow()
 * prefers that literal value over the category-tracked one when present, so the offline
 * fallback path isn't silently broken by the autoMapFrom override that real-export imports
 * rely on.
 */
final class ChartOfAccountImportTemplate implements ImportTemplate
{
    private ?string $currentAccountType = null;

    private bool $currentIsCashBank = false;

    /** Financial Category prefix (from "Financial Category : B10 [...]") -> AccountType value. */
    private const CATEGORY_TYPE_MAP = [
        'B10' => 'equity',    // Share Capital
        'B15' => 'equity',    // Retained Profits
        'B20' => 'liability', // Bank Borrowings
        'B35' => 'asset',     // Property, Plant & Equipment
        'B50' => 'asset',     // Inventory
        'B55' => 'asset',     // Trade and Other Receivables
        'B60' => 'asset',     // Bank
        'B62' => 'asset',     // Cash
        'B65' => 'asset',     // Other Current Assets
        'B70' => 'liability', // Trade and Other Payables
        'B72' => 'liability', // Current Liabilities
        'B80' => 'liability', // Other Current Liabilities
        'I10' => 'revenue',   // Income
        'I15' => 'expense',   // Cost of Sales
        'I20' => 'revenue',   // Other Income
        'I22' => 'expense',   // Administrative Expenses
        'I27' => 'expense',   // Operating Expenses
        'I29' => 'expense',   // Others Expenses
        'I30' => 'expense',   // Taxation
    ];

    /** The only two categories whose accounts are selectable as a Payment/Receipt cash_account_id. */
    private const CASH_BANK_CATEGORIES = ['B60', 'B62'];

    public function key(): string
    {
        return 'chart-of-accounts';
    }

    public function label(): string
    {
        return 'Chart of Accounts';
    }

    public function fields(): array
    {
        return [
            new ImportFieldDefinition(
                name: 'code',
                label: 'Account Code',
                type: 'string',
                required: true,
                isUniqueKey: true,
                synonyms: ['code', 'kode', 'account code', 'gl code'],
                example: '411.01.01',
            ),
            new ImportFieldDefinition(
                name: 'name',
                label: 'Account Description',
                type: 'string',
                required: true,
                synonyms: ['name', 'nama', 'description', 'account description'],
                example: 'Jasa Angkutan',
            ),
            new ImportFieldDefinition(
                name: 'account_type',
                label: 'Account Type',
                type: 'string',
                required: true,
                synonyms: ['account type', 'type', 'tipe akun'],
                example: 'revenue',
                autoMapFrom: '_account_type',
            ),
            new ImportFieldDefinition(
                name: 'is_cash_bank',
                label: 'Cash/Bank',
                type: 'string',
                synonyms: [],
                example: '0',
                autoMapFrom: '_is_cash_bank',
            ),
        ];
    }

    public function model(): string
    {
        return ChartOfAccount::class;
    }

    public function uniqueKeyField(): string
    {
        return 'code';
    }

    public function validationRules(array $row, array $context): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'is_cash_bank' => ['nullable', 'boolean'],
        ];
    }

    public function transformRow(array $row): array
    {
        $codeCell = trim((string) ($row['Account Code'] ?? ''));

        if (preg_match('/Financial Category\s*:\s*([A-Za-z]\d+)/i', $codeCell, $m) === 1) {
            $prefix = strtoupper($m[1]);
            $this->currentAccountType = self::CATEGORY_TYPE_MAP[$prefix] ?? null;
            $this->currentIsCashBank = in_array($prefix, self::CASH_BANK_CATEGORIES, true);
            $row['_skip_row'] = '1';

            return $row;
        }

        $detailFlag = trim((string) ($row['Detail YN'] ?? ''));

        if (strcasecmp($detailFlag, 'YES') !== 0) {
            $row['_skip_row'] = '1';

            return $row;
        }

        $explicitType = trim((string) ($row['Account Type'] ?? $row['Type'] ?? ''));
        $row['_account_type'] = $explicitType !== '' ? mb_strtolower($explicitType) : $this->currentAccountType;
        $row['_is_cash_bank'] = $this->currentIsCashBank ? '1' : '0';

        return $row;
    }
}
