<?php

namespace App\Support;

/**
 * Extracts a type+sequence(+month+year) key from a Skybiz-era SI/TR
 * reference so it can be matched against a KE `Invoice.document_number`
 * normalized the same way — both "SI/KE/07133/08/2026" (current KE format)
 * and "SI-KE-7212-08-2025" / "KE-TR-BPP-00017" (older Skybiz formats) must
 * resolve to a comparable key. Deliberately separate from
 * DocumentKeyNormalizer, which only upper-cases/trims for exact-string
 * duplicate checks — a different, simpler job.
 *
 * Returns null when the reference has no recognizable SI/TR token or no
 * digit token at all — these are reported as "unparseable", never guessed.
 */
class SkybizDocumentKeyNormalizer
{
    /** @return array{type: string, seq: int, strict_key: ?string, loose_key: string}|null */
    public static function normalize(?string $reference): ?array
    {
        $upper = strtoupper(trim((string) $reference));

        if ($upper === '') {
            return null;
        }

        $tokens = preg_split('/[^A-Z0-9]+/', $upper, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $type = match (true) {
            in_array('SI', $tokens, true) => 'SI',
            in_array('TR', $tokens, true) => 'TR',
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $digitTokens = array_values(array_filter($tokens, fn (string $t) => ctype_digit($t)));

        if ($digitTokens === []) {
            return null;
        }

        $seq = (int) $digitTokens[0];

        $yearIndex = null;
        $year = null;
        foreach ($digitTokens as $i => $token) {
            if ($i === 0) {
                continue;
            }

            if (strlen($token) === 4 && (int) $token >= 2000 && (int) $token <= 2099) {
                $yearIndex = $i;
                $year = (int) $token;
                break;
            }
        }

        $month = null;
        if ($yearIndex !== null) {
            foreach ($digitTokens as $i => $token) {
                if ($i === 0 || $i === $yearIndex) {
                    continue;
                }

                if (strlen($token) <= 2 && (int) $token >= 1 && (int) $token <= 12) {
                    $month = (int) $token;
                    break;
                }
            }
        }

        return [
            'type' => $type,
            'seq' => $seq,
            'strict_key' => $year !== null && $month !== null ? "{$type}|{$seq}|{$month}|{$year}" : null,
            'loose_key' => "{$type}|{$seq}",
        ];
    }
}
