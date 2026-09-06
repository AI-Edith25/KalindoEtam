<?php

namespace App\Services\Import\Contracts;

/**
 * Opt-in escape hatch for a template whose row is more than the generic engine's
 * $modelClass::updateOrCreate() can express — a document header + one line item
 * (Opening Stock: one imported row is always one whole new document), not a single flat
 * record. ProcessImportBatchJob checks `instanceof` this before falling back to its default
 * per-row write — every other template is completely unaffected by this existing.
 */
interface CreatesRelatedRecords
{
    /**
     * Persists one cleaned+mapped row. Every row is always a fresh record here — there's no
     * natural "unique key to upsert by" the way Items/Suppliers have, so unlike the default
     * path this replaces, no exists-check or write_mode (insert_only/update_only) branching
     * applies; $importBatchId is provided so the created record(s) can be tagged and later
     * reviewed/submitted/cancelled as one batch.
     *
     * @param  array<string, mixed>  $data
     */
    public function persist(array $data, string $importBatchId): void;
}
