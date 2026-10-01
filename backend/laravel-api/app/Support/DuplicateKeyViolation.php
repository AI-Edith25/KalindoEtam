<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Tells a unique-constraint violation on a duplicate-key index (e.g. the *_normalized columns
 * from migration 2026_10_01_000003) apart from any other database error, so a race between two
 * concurrent imports can be turned into a clean "rejected — duplicate" outcome instead of a 500.
 */
class DuplicateKeyViolation
{
    public static function detected(Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }

        if (($e->errorInfo[1] ?? null) === 1062) {
            return true; // MySQL: Duplicate entry ... for key ...
        }

        return str_contains($e->getMessage(), 'UNIQUE constraint failed'); // SQLite
    }
}
