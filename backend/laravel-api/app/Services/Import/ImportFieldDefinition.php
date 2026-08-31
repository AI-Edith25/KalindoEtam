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
