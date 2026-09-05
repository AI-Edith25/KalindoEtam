<?php

namespace App\Services\Import\Templates;

use App\Models\Warehouse;
use App\Services\Import\Contracts\ImportTemplate;
use App\Services\Import\ImportFieldDefinition;
use App\Services\Import\Templates\Concerns\HasNoRowTransform;
use Illuminate\Validation\Rule;

/**
 * "Area" in the legacy system's export (xlsBranchListing) and breadcrumb —
 * maps onto this app's Warehouse master, not a separate Area/Branch table
 * (neither exists; branch_id was dropped from warehouses months ago).
 *
 * `warehouse_type` has no DB default and isn't on the legacy file at all, but
 * it's still declared as a field here (required: false — no file column will
 * ever map to it) purely so ImportBatchService's field_defaults mechanism has
 * somewhere to write it: buildCleanedRows() only reads $fieldDefaults[$name]
 * for names it finds while iterating fields(), so an undeclared field's
 * default is silently never applied. The 1-step orchestration supplies
 * 'transit' for every imported row; validationRules() below still requires
 * it non-empty, since the DB column itself has no default.
 */
final class WarehouseImportTemplate implements ImportTemplate
{
    use HasNoRowTransform;

    public function key(): string
    {
        return 'warehouses';
    }

    public function label(): string
    {
        return 'Warehouses (Area)';
    }

    public function fields(): array
    {
        return [
            new ImportFieldDefinition(
                name: 'code',
                label: 'Code',
                type: 'string',
                required: true,
                isUniqueKey: true,
                synonyms: ['code', 'kode'],
                example: 'BPP',
            ),
            new ImportFieldDefinition(
                name: 'name',
                label: 'Name',
                type: 'string',
                required: true,
                synonyms: ['name', 'nama', 'description'],
                example: 'Balikpapan',
            ),
            new ImportFieldDefinition(
                name: 'warehouse_type',
                label: 'Type',
                type: 'string',
                synonyms: ['type', 'tipe', 'warehouse type'],
                example: 'transit',
            ),
        ];
    }

    public function model(): string
    {
        return Warehouse::class;
    }

    public function uniqueKeyField(): string
    {
        return 'code';
    }

    public function validationRules(array $row, array $context): array
    {
        return [
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'warehouse_type' => ['required', Rule::in(['main', 'transit', 'return'])],
        ];
    }
}
