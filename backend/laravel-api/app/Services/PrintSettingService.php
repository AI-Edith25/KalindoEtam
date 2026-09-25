<?php

namespace App\Services;

use App\Models\UserPrintSetting;
use Illuminate\Validation\ValidationException;

/**
 * Per-user print settings (Delivery Order / Invoice print's "Print Options" dialog) — read with
 * priority server -> localStorage -> default (see printOptions.ts on the frontend), written both
 * by a user for themselves and by an admin for another user (same document-type set either way).
 */
class PrintSettingService
{
    /** Delivery Order + Invoice only for now (the two documents with the dot-matrix overflow bug) — see UserPrintSetting's own migration doc comment for why this is a plain string column, not an enum. */
    public const DOCUMENT_TYPES = ['delivery-order', 'invoice'];

    /** @return array<string, array<string, mixed>|null> keyed by every supported document type, null where the user has no saved row yet. */
    public function forUser(string $userId): array
    {
        $rows = UserPrintSetting::query()->where('user_id', $userId)->get()->keyBy('document_type');

        return collect(self::DOCUMENT_TYPES)->mapWithKeys(fn (string $type) => [$type => $rows->get($type)?->settings])->all();
    }

    /** @param array<string, mixed> $settings */
    public function save(string $userId, string $documentType, array $settings, string $updatedBy): UserPrintSetting
    {
        if (! in_array($documentType, self::DOCUMENT_TYPES, true)) {
            throw ValidationException::withMessages(['document_type' => ['Unsupported document type.']]);
        }

        return UserPrintSetting::query()->updateOrCreate(
            ['user_id' => $userId, 'document_type' => $documentType],
            ['settings' => $settings, 'updated_by' => $updatedBy],
        );
    }
}
