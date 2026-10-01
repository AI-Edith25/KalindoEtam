<?php

namespace App\Support;

use App\Enums\DocumentStatus;

/**
 * Shared "is this document number already taken" check for Invoice/PurchaseOrder/GoodsReceipt —
 * all three use source_document_number (+ its _normalized shadow) as their duplicateKeyField().
 * A live match is always rejected outright, no override. A cancelled match is only rejected until
 * the caller's resolutions say to proceed — see Documentable::cancel(), which frees a cancelled
 * row's normalized column so it never also double-rejects via the unique index.
 */
class DocumentDuplicateChecker
{
    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass  Must declare
     *                                                                         source_document_number(_normalized) and a DocumentStatus-backed status column.
     * @param  array<int, string>  $fallbackReferenceColumns  Free-text columns (e.g. ['reference_1', 'reference_2']) a manual
     *                                                         entry might carry the legacy number in — checked only when $modelClass has them.
     * @return string|null A rejection reason, or null if this number is clear to use.
     */
    public static function reject(string $modelClass, string $rawNumber, array $resolutions, array &$seenNormalizedNumbers, array $fallbackReferenceColumns = []): ?string
    {
        $normalized = DocumentKeyNormalizer::normalize($rawNumber);

        if ($normalized === null) {
            return null;
        }

        if (isset($seenNormalizedNumbers[$normalized])) {
            return 'Nomor dokumen ini duplikat di dalam file yang sama — dilewati.';
        }

        if ($modelClass::query()->where('source_document_number_normalized', $normalized)->exists()) {
            return 'Nomor dokumen ini sudah ada pada data yang aktif (input manual maupun import sebelumnya) — ditolak.';
        }

        foreach ($fallbackReferenceColumns as $column) {
            if ($modelClass::query()->whereRaw("UPPER(TRIM({$column})) = ?", [$normalized])->exists()) {
                return "Nomor dokumen ini sudah tercatat pada {$column} dokumen lain — ditolak.";
            }
        }

        $cancelledMatch = $modelClass::query()
            ->where('status', DocumentStatus::CANCELLED->value)
            ->whereRaw('UPPER(TRIM(source_document_number)) = ?', [$normalized])
            ->exists();

        if ($cancelledMatch && ($resolutions['duplicate'][$rawNumber]['action'] ?? 'skip') !== 'proceed') {
            return 'Nomor dokumen ini bentrok dengan dokumen yang sudah dibatalkan (cancelled) — pilih "proceed" untuk tetap mengimpor.';
        }

        $seenNormalizedNumbers[$normalized] = true;

        return null;
    }
}
