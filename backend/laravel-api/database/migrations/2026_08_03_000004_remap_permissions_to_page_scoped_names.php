<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Renames every permission from the old {backend_resource}.{action} scheme
 * to the new {navGroup}.{page}.{action} scheme (see docs/PERMISSION_MODEL_DESIGN.md).
 *
 * This map is deliberately hardcoded here rather than sourced from
 * RolePermissionSeeder — a migration is an immutable historical record; if
 * it read from a shared "current" catalog that later changes (e.g. a new
 * module added next sprint), replaying this migration on a fresh DB or in
 * CI would silently do something different than what actually ran in
 * production on this date. The seeder always reflects "current" state and
 * is rewritten independently.
 *
 * Each old permission maps to one or more new permissions ("closest
 * faithful equivalent of what a role holding it could already do" —
 * several old permissions gated more than one page, most notably
 * journal_entry.* covering 7 distinct Accounting Reports pages, and
 * stock.* covering 3 distinct Inventory pages, plus every *_order/
 * *_receipt/delivery .view gaining its matching new reports.* permission
 * since the old permission already gated both the transactional AND the
 * Report page). Old permissions with no new equivalent (rows that existed
 * from the seeder's uniform per-module 4-action loop but were never wired
 * to any actual route — e.g. dashboard.create, audit_log.delete,
 * payment_allocation.view) map to an empty array: intentionally dropped,
 * not an oversight.
 */
