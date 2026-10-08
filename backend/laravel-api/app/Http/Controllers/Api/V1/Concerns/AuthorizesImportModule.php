<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\ImportBatch;

/**
 * The `permission:` route middleware only takes a static string, but the
 * import routes are shared across modules — so each action checks the
 * module's own `{group}.{module}.import` permission here instead of at the
 * route level. Dashes in the URL slug (e.g. "item-groups") map to
 * underscores in the permission name, matching this app's existing
 * `master.item_groups.*` naming. Every module defaults to the `master`
 * permission group except the ones listed below — Opening Stock lives
 * under Inventory's own permission group, not Master Data.
 */
trait AuthorizesImportModule
{
    private const MODULE_PERMISSION_GROUPS = [
        'opening-stock' => 'inventory',
    ];

    /**
     * The document-import modules (Official Receipt, Payment Voucher, Purchase History) predate
     * this trait's generic group+slug convention and already have their own, differently-named
     * permissions wired on their own store/resolve/show routes — so their {batch}/failed-rows
     * download (routed generically through ImportController, see routes/api.php import/batches
     * group) checks the exact same permission those routes use, rather than trying to force them
     * through the master.{module}.import pattern.
     */
    private const MODULE_PERMISSION_OVERRIDES = [
        'purchase-history' => 'reports.purchase.import',
        'official-receipts' => 'finance.incoming_payment.import',
        'payment-vouchers' => 'finance.outgoing_payment.import',
        'skybiz-ledger-reconciliation' => 'finance.incoming_payment.import',
    ];

    private function authorizeModule(string $module): void
    {
        $permission = self::MODULE_PERMISSION_OVERRIDES[$module]
            ?? (self::MODULE_PERMISSION_GROUPS[$module] ?? 'master').'.'.str_replace('-', '_', $module).'.import';

        abort_unless(auth()->user()?->can($permission) ?? false, 403);
    }

    private function authorizeBatch(ImportBatch $batch): void
    {
        $this->authorizeModule($batch->module);
    }
}
