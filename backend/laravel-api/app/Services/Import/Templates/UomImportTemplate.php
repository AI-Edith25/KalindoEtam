<?php

namespace App\Services\Import\Templates;

use App\Models\UnitOfMeasurement;
use App\Services\Import\Contracts\ImportTemplate;
use App\Services\Import\ImportFieldDefinition;

final class UomImportTemplate implements ImportTemplate
{
    public function key(): string
    {
        return 'uoms';
    }

    public function label(): string
    {
        return 'UOMs';
    }

    public function fields(): array
    {
        return [
            new ImportFieldDefinition(
                name: 'name',
                label: 'Name',
                type: 'string',
                required: true,
                isUniqueKey: true,
                synonyms: ['name', 'nama', 'uom', 'unit', 'satuan'],
                example: 'Pcs',
            ),
            new ImportFieldDefinition(
                name: 'symbol',
                label: 'Symbol',
                type: 'string',
                synonyms: ['symbol', 'simbol', 'singkatan'],
                example: 'pcs',
            ),
        ];
    }

    public function model(): string
    {
        return UnitOfMeasurement::class;
    }

    public function uniqueKeyField(): string
    {
        return 'name';
    }

    public function validationRules(array $row, array $context): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:255'],
        ];
    }
}
