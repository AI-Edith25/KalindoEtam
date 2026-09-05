<?php

namespace App\Services\Import\Templates;

use App\Models\SalesPerson;
use App\Services\Import\Contracts\ImportTemplate;
use App\Services\Import\ImportFieldDefinition;

/**
 * `is_active` is a boolean column, not a string status — transformRow() maps the legacy
 * file's "Active"/"Inactive" text to '1'/'0', defaulting to Active ('1') for anything else
 * (including blank), matching the user's own "default ke Active" instruction.
 * ponytail: no per-row warning emitted for an unrecognized Status value — the real file only
 * ever contains "Active", and the shared engine has no per-field "soft warning" path outside
 * FK resolution; add one if a genuinely ambiguous Status value shows up in practice.
 */
final class SalesPersonImportTemplate implements ImportTemplate
{
    public function key(): string
    {
        return 'sales-persons';
    }

    public function label(): string
    {
        return 'Sales Persons';
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
                synonyms: ['code', 'kode', 'sales person code'],
                example: 'SP-001',
            ),
            new ImportFieldDefinition(
                name: 'name',
                label: 'Name',
                type: 'string',
                required: true,
                synonyms: ['name', 'nama', 'sales person name'],
                example: 'Sample Sales Person',
            ),
            new ImportFieldDefinition(
                name: 'phone',
                label: 'Phone',
                type: 'string',
                synonyms: ['phone', 'telephone', 'telp', 'no telp'],
                example: '0541-123456',
            ),
            new ImportFieldDefinition(
                name: 'is_active',
                label: 'Active',
                type: 'string',
                synonyms: ['status', 'active'],
                example: '1',
                autoMapFrom: '_is_active',
            ),
        ];
    }

    public function model(): string
    {
        return SalesPerson::class;
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
            'phone' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'string'],
        ];
    }

    public function transformRow(array $row): array
    {
        $status = mb_strtolower(trim((string) ($row['Status'] ?? '')));

        $row['_is_active'] = in_array($status, ['inactive', 'nonaktif', 'tidak aktif', '0'], true) ? '0' : '1';

        return $row;
    }
}
