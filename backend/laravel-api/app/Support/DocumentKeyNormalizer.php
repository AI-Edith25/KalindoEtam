<?php

namespace App\Support;

/**
 * Normalizes a legacy document/reference number for duplicate comparison —
 * trims whitespace and upper-cases so "SI/KE/00001/09/2026" and
 * " si/ke/00001/09/2026 " are recognized as the same number. Does not
 * reformat separators/padding; only case and surrounding whitespace.
 */
class DocumentKeyNormalizer
{
    public static function normalize(?string $value): ?string
    {
        $trimmed = strtoupper(trim((string) $value));

        return $trimmed === '' ? null : $trimmed;
    }
}
