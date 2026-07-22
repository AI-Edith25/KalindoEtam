<?php

use App\Http\Controllers\Api\V1\AccountsPayableController;
use App\Http\Controllers\Api\V1\AccountsReceivableController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\ChartOfAccountController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CreditNoteController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DebitNoteController;
use App\Http\Controllers\Api\V1\DeliveryController;
use App\Http\Controllers\Api\V1\DocumentAttachmentController;
use App\Http\Controllers\Api\V1\DocumentTimelineController;
use App\Http\Controllers\Api\V1\GeneralLedgerController;
use App\Http\Controllers\Api\V1\TrialBalanceController;
use App\Http\Controllers\Api\V1\ProfitLossController;
use App\Http\Controllers\Api\V1\BalanceSheetController;
use App\Http\Controllers\Api\V1\CashFlowController;
use App\Http\Controllers\Api\V1\PeriodController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ItemGroupController;
use App\Http\Controllers\Api\V1\JournalEntryController;
use App\Http\Controllers\Api\V1\NamingSeriesController;
use App\Http\Controllers\Api\V1\PaymentAllocationController;
use App\Http\Controllers\Api\V1\PaymentEntryController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\ReceiptEntryController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockInController;
use App\Http\Controllers\Api\V1\StockLedgerController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TaxController;
use App\Http\Controllers\Api\V1\UomController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WarehouseController;
use Illuminate\Routing\PendingResourceRegistration;
use Illuminate\Support\Facades\Route;

/**
 * Gates a resource's standard actions behind page-scoped {group}.{page}.{action}
 * permissions (view/create/update/delete). $page is the full "group.page"
 * prefix (e.g. "sales.orders"), matching the frontend's navTree.ts page keys
 * 1:1 — permissions are named after the application page that owns them,
 * never the backend resource, so two pages that happen to share a resource
 * never share a permission by accident.
 *
 * $viewOr appends additional permissions (via Spatie's native pipe-delimited
 * "any of" support, PermissionMiddleware -> canAny()) to the index/show gate
 * only, for the handful of endpoints genuinely read by more than one page
 * (e.g. GET /deliveries backs both Sales -> Deliveries and Reports -> Delivery).
 * create/update/delete are never OR'd — exactly one page owns writes to any
 * given resource.
 *
 * A local closure, not a top-level `function` — this file is re-included
 * on every fresh Application boot within the same PHP process (every
 * Feature test does this), and a global function declaration would fatal
 * with "Cannot redeclare" on the second boot.
 */
$withPagePermissions = function (PendingResourceRegistration $resource, string $page, ?string $viewOr = null): PendingResourceRegistration {
    $view = $viewOr ? "permission:{$page}.view|{$viewOr}" : "permission:{$page}.view";

    return $resource
        ->middlewareFor(['index', 'show'], $view)
        ->middlewareFor('store', "permission:{$page}.create")
        ->middlewareFor('update', "permission:{$page}.update")
        ->middlewareFor('destroy', "permission:{$page}.delete");
};

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
});

