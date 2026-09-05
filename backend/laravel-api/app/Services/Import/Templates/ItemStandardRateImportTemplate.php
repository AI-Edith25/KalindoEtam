<?php

namespace App\Services\Import\Templates;

use App\Models\Item;
use App\Services\Import\Contracts\ImportTemplate;
use App\Services\Import\ImportFieldDefinition;

/**
 * A separate, narrower import from the main Items module (ItemImportTemplate)
 * — this one only ever updates `standard_rate` on an EXISTING item matched by
 * item_code (the 1-step orchestration forces write_mode=update_only for this
 * module, so an unrecognized ItemCode fails the row rather than creating a
 * bare Item). Lives on the Item Prices page, not the Items page.
 */
final class ItemStandardRateImportTemplate implements ImportTemplate
{
    public function key(): string
    {
        return 'item-standard-rates';
    }

    public function label(): string
    {
        return 'Item Standard Rates';
    }

    public function fields(): array
    {
        return [
            new ImportFieldDefinition(
                name: 'item_code',
                label: 'Item Code',
                type: 'string',
                required: true,
                isUniqueKey: true,
                synonyms: ['code', 'kode', 'sku', 'item code', 'itemcode'],
                example: 'ITEM-001',
            ),
            new ImportFieldDefinition(
                name: 'standard_rate',
                label: 'Standard Rate',
                type: 'number',
                required: true,
                synonyms: ['price', 'harga', 'unit price', 'unitprice', 'standard rate'],
                example: '10000',
                autoMapFrom: '_standard_rate',
            ),
        ];
    }

    public function model(): string
    {
        return Item::class;
    }

    public function uniqueKeyField(): string
    {
        return 'item_code';
    }

    public function validationRules(array $row, array $context): array
    {
        return [
            // Existence isn't checked here — write_mode=update_only (forced by the 1-step
            // orchestration for this module) already fails an unmatched item_code at commit
            // time with a clearer "Not found (update-only mode)." message per row.
            'item_code' => ['required', 'string', 'max:255'],
            'standard_rate' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function transformRow(array $row): array
    {
        $unitPrice = (float) ($row['UnitPrice'] ?? 0);
        $unitCost = (float) ($row['UnitCost'] ?? 0);

        $row['_standard_rate'] = $unitPrice > 0 ? $unitPrice : $unitCost;

        return $row;
    }
}