return new class extends Migration
{
    protected array $oldToNew = [
        // Administration — 1:1
        'company.view' => ['administration.company.view'],
        'company.create' => ['administration.company.create'],
        'company.update' => ['administration.company.update'],
        'company.delete' => ['administration.company.delete'],
        'branch.view' => ['administration.branch.view'],
        'branch.create' => ['administration.branch.create'],
        'branch.update' => ['administration.branch.update'],
        'branch.delete' => ['administration.branch.delete'],
        'role.view' => ['administration.roles.view'],
        'role.create' => ['administration.roles.create'],
        'role.update' => ['administration.roles.update'],
        'role.delete' => ['administration.roles.delete'],
        'user.view' => ['administration.users.view'],
        'user.create' => ['administration.users.create'],
        'user.update' => ['administration.users.update'],
        'user.delete' => [], // no destroy route ever existed (apiResource->except('destroy'))
        'audit_log.view' => ['administration.audit_log.view'],
        'audit_log.create' => [], // AuditLogController has no write actions
        'audit_log.update' => [],
        'audit_log.delete' => [],
        'naming_series.view' => ['administration.naming_series.view'],
        'naming_series.create' => ['administration.naming_series.create'],
        'naming_series.update' => ['administration.naming_series.update'],
        'naming_series.delete' => ['administration.naming_series.delete'],

        // Master Data — 1:1
        'item.view' => ['master.items.view'],
        'item.create' => ['master.items.create'],
        'item.update' => ['master.items.update'],
        'item.delete' => ['master.items.delete'],
        'item_group.view' => ['master.item_groups.view'],
        'item_group.create' => ['master.item_groups.create'],
        'item_group.update' => ['master.item_groups.update'],
        'item_group.delete' => ['master.item_groups.delete'],
        'uom.view' => ['master.uoms.view'],
        'uom.create' => ['master.uoms.create'],
        'uom.update' => ['master.uoms.update'],
        'uom.delete' => ['master.uoms.delete'],
        'currency.view' => ['master.currencies.view'],
        'currency.create' => ['master.currencies.create'],
        'currency.update' => ['master.currencies.update'],
        'currency.delete' => ['master.currencies.delete'],
        'tax.view' => ['master.taxes.view'],
        'tax.create' => ['master.taxes.create'],
        'tax.update' => ['master.taxes.update'],
        'tax.delete' => ['master.taxes.delete'],
        'customer.view' => ['master.customers.view'],
        'customer.create' => ['master.customers.create'],
        'customer.update' => ['master.customers.update'],
        'customer.delete' => ['master.customers.delete'],
        'supplier.view' => ['master.suppliers.view'],
        'supplier.create' => ['master.suppliers.create'],
        'supplier.update' => ['master.suppliers.update'],
        'supplier.delete' => ['master.suppliers.delete'],
        'warehouse.view' => ['master.warehouses.view'],
        'warehouse.create' => ['master.warehouses.create'],
        'warehouse.update' => ['master.warehouses.update'],
        'warehouse.delete' => ['master.warehouses.delete'],
        'chart_of_account.view' => ['master.chart_of_accounts.view'],
        'chart_of_account.create' => ['master.chart_of_accounts.create'],
        'chart_of_account.update' => ['master.chart_of_accounts.update'],
        'chart_of_account.delete' => ['master.chart_of_accounts.delete'],

        // Inventory — stock.* fans out to 3 pages: a holder of stock.view could reach
        // Stock Balance, Stock Ledger, AND Adjustments (all three routed on stock.view
        // today); stock.create covered both stock-in and creating an adjustment;
        // stock.update/.delete only ever gated Adjustments (no ledger update/delete route).
        'stock.view' => ['inventory.stock_balance.view', 'inventory.stock_ledger.view', 'inventory.adjustments.view'],
        'stock.create' => ['inventory.stock_ledger.create', 'inventory.adjustments.create'],
        'stock.update' => ['inventory.adjustments.update'],
        'stock.delete' => ['inventory.adjustments.delete'],

        // Purchase — .view fans out to the matching Report page, since the old
        // permission already gated both the transactional list AND the report.
        'purchase_order.view' => ['purchase.orders.view', 'reports.purchase.view'],
        'purchase_order.create' => ['purchase.orders.create'],
        'purchase_order.update' => ['purchase.orders.update'],
        'purchase_order.delete' => ['purchase.orders.delete'],
        'purchase_order.approve' => ['purchase.orders.approve'],
        'goods_receipt.view' => ['purchase.goods_receipts.view', 'reports.goods_receipts.view'],
        'goods_receipt.create' => ['purchase.goods_receipts.create'],
        'goods_receipt.update' => ['purchase.goods_receipts.update'],
        'goods_receipt.delete' => ['purchase.goods_receipts.delete'],

        // Sales — same .view fan-out reasoning as Purchase above.
        'sales_order.view' => ['sales.orders.view', 'reports.sales.view'],
        'sales_order.create' => ['sales.orders.create'],
        'sales_order.update' => ['sales.orders.update'],
        'sales_order.delete' => ['sales.orders.delete'],
        'sales_order.approve' => ['sales.orders.approve'],
        'delivery.view' => ['sales.deliveries.view', 'reports.deliveries.view'],
        'delivery.create' => ['sales.deliveries.create'],
        'delivery.update' => ['sales.deliveries.update'],
        'delivery.delete' => ['sales.deliveries.delete'],
        'invoice.view' => ['sales.invoices.view'],
        'invoice.create' => ['sales.invoices.create'],
        'invoice.update' => ['sales.invoices.update'],
        'invoice.delete' => ['sales.invoices.delete'],
        'credit_note.view' => ['sales.credit_notes.view'],
        'credit_note.create' => ['sales.credit_notes.create'],
        'credit_note.update' => ['sales.credit_notes.update'],
        'credit_note.delete' => ['sales.credit_notes.delete'],
        'debit_note.view' => ['sales.debit_notes.view'],
        'debit_note.create' => ['sales.debit_notes.create'],
        'debit_note.update' => ['sales.debit_notes.update'],
        'debit_note.delete' => ['sales.debit_notes.delete'],

        // Finance — 1:1 for the two payment types; AP/AR were always view-only in
        // practice (only a .view route ever existed despite all 4 actions being
        // seeded); payment_allocation only ever had .create (allocate) and .update
        // (reverse) routes, never .view/.delete.
        'payment_entry.view' => ['finance.outgoing_payment.view'],
        'payment_entry.create' => ['finance.outgoing_payment.create'],
        'payment_entry.update' => ['finance.outgoing_payment.update'],
        'payment_entry.delete' => ['finance.outgoing_payment.delete'],
        'receipt_entry.view' => ['finance.incoming_payment.view'],
        'receipt_entry.create' => ['finance.incoming_payment.create'],
        'receipt_entry.update' => ['finance.incoming_payment.update'],
        'receipt_entry.delete' => ['finance.incoming_payment.delete'],
        'accounts_payable.view' => ['finance.accounts_payable.view'],
        'accounts_payable.create' => [],
        'accounts_payable.update' => [],
        'accounts_payable.delete' => [],
        'accounts_receivable.view' => ['finance.accounts_receivable.view'],
        'accounts_receivable.create' => [],
        'accounts_receivable.update' => [],
        'accounts_receivable.delete' => [],
        'payment_allocation.view' => [],
        'payment_allocation.create' => ['finance.payment_allocation.create'],
        'payment_allocation.update' => ['finance.payment_allocation.update'],
        'payment_allocation.delete' => [],

        // Accounting — journal_entry.* is the worst offender this refactor fixes:
        // one permission covered 7 distinct pages. .view covered Journal Entries,
        // General Ledger, Trial Balance, Profit & Loss, Balance Sheet, Cash Flow, AND
        // Period Closing's own read views. .update covered Journal Entry post/reverse/
        // request-approval, creating a fiscal year, and closing/reopening a period.
        'journal_entry.view' => [
            'accounting.journal_entries.view',
            'accounting.general_ledger.view',
            'accounting.trial_balance.view',
            'accounting.profit_loss.view',
            'accounting.balance_sheet.view',
            'accounting.cash_flow.view',
            'accounting.period_closing.view',
        ],
        'journal_entry.create' => ['accounting.journal_entries.create'],
        'journal_entry.update' => [
            'accounting.journal_entries.update',
            'accounting.period_closing.create', // old route gated "POST fiscal-years" on journal_entry.update
            'accounting.period_closing.update',
        ],
        'journal_entry.delete' => ['accounting.journal_entries.delete'],
        'journal_entry.approve' => ['accounting.journal_entries.approve'],

        // Document Engine — cross-cutting infra, reserved non-nav "system" group.
        'document_attachment.view' => ['system.document_attachment.view'],
        'document_attachment.create' => ['system.document_attachment.create'],
        'document_attachment.update' => [], // no update route ever existed
        'document_attachment.delete' => ['system.document_attachment.delete'],
        'document_timeline.view' => ['system.document_timeline.view'],
        'document_timeline.create' => [],
        'document_timeline.update' => [],
        'document_timeline.delete' => [],

        // Dashboard — unchanged; .create/.update/.delete were seeded but never
        // wired to any route (Dashboard is entirely read-only), dropped.
        'dashboard.view' => ['dashboard.view'],
        'dashboard.create' => [],
        'dashboard.update' => [],
        'dashboard.delete' => [],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            $newNames = collect($this->oldToNew)->flatten()->unique()->values();

            foreach ($newNames as $name) {
                Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }

            Role::query()->with('permissions')->each(function (Role $role) {
                $newSet = collect();

                foreach ($role->permissions as $permission) {
                    $newSet = $newSet->merge($this->oldToNew[$permission->name] ?? []);
                }

                $role->syncPermissions($newSet->unique()->values()->all());
            });

            // dashboard.view is both an old key and a new value (Dashboard is unchanged) —
            // never soft-delete a name that's still valid post-migration, or every role
            // (including Admin) silently loses it the instant this runs.
            $oldNames = collect(array_keys($this->oldToNew))->diff($newNames)->values();
            Permission::query()->whereIn('name', $oldNames)->delete(); // soft-delete (model has SoftDeletes)
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            $newToOld = [];
            foreach ($this->oldToNew as $old => $news) {
                foreach ($news as $new) {
                    $newToOld[$new][] = $old;
                }
            }

            Permission::onlyTrashed()
                ->whereIn('name', array_keys($this->oldToNew))
                ->restore();

            Role::query()->with('permissions')->each(function (Role $role) {
                $oldSet = collect();

                foreach ($role->permissions as $permission) {
                    $oldSet = $oldSet->merge($newToOld[$permission->name] ?? []);
                }

                $role->syncPermissions($oldSet->unique()->values()->all());
            });

            // Same dashboard.view exception as up(): never soft-delete a name that's
            // also a valid old name — it needs to keep existing either way.
            $newNames = collect($this->oldToNew)->flatten()->unique()->diff(array_keys($this->oldToNew))->values();
            Permission::query()->whereIn('name', $newNames)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
