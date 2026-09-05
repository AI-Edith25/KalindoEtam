<?php

namespace App\Services\Import\Templates;

use App\Models\TermsOfPayment;
use App\Services\Import\Contracts\ImportTemplate;
use App\Services\Import\ImportFieldDefinition;
use App\Services\Import\Templates\Concerns\HasNoRowTransform;

final class TermsOfPaymentImportTemplate implements ImportTemplate
{
    use HasNoRowTransform;

    public function key(): string
    {
        return 'terms-of-payments';
    }

    public function label(): string
    {
        return 'Terms of Payment';
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
                synonyms: ['code', 'kode', 'term code'],
                example: 'COD',
            ),
            new ImportFieldDefinition(
                name: 'name',
                label: 'Name',
                type: 'string',
                required: true,
                synonyms: ['name', 'nama', 'term description', 'description'],
                example: 'Cash On Delivery',
            ),
            new ImportFieldDefinition(
                name: 'days',
                label: 'Days',
                type: 'number',
                required: true,
                synonyms: ['days', 'day', 'hari'],
                example: '30',
            ),
        ];
    }

    public function model(): string
    {
        return TermsOfPayment::class;
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
            'days' => ['required', 'integer', 'min:0'],
        ];
    }
}
