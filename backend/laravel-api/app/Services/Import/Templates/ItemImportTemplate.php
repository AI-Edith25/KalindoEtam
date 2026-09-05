<?php

namespace App\Services\Import\Templates;

use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use App\Services\Import\Contracts\ImportTemplate;
use App\Services\Import\ImportFieldDefinition;
use App\Services\Import\Templates\Concerns\HasNoRowTransform;
use Illuminate\Validation\Rule;

/**
 * Items master import. `current_stock` is deliberately absent from fields()
 * — it's a cache column, `stock_ledgers` is the real source of truth, and
 * importing it directly would desync inventory (confirmed with user).
 *
 * `standard_rate` stays declared here (pre-existing field, matches an
 * "unit price"/"harga" header if one exists) but the 1-step auto-import
 * orchestration (ImportBatchService::autoImport) strips it from this
 * module's mapping before committing — Standard Rate for a legacy import
 * is meant to come from the separate `item-standard-rates` module instead,
 * never from this one, even if a file happens to have both.
 */
final class ItemImportTemplate implements ImportTemplate
{
    use HasNoRowTransform;

    public function key(): string
    {
        return 'items';
    }

    public function label(): string
    {
        return 'Items';
    }

    public function fields(): array
    {
        return [
            new ImportFieldDefinition(
                name: 'item_code',
                label: 'Code',
                type: 'string',
                required: true,
                isUniqueKey: true,
                synonyms: ['code', 'kode', 'sku', 'item code', 'itemcode'],
                example: 'ITEM-001',
            ),
            new ImportFieldDefinition(
                name: 'item_name',
                label: 'Name',
                type: 'string',
                required: true,
                synonyms: ['name', 'nama', 'item name', 'description'],
                example: 'Sample Item',
            ),
            new ImportFieldDefinition(
                name: 'item_group_id',
                label: 'Group',
                type: 'fk',
                required: true,
                fkTarget: ['model' => ItemGroup::class, 'displayColumn' => 'name'],
                synonyms: ['group', 'item group', 'itemgroup', 'kategori', 'category'],
                example: 'General',
            ),
            new ImportFieldDefinition(
                name: 'uom_id',
                label: 'UOM',
                type: 'fk',
                required: true,
                fkTarget: ['model' => UnitOfMeasurement::class, 'displayColumn' => 'name'],
                synonyms: ['uom', 'unit', 'satuan'],
                example: 'Pcs',
            ),
            new ImportFieldDefinition(
                name: 'standard_rate',
                label: 'Standard Rate',
                type: 'number',
                synonyms: ['price', 'harga', 'tarif', 'unit price', 'standard rate'],
                example: '10000',
            ),
            new ImportFieldDefinition(
                name: 'purchase_tax_id',
                label: 'Purchase Tax',
                type: 'fk',
                fkTarget: ['model' => Tax::class, 'displayColumn' => 'code'],
                synonyms: ['purchase tax', 'purchase tax code', 'purchasetaxcode', 'pajak beli'],
                example: 'PPN11',
            ),
            new ImportFieldDefinition(
                name: 'sales_tax_id',
                label: 'Sales Tax',
                type: 'fk',
                fkTarget: ['model' => Tax::class, 'displayColumn' => 'code'],
                synonyms: ['sales tax', 'sales tax code', 'salestaxcode', 'pajak jual'],
                example: 'PPN11',
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
            'item_code' => ['required', 'string', 'max:255'],
            'item_name' => ['required', 'string', 'max:255'],
            'item_group_id' => ['required', 'uuid', Rule::exists('item_groups', 'id')],
            'uom_id' => ['required', 'uuid', Rule::exists('uoms', 'id')],
            'standard_rate' => ['nullable', 'numeric', 'min:0'],
            'purchase_tax_id' => ['nullable', 'uuid', Rule::exists('taxes', 'id')],
            'sales_tax_id' => ['nullable', 'uuid', Rule::exists('taxes', 'id')],
        ];
    }
}
