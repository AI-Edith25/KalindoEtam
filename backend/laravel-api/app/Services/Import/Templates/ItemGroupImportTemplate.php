<?php

namespace App\Services\Import\Templates;

use App\Models\ItemGroup;
use App\Services\Import\Contracts\ImportTemplate;
use App\Services\Import\ImportFieldDefinition;

final class ItemGroupImportTemplate implements ImportTemplate
{
    public function key(): string
    {
        return 'item-groups';
    }

    public function label(): string
    {
        return 'Item Groups';
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
                synonyms: ['name', 'nama', 'group', 'kategori', 'category', 'item group'],
                example: 'General',
            ),
            new ImportFieldDefinition(
                name: 'description',
                label: 'Description',
                type: 'string',
                synonyms: ['description', 'deskripsi', 'keterangan'],
                example: 'General purpose items',
            ),
        ];
    }

    public function model(): string
    {
        return ItemGroup::class;
    }

    public function uniqueKeyField(): string
    {
        return 'name';
    }

    public function validationRules(array $row, array $context): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
