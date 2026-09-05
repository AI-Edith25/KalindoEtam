<?php

namespace App\Services\Import\Templates\Concerns;

/**
 * Legacy Supplier/Customer exports split the address across Address1-4 with
 * no equivalent split in this app's schema (single flat `address` column) —
 * joins the non-blank segments with ", " into a synthetic `_address` key
 * that the template's `address` field maps from instead of a real header.
 */
trait ConcatenatesLegacyAddress
{
    public function transformRow(array $row): array
    {
        $segments = [];

        foreach (['Address1', 'Address2', 'Address3', 'Address4'] as $header) {
            $value = trim((string) ($row[$header] ?? ''));

            if ($value !== '') {
                $segments[] = $value;
            }
        }

        $row['_address'] = implode(', ', $segments);

        return $row;
    }
}
