<?php

namespace App\Services\Import\Templates;

use App\Models\ChartOfAccount;
use App\Models\MiscellaneousItem;
use App\Models\UnitOfMeasurement;
use App\Services\Import\Contracts\ImportTemplate;
use App\Services\Import\ImportFieldDefinition;
use Illuminate\Validation\Rule;

/**
 * `charge_type` is a 4-value enum (addition/deduction/addition_percent/deduction_percent)
 * cast on the model — the legacy file's Plus_MinusYN column only documents a 0/1 meaning
 * (0=Addition, 1=Deduction, confirmed against the file's own DISCOUNT row), but the real
 * data also contains 2 and 3 (all on PPH/tax rows, which are % based in Indonesian
 * accounting practice) with no source documentation for what those mean. transformRow()
 * maps 0/1/2/3 -> addition/deduction/addition_percent/deduction_percent as the best
 * available guess (confirmed with the user) — every row's charge_type is therefore always
 * derived from this assumption, not just the 2/3 rows, so double-check the imported
 * Addition/Deduction/% split against the legacy system after import rather than trusting
 * it blindly.
 *
 * sales_account_id/purchase_account_id are declared required here even though the ticket
 * asked for "leave null, don't fail the row" on an unresolved GLCode/PurchaseAccount — the
 * miscellaneous_items table has NOT NULL constraints on both (no ->nullable(), unlike
 * uom_id which genuinely is nullable), so a null write would violate the DB schema. An
 * unmatched account code fails that one row instead — confirmed the only schema-compatible
 * option, flagged here rather than silently diverging from the ticket's stated behavior.
 */
final class MiscellaneousItemImportTemplate implements ImportTemplate
{
    public function key(): string
    {
        return 'miscellaneous';
    }

    public function label(): string
    {
        return 'Miscellaneous';
    }

    public function fields(): array
    {
        return [
            new ImportFieldDefinition(
                name: 'misc_code',
                label: 'Misc Code',
                type: 'string',
                required: true,
                isUniqueKey: true,
                synonyms: ['code', 'kode', 'occode', 'misc code'],
                example: 'MISC-001',
            ),
            new ImportFieldDefinition(
                name: 'description',
                label: 'Description',
                type: 'string',
                required: true,
                synonyms: ['description', 'deskripsi'],
                example: 'Sample Charge',
            ),
            new ImportFieldDefinition(
                name: 'rate',
                label: 'Rate',
                type: 'number',
                synonyms: ['rate', 'r ate'],
                example: '10000',
            ),
            new ImportFieldDefinition(
                name: 'unit_cost',
                label: 'Unit Cost',
                type: 'number',
                synonyms: ['unit cost', 'unitcost'],
                example: '5000',
            ),
            new ImportFieldDefinition(
                name: 'uom_id',
                label: 'UOM',
                type: 'fk',
                fkTarget: ['model' => UnitOfMeasurement::class, 'displayColumn' => 'name'],
                synonyms: ['uom', 'unit', 'satuan'],
                example: 'Pcs',
            ),
            new ImportFieldDefinition(
                name: 'sales_account_id',
                label: 'Sales Account',
                type: 'fk',
                required: true,
                fkTarget: ['model' => ChartOfAccount::class, 'displayColumn' => 'code'],
                synonyms: ['glcode', 'gl code', 'sales account'],
                example: '411.01.01',
            ),
            new ImportFieldDefinition(
                name: 'purchase_account_id',
                label: 'Purchase Account',
                type: 'fk',
                required: true,
                fkTarget: ['model' => ChartOfAccount::class, 'displayColumn' => 'code'],
                synonyms: ['purchaseaccount', 'purchase account'],
                example: '411.01.01',
            ),
            new ImportFieldDefinition(
                name: 'charge_type',
                label: 'Charge Type',
                type: 'string',
                synonyms: ['plus_minusyn', 'plus minusyn', 'charge type'],
                example: 'addition',
                autoMapFrom: '_charge_type',
            ),
        ];
    }

    public function model(): string
    {
        return MiscellaneousItem::class;
    }

    public function uniqueKeyField(): string
    {
        return 'misc_code';
    }

    public function validationRules(array $row, array $context): array
    {
        return [
            'misc_code' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'uom_id' => ['nullable', 'uuid', Rule::exists('uoms', 'id')],
            'sales_account_id' => ['required', 'uuid', Rule::exists('chart_of_accounts', 'id')],
            'purchase_account_id' => ['required', 'uuid', Rule::exists('chart_of_accounts', 'id')],
            'charge_type' => ['required', Rule::in(['addition', 'deduction', 'addition_percent', 'deduction_percent'])],
        ];
    }

    public function transformRow(array $row): array
    {
        $plusMinus = trim((string) ($row['Plus_MinusYN'] ?? '0'));

        $row['_charge_type'] = match ($plusMinus) {
            '1' => 'deduction',
            '2' => 'addition_percent',
            '3' => 'deduction_percent',
            default => 'addition',
        };

        return $row;
    }
}
