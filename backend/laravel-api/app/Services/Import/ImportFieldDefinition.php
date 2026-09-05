<?php

namespace App\Services\Import;

/**
 * One importable field on a module's template — the single source of truth
 * consumed by the mapping UI's field list, the blank-template download, and
 * (via the owning ImportTemplate) row validation.
 *
 * @param  string  $type  'string' | 'number' | 'date' | 'fk'
 * @param  array{model: class-string<\Illuminate\Database\Eloquent\Model>, displayColumn: string}|null  $fkTarget
 * @param  string[]  $synonyms  lower-cased alternative header names used for mapping auto-suggest
 * @param  ?string  $autoMapFrom  a synthetic row key (see ImportTemplate::transformRow()) this field
 *   should always map from in the 1-step auto-import flow, instead of a real file header — e.g.
 *   Supplier/Customer's `address` field maps from `_address` (Address1-4 joined by transformRow()).
 */
final class ImportFieldDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly bool $required = false,
        public readonly bool $isUniqueKey = false,
        public readonly ?array $fkTarget = null,
        public readonly array $synonyms = [],
        public readonly string $example = '',
        public readonly ?string $autoMapFrom = null,
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'is_unique_key' => $this->isUniqueKey,
            'fk_target' => $this->fkTarget,
            'synonyms' => $this->synonyms,
        ];
    }
}