Route::prefix('v1')->middleware('auth:sanctum')->group(function () use ($withPagePermissions) {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    $withPagePermissions(Route::apiResource('companies', CompanyController::class), 'administration.company');
    // App-shell branding (name/logo only) — every authenticated user needs this regardless of
    // administration.company.view, see docs/ADMINISTRATION_DESIGN.md. Deliberately outside $withPagePermissions.
    Route::get('company/branding', [CompanyController::class, 'branding']);
    Route::get('company/branding/logo', [CompanyController::class, 'brandingLogo']);
    // 'master.warehouses.view' is OR'd in — Warehouse create/edit needs to list Branches to
    // assign one, without requiring the full Administration > Branch permission. Same pattern
    // as sales-orders/purchase-orders below pulling in their own Reports view permission.
    $withPagePermissions(Route::apiResource('branches', BranchController::class), 'administration.branch', 'master.warehouses.view');
    $withPagePermissions(Route::apiResource('warehouses', WarehouseController::class), 'master.warehouses');

    $withPagePermissions(Route::apiResource('roles', RoleController::class), 'administration.roles');
    Route::post('roles/{role}/permissions', [RoleController::class, 'assignPermissions'])->middleware('permission:administration.roles.update');
    Route::get('permissions', [PermissionController::class, 'index']);

    // Administration — User Management (docs/ADMINISTRATION_DESIGN.md §4): the backend
    // this sprint adds, since only auth/login|logout|me existed before.
    // No destroy — deactivate (below) is the intended lifecycle action, same
    // "prefer deactivation over deletion" convention Tax already established.
    $withPagePermissions(Route::apiResource('users', UserController::class)->except('destroy'), 'administration.users');
    Route::post('users/{user}/activate', [UserController::class, 'activate'])->middleware('permission:administration.users.update');
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->middleware('permission:administration.users.update');
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('permission:administration.users.update');
    Route::post('users/{user}/role', [UserController::class, 'assignRole'])->middleware('permission:administration.users.update');

    // Administration — Audit Log (docs/ADMINISTRATION_DESIGN.md §6): read-only, system-wide,
    // deliberately not DocumentTimeline. Gated on the same 'administration.audit_log' page every
    // other resource here uses, kept view-only since AuditLogController has no write actions.
    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:administration.audit_log.view');

    $withPagePermissions(Route::apiResource('item-groups', ItemGroupController::class), 'master.item_groups');
    $withPagePermissions(Route::apiResource('uoms', UomController::class), 'master.uoms');
    $withPagePermissions(Route::apiResource('currencies', CurrencyController::class), 'master.currencies');
    $withPagePermissions(Route::apiResource('taxes', TaxController::class), 'master.taxes');
    $withPagePermissions(Route::apiResource('customers', CustomerController::class), 'master.customers');
    $withPagePermissions(Route::apiResource('suppliers', SupplierController::class), 'master.suppliers');

    $withPagePermissions(Route::apiResource('items', ItemController::class), 'master.items');
    Route::get('items/{item}/stock-ledger', [StockLedgerController::class, 'index'])->middleware('permission:inventory.stock_ledger.view|reports.inventory_movement.view');
    // Bulk, warehouse-scoped current balance — exposes the existing StockLedgerService::getCurrentBalance()
    // (already used internally by DeliveryService::assertSufficientStock()) for editors that need to
    // preview available stock before submitting. Additive only, no new business logic.
    Route::get('stock-ledger/balances', [StockLedgerController::class, 'balances'])->middleware('permission:inventory.stock_ledger.view|reports.inventory_movement.view');
    // Inventory module (Phase 2G): full ledger listing + a current-balance-per-item/warehouse report.
    // Both read-only, both additive, both reuse StockLedgerService — no new business logic.
    Route::get('stock-ledger', [StockLedgerController::class, 'list'])->middleware('permission:inventory.stock_ledger.view|reports.inventory_movement.view');
    Route::get('stock-ledger/balances/report', [StockLedgerController::class, 'balancesReport'])->middleware('permission:inventory.stock_balance.view|reports.inventory_balance.view');
    Route::post('stock-in', [StockInController::class, 'store'])->middleware('permission:inventory.stock_ledger.create');

    // Document Engine — shared by every future transactional module.
    $withPagePermissions(Route::apiResource('naming-series', NamingSeriesController::class), 'administration.naming_series');
    Route::get('attachments', [DocumentAttachmentController::class, 'index'])->middleware('permission:system.document_attachment.view');
    Route::post('attachments', [DocumentAttachmentController::class, 'store'])->middleware('permission:system.document_attachment.create');
    Route::delete('attachments/{documentAttachment}', [DocumentAttachmentController::class, 'destroy'])->middleware('permission:system.document_attachment.delete');
    Route::get('attachments/{documentAttachment}/download', [DocumentAttachmentController::class, 'download'])->middleware('permission:system.document_attachment.view');
    Route::get('document-timeline', [DocumentTimelineController::class, 'index'])->middleware('permission:system.document_timeline.view');

    // Purchase Workflow (Sprint 4): Supplier -> PO -> Goods Receipt -> Stock Ledger(+) -> Accounts Payable.
    $withPagePermissions(Route::apiResource('purchase-orders', PurchaseOrderController::class), 'purchase.orders', 'reports.purchase.view');
    Route::post('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:purchase.orders.update');
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchase.orders.update');
    // Approval Workflow (Sprint 24B) — requesting approval is an action on the document itself,
    // gated by the document's own existing update permission, not a new one. See docs/APPROVAL_WORKFLOW_DESIGN.md §4.
    Route::post('purchase-orders/{purchaseOrder}/request-approval', [PurchaseOrderController::class, 'requestApproval'])->middleware('permission:purchase.orders.update');

    $withPagePermissions(Route::apiResource('goods-receipts', GoodsReceiptController::class), 'purchase.goods_receipts', 'reports.goods_receipts.view');
    Route::post('goods-receipts/{goodsReceipt}/submit', [GoodsReceiptController::class, 'submit'])->middleware('permission:purchase.goods_receipts.update');

    Route::get('accounts-payables', [AccountsPayableController::class, 'index'])->middleware('permission:finance.accounts_payable.view');
    Route::get('accounts-payables/{accountsPayable}', [AccountsPayableController::class, 'show'])->middleware('permission:finance.accounts_payable.view');

    // Sales Workflow (Sprint 5): Customer -> SO -> Delivery -> Stock Ledger(-).
    // Delivery Report additionally reads Sales Orders client-side (to resolve each delivery's
    // sales_order_id -> document number), so this view gate must also accept reports.deliveries.view.
    $withPagePermissions(Route::apiResource('sales-orders', SalesOrderController::class), 'sales.orders', 'reports.sales.view|reports.deliveries.view');
    Route::post('sales-orders/{salesOrder}/submit', [SalesOrderController::class, 'submit'])->middleware('permission:sales.orders.update');
    Route::post('sales-orders/{salesOrder}/cancel', [SalesOrderController::class, 'cancel'])->middleware('permission:sales.orders.update');
    Route::post('sales-orders/{salesOrder}/request-approval', [SalesOrderController::class, 'requestApproval'])->middleware('permission:sales.orders.update');

    $withPagePermissions(Route::apiResource('deliveries', DeliveryController::class), 'sales.deliveries', 'reports.deliveries.view');
    Route::post('deliveries/{delivery}/submit', [DeliveryController::class, 'submit'])->middleware('permission:sales.deliveries.update');

    // Invoice Workflow (Sprint 10): Delivery -> Invoice -> Accounts Receivable -> Receipt Entry.
    $withPagePermissions(Route::apiResource('invoices', InvoiceController::class), 'sales.invoices');
    Route::post('invoices/{invoice}/submit', [InvoiceController::class, 'submit'])->middleware('permission:sales.invoices.update');
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->middleware('permission:sales.invoices.update');

    // Credit Note (Sprint 13B): the only accounting-correction path for a posted Invoice —
    // Invoice::cancel() deliberately never touches the ledger, see InvoiceService::cancel().
    $withPagePermissions(Route::apiResource('credit-notes', CreditNoteController::class), 'sales.credit_notes');
    Route::post('credit-notes/{creditNote}/submit', [CreditNoteController::class, 'submit'])->middleware('permission:sales.credit_notes.update');
    Route::post('credit-notes/{creditNote}/reverse', [CreditNoteController::class, 'reverse'])->middleware('permission:sales.credit_notes.update');

    // Debit Note (Sprint 14B): the counterpart to Credit Note — increases a
    // customer's receivable after a posted Invoice. See docs/DEBIT_NOTE_DESIGN.md.
    $withPagePermissions(Route::apiResource('debit-notes', DebitNoteController::class), 'sales.debit_notes');
    Route::post('debit-notes/{debitNote}/submit', [DebitNoteController::class, 'submit'])->middleware('permission:sales.debit_notes.update');
    Route::post('debit-notes/{debitNote}/reverse', [DebitNoteController::class, 'reverse'])->middleware('permission:sales.debit_notes.update');

    Route::get('accounts-receivables', [AccountsReceivableController::class, 'index'])->middleware('permission:finance.accounts_receivable.view');
    Route::get('accounts-receivables/{accountsReceivable}', [AccountsReceivableController::class, 'show'])->middleware('permission:finance.accounts_receivable.view');

    // Inventory Module (Phase 2G): physical count reconciliation -> Stock Ledger(+/-). No cancel route,
    // deliberately — see StockAdjustment::cancel(). No Report counterpart exists, no OR needed.
    $withPagePermissions(Route::apiResource('stock-adjustments', StockAdjustmentController::class), 'inventory.adjustments');
    Route::post('stock-adjustments/{stockAdjustment}/submit', [StockAdjustmentController::class, 'submit'])->middleware('permission:inventory.adjustments.update');

    // Financial Settlement (Sprint 6): AP -> Payment Entry -> paid_amount/status; AR -> Receipt Entry -> paid_amount/status.
    $withPagePermissions(Route::apiResource('payment-entries', PaymentEntryController::class), 'finance.outgoing_payment');
    Route::post('payment-entries/{paymentEntry}/submit', [PaymentEntryController::class, 'submit'])->middleware('permission:finance.outgoing_payment.update');

    $withPagePermissions(Route::apiResource('receipt-entries', ReceiptEntryController::class), 'finance.incoming_payment');
    Route::post('receipt-entries/{receiptEntry}/submit', [ReceiptEntryController::class, 'submit'])->middleware('permission:finance.incoming_payment.update');

    // Payment Allocation (Sprint 12): applies an already-received Receipt Entry to one or more
    // outstanding Invoices' receivables — a separate step from receiving the money itself.
    Route::post('receipt-entries/{receiptEntry}/allocate', [PaymentAllocationController::class, 'store'])->middleware('permission:finance.payment_allocation.create');
    Route::post('payment-allocations/{paymentAllocation}/reverse', [PaymentAllocationController::class, 'reverse'])->middleware('permission:finance.payment_allocation.update');

    // Accounting Engine (Sprint 11): Invoice/Receipt Entry -> Accounting Service -> Journal Entry -> General Ledger.
    $withPagePermissions(Route::apiResource('chart-of-accounts', ChartOfAccountController::class), 'master.chart_of_accounts');
    $withPagePermissions(Route::apiResource('journal-entries', JournalEntryController::class), 'accounting.journal_entries');
    Route::post('journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])->middleware('permission:accounting.journal_entries.update');
    Route::post('journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])->middleware('permission:accounting.journal_entries.update');
    Route::post('journal-entries/{journalEntry}/request-approval', [JournalEntryController::class, 'requestApproval'])->middleware('permission:accounting.journal_entries.update');

    // Approval Workflow (Sprint 24B) — one shared engine for Sales Order, Purchase Order, and
    // manual Journal Entry (docs/APPROVAL_WORKFLOW_DESIGN.md §1). approve()/reject() serve all
    // three through this single route; the {page}.approve permission check happens inside
    // ApprovalService (document-dependent, not expressible as one static route middleware).
    Route::get('approval-flows', [ApprovalController::class, 'index']);
    Route::post('approval-flows/{approvalFlow}/approve', [ApprovalController::class, 'approve']);
    Route::post('approval-flows/{approvalFlow}/reject', [ApprovalController::class, 'reject']);

    // General Ledger (Sprint 15B): a read model derived entirely from journal_entries/
    // journal_entry_lines above — no new accounting table, never writes. See docs/GENERAL_LEDGER_DESIGN.md.
    // Its own distinct Accounting Reports page — no longer shares journal_entries' permission.
    Route::get('general-ledger/accounts', [GeneralLedgerController::class, 'accounts'])->middleware('permission:accounting.general_ledger.view');
    Route::get('general-ledger/accounts/{chartOfAccount}', [GeneralLedgerController::class, 'ledger'])->middleware('permission:accounting.general_ledger.view');

    // Trial Balance (Sprint 16A): a presentation layer over GeneralLedgerService::listAccounts()
    // — no new balance calculation, no new accounting table. See docs/TRIAL_BALANCE_DESIGN.md.
    // Own distinct page/permission, same reasoning as General Ledger above.
    Route::get('trial-balance', [TrialBalanceController::class, 'summary'])->middleware('permission:accounting.trial_balance.view');

    // Profit & Loss (Sprint 17B): a presentation layer over GeneralLedgerService::listAccounts(),
    // classified via the separate report_account_mappings table — chart_of_accounts gains no new
    // columns. See docs/PROFIT_LOSS_DESIGN.md. Own distinct page/permission.
    Route::get('profit-loss', [ProfitLossController::class, 'summary'])->middleware('permission:accounting.profit_loss.view');

    // Balance Sheet (Sprint 18B): a presentation layer over GeneralLedgerService::listAccounts()
    // (cumulative ending_balance, not period movement) and ProfitLossService::summarize() (Current
    // Year Profit / Retained Earnings) — no new balance calculation. See docs/BALANCE_SHEET_DESIGN.md.
    // Own distinct page/permission.
    Route::get('balance-sheet', [BalanceSheetController::class, 'summary'])->middleware('permission:accounting.balance_sheet.view');

    // Cash Flow (Sprint 19B): Indirect Method — reuses ProfitLossService::summarize() (Net Profit
    // for the Period) and GeneralLedgerService::listAccounts() (opening/ending balance per account
    // in one call) — no new balance calculation. See docs/CASH_FLOW_DESIGN.md. Own distinct page/permission.
    Route::get('cash-flow', [CashFlowController::class, 'summary'])->middleware('permission:accounting.cash_flow.view');

    // Period Closing (Sprint 20B): a locking mechanism only — never posts a journal entry, never
    // modifies any report's calculation. Enforced inside JournalEntryService via PeriodLockService,
    // the single check point for the whole app. See docs/PERIOD_CLOSING_DESIGN.md. Own distinct page/permission.
    Route::get('fiscal-years', [PeriodController::class, 'fiscalYears'])->middleware('permission:accounting.period_closing.view');
    Route::post('fiscal-years', [PeriodController::class, 'storeFiscalYear'])->middleware('permission:accounting.period_closing.create');
    Route::get('accounting-periods', [PeriodController::class, 'periods'])->middleware('permission:accounting.period_closing.view');
    Route::get('accounting-periods/{accountingPeriod}/validate', [PeriodController::class, 'validatePeriod'])->middleware('permission:accounting.period_closing.view');
    Route::post('accounting-periods/{accountingPeriod}/close', [PeriodController::class, 'close'])->middleware('permission:accounting.period_closing.update');
    // reopen() additionally requires hasRole('Admin') inside PeriodManagementService — this
    // middleware is an added first gate, the existing Admin-only check remains untouched.
    Route::post('accounting-periods/{accountingPeriod}/reopen', [PeriodController::class, 'reopen'])->middleware('permission:accounting.period_closing.update');

    // Dashboard (Sprint 7) — read-only aggregates over existing entities, no new tables.
    // Each widget reuses whatever page's permission already governs the underlying data,
    // same convention as before the rename — just the renamed page-scoped permission strings.
    Route::prefix('dashboard')->group(function () {
        Route::get('stock-summary', [DashboardController::class, 'stockSummary'])->middleware('permission:master.items.view');
        Route::get('purchases-today', [DashboardController::class, 'purchasesToday'])->middleware('permission:purchase.orders.view');
        Route::get('sales-today', [DashboardController::class, 'salesToday'])->middleware('permission:sales.orders.view');
        Route::get('accounts-payable-outstanding', [DashboardController::class, 'accountsPayableOutstanding'])->middleware('permission:finance.accounts_payable.view');
        Route::get('accounts-receivable-outstanding', [DashboardController::class, 'accountsReceivableOutstanding'])->middleware('permission:finance.accounts_receivable.view');
        Route::get('low-stock-items', [DashboardController::class, 'lowStockItems'])->middleware('permission:master.items.view');
        Route::get('recent-transactions', [DashboardController::class, 'recentTransactions'])->middleware('permission:dashboard.view');

        // Sprint 23B (docs/DASHBOARD_DESIGN.md §4) — each new widget endpoint gated
        // behind the same existing page permission that already gates that
        // page's own section, not a new generic "dashboard" permission.
        Route::get('financial-summary', [DashboardController::class, 'financialSummary'])->middleware('permission:accounting.journal_entries.view');
        Route::get('sales-trend', [DashboardController::class, 'salesTrend'])->middleware('permission:sales.orders.view');
        Route::get('purchase-trend', [DashboardController::class, 'purchaseTrend'])->middleware('permission:purchase.orders.view');
        Route::get('inventory-movement', [DashboardController::class, 'inventoryMovement'])->middleware('permission:master.items.view');
        Route::get('pending-tasks', [DashboardController::class, 'pendingTasks'])->middleware('permission:dashboard.view');
    });
});
