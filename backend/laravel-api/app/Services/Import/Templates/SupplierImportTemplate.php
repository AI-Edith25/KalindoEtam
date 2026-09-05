<?php

namespace App\Services\Import\Templates;

use App\Models\Supplier;
use App\Services\Import\Contracts\ImportTemplate;
use App\Services\Import\ImportFieldDefinition;
use App\Services\Import\Templates\Concerns\ConcatenatesLegacyAddress;

final class SupplierImportTemplate implements ImportTemplate
{
    use ConcatenatesLegacyAddress;

    public function key(): string
    {
        return 'suppliers';
    }

    public function label(): string
    {
        return 'Suppliers';
    }

    public function fields(): array
    {
        return [
            new ImportFieldDefinition(
                name: 'supplier_code',
                label: 'Code',
                type: 'string',
                required: true,
                isUniqueKey: true,
                synonyms: ['code', 'kode', 'cuscode', 'supplier code'],
                example: 'SUP-001',
            ),
            new ImportFieldDefinition(
                name: 'supplier_name',
                label: 'Name',
                type: 'string',
                required: true,
                synonyms: ['name', 'nama', 'cusname', 'supplier name'],
                example: 'PT Sample Supplier',
            ),
            new ImportFieldDefinition(
                name: 'phone',
                label: 'Phone',
                type: 'string',
                synonyms: ['phone', 'tel', 'telepon', 'no telp'],
                example: '0541-123456',
            ),
            new ImportFieldDefinition(
                name: 'email',
                label: 'Email',
                type: 'string',
                synonyms: ['email', 'e-mail'],
                example: 'supplier@example.com',
            ),
            new ImportFieldDefinition(
                name: 'address',
                label: 'Address',
                type: 'string',
                synonyms: ['address', 'alamat'],
                example: 'Jl. Sample No. 1',
                autoMapFrom: '_address',
            ),
        ];
    }

    public function model(): string
    {
        return Supplier::class;
    }

    public function uniqueKeyField(): string
    {
        return 'supplier_code';
    }

    public function validationRules(array $row, array $context): array
    {
        return [
            'supplier_code' => ['required', 'string', 'max:255'],
            'supplier_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
        ];
    }
}
