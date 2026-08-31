<?php

namespace App\Services\Import\Templates;

use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Services\Import\Contracts\ImportTemplate;
use App\Services\Import\ImportFieldDefinition;
use Illuminate\Validation\Rule;

/**
 * Items master import. `current_stock` is deliberately absent from fields()
 * — it's a cache column, `stock_ledgers` is the real source of truth, and
 * importing it directly would desync inventory (confirmed with user).
 */
final class ItemImportTemplate implements ImportTemplate
{
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
                synonyms: ['code', 'kode', 'sku', 'item code'],
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
                synonyms: ['group', 'item group', 'kategori', 'category'],
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
        ];
    }
}
