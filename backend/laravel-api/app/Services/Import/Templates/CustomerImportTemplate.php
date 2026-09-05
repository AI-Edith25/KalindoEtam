<?php

namespace App\Services\Import\Templates;

use App\Models\Customer;
use App\Models\TermsOfPayment;
use App\Services\Import\Contracts\ImportTemplate;
use App\Services\Import\ImportFieldDefinition;
use App\Services\Import\Templates\Concerns\ConcatenatesLegacyAddress;
use Illuminate\Validation\Rule;

final class CustomerImportTemplate implements ImportTemplate
{
    use ConcatenatesLegacyAddress;

    public function key(): string
    {
        return 'customers';
    }

    public function label(): string
    {
        return 'Customers';
    }

    public function fields(): array
    {
        return [
            new ImportFieldDefinition(
                name: 'customer_code',
                label: 'Code',
                type: 'string',
                required: true,
                isUniqueKey: true,
                synonyms: ['code', 'kode', 'cuscode', 'customer code'],
                example: 'CUS-001',
            ),
            new ImportFieldDefinition(
                name: 'customer_name',
                label: 'Name',
                type: 'string',
                required: true,
                synonyms: ['name', 'nama', 'cusname', 'customer name'],
                example: 'PT Sample Customer',
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
                example: 'customer@example.com',
            ),
            new ImportFieldDefinition(
                name: 'address',
                label: 'Address',
                type: 'string',
                synonyms: ['address', 'alamat'],
                example: 'Jl. Sample No. 1',
                autoMapFrom: '_address',
            ),
            new ImportFieldDefinition(
                name: 'terms_of_payment_id',
                label: 'Terms of Payment',
                type: 'fk',
                fkTarget: ['model' => TermsOfPayment::class, 'displayColumn' => 'code'],
                synonyms: ['termcode', 'terms of payment', 'terms code', 'top'],
                example: 'COD',
            ),
            new ImportFieldDefinition(
                name: 'credit_limit',
                label: 'Credit Limit',
                type: 'number',
                synonyms: ['credit limit', 'creditlimit', 'limit kredit'],
                example: '50000000',
            ),
        ];
    }

    public function model(): string
    {
        return Customer::class;
    }

    public function uniqueKeyField(): string
    {
        return 'customer_code';
    }

    public function validationRules(array $row, array $context): array
    {
        return [
            'customer_code' => ['required', 'string', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'terms_of_payment_id' => ['nullable', 'uuid', Rule::exists('terms_of_payments', 'id')],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
