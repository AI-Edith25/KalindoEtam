<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\ImportBatch;

/**
 * The `permission:` route middleware only takes a static string, but the
 * import routes are shared across modules — so each action checks the
 * module's own `master.{module}.import` permission here instead of at the
 * route level. Dashes in the URL slug (e.g. "item-groups") map to
 * underscores in the permission name, matching this app's existing
 * `master.item_groups.*` naming.
 */
trait AuthorizesImportModule
{
    private function authorizeModule(string $module): void
    {
        $permission = 'master.'.str_replace('-', '_', $module).'.import';

        abort_unless(auth()->user()?->can($permission) ?? false, 403);
    }

    private function authorizeBatch(ImportBatch $batch): void
    {
        $this->authorizeModule($batch->module);
    }
}
