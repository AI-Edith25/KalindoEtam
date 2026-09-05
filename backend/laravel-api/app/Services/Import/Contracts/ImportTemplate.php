<?php

namespace App\Services\Import\Contracts;

use App\Services\Import\ImportFieldDefinition;

/**
 * Per-module import definition — drives the mapping UI's field list, the
 * blank-template download, and row validation. One instance per importable
 * module (Items now; Item Groups/UOMs follow the same shape).
 */
interface ImportTemplate
{
    public function key(): string;

    public function label(): string;

    /** @return ImportFieldDefinition[] */
    public function fields(): array;

    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    public function model(): string;

    public function uniqueKeyField(): string;

    /**
     * Laravel validation rules for one cleaned+mapped row. $context carries
     * anything a rule needs beyond the row itself (e.g. a resolved FK id map),
     * matching the row-conditional shape StoreItemRequest already uses for tax.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function validationRules(array $row, array $context): array;

    /**
     * Runs on one raw (header-keyed) row before field-mapping is applied — lets a
     * template synthesize a derived column (e.g. joining Address1-4 into one
     * address string) that a batch's mapping can then point a field at, since
     * mapping itself is strictly one real file header -> one field. Most
     * templates have nothing to derive and just return $row unchanged.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function transformRow(array $row): array;
}
